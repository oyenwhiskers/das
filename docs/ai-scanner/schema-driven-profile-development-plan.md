# DAS schema-driven profile extraction: development plan

**Prepared:** 16 October 2026  
**Status:** Phase 4 review and correction delivered. Merge and end-to-end phases remain gated one at a time.

## 1. Outcome and boundaries

An upload continues through the existing private document scanner. Gemini receives the uploaded source and definitions for every supported editable Customer Profile business field. Laravel validates a sparse response and creates one independent, reviewable Extracted Profile for each logical primary individual. Staff corrects each profile, selects an existing individual, previews a merge, and explicitly confirms it. Extraction and review never write to customer records.

Keep the existing `DocumentFindingsV1` observations as the original document evidence. Information outside Customer Profile stays there. Do not use the separate BIMB catalog, document templates, fixed coordinates, inferred customer IDs, new customer fields, or paid Gemini calls during automated tests. Preserve the current upload, basic read, batching, choice verification, findings display, and retry behavior unless a phase explicitly changes one of them.

The installed baseline is Laravel 13.36.0, Livewire 3.8.10, Volt 1.11.2, Boost 2.10.3, and PHPUnit 12.5.39. Follow the local Boost conventions and nearby PHPUnit/Livewire tests. No new dependency is planned.

### Phase 0 field boundary

The audit found **138 distinct editable business fields**. A repeatable row's attribute counts once; the three compliance declaration kinds count separately. This boundary excludes IDs, timestamps, hashes, normalized copies, staff provenance/confirmation controls, and duplicate form inputs that write the same value. The schema coverage test in Phase 2 must lock the final inventory and fail on drift.

| Area | Fields | Existing definition locations |
| --- | ---: | --- |
| Personal, identity, contacts, addresses | 34 | `Customers/Show`, `Customers/Create`, models, enums, Blade labels |
| Relationships and related people | 22 | `Customers/FamilyRelationships`, `CustomerRelationship`, `RelatedPerson` |
| Employment | 22 | `Customers/WorkFinance`, `CustomerEmployment` |
| Bank accounts | 9 | `Customers/BankAccounts`, `CustomerBankAccount` |
| Tax and FATCA | 12 | `Customers/TaxResidency`, tax models |
| Financial declaration | 9 | `Customers/Financial`, `CustomerFinancialDeclaration` |
| Investment and brokerage | 11 | `Customers/Investment`, investment/brokerage models |
| Compliance | 19 | `Customers/Compliance`, compliance/PEP models |

The count includes conditionally editable values such as primary/active designations and residency or association end dates. The schema must state those conditions. It must not interpret a source document's silence as an instruction to change a designation or end a record.

## 2. Data and interface contracts

### Customer field schema

Use **logical paths grounded in current customer relationships and stored attributes**, not Livewire form property names. Examples are `customer.full_legal_name`, `primary_identification.number`, `contacts[mobile].value`, `addresses[residential].line_1`, `bank_accounts[].account_number`, and `relationships[].related_person.full_legal_name`. A bracketed discriminator is part of the path; `[]` denotes a repeatable row identified by a separate row reference in an extraction. One logical primary ID field maps to the existing primary identification record and denormalized customer columns through the existing write behavior, never through two AI fields.

Each emitted descriptor has a stable path, module, label, substantive description, type, allowed values where relevant, entity role, cardinality, parent path, validation constraints, conditional edit rule, and unprovided semantics. Descriptions must distinguish applicant from related person, personal from employer contact/address, residential from mailing address, monthly amounts from annual brackets, customer choices from printed options, and compliance risk from investment tolerance. Mark metadata such as staff-entered source and confirmation status as outside the AI field set.

There is no current central field registry. Phase 2 should make a **shared Customer Profile field definition source** from the existing form rules, option arrays/enums, relationship paths, and labels; customer forms should consume the moved definitions they use. Add semantic descriptions in that same source. Retain save-time cross-field and database checks in their current services/components until Phase 5 extracts shared write operations. A test must compare emitted descriptors with the supported editable paths, reject missing descriptions, duplicate paths, unsupported options, and accidental technical columns. Database introspection alone is insufficient.

Serialize descriptors in stable path order with fixed key order. Use a schema format version for contract changes and a SHA-256 hash of the canonical JSON for field-definition changes. Save the full canonical schema snapshot and hash with each scan or in a hash-keyed private snapshot table so historical extractions remain interpretable after definitions change. The scanner must fail visibly if a descriptor is invalid; it must not silently drop a field to fit a model limit.

### Gemini request and sparse response

Keep `GeminiDocumentReader` and its existing Interactions API request (`store=false`, inline private source, JSON response format). Run profile extraction as a **separate queued pass after immutable `DocumentFindingsV1` is stored**, so the existing scanner contract and its stable final finding IDs remain intact. Use one profile request per existing PDF page batch, or one for an image/one-page PDF. Send the assigned source bytes, the **complete** field schema, the validated logical record plan, and the final findings/evidence IDs relevant to those pages. A batch prompt carries the original absolute page range. The instructions require evidence, correct person ownership, explicit selection marks, sparse supported values, and uncertainty rather than guesses. Document text is untrusted instructions. Reconcile responses by server record ID; preserve conflicting cross-batch values for review instead of choosing one.

Use this server-validated response shape (the example paths are real logical target paths, not new customer columns):

```json
{
  "contract_version": "1.0",
  "schema_hash": "<supplied hash>",
  "records": [
    {
      "record_ref": "r1",
      "values": [
        {
          "field_id": "customer.full_legal_name",
          "entity_ref": "applicant-1",
          "row_ref": null,
          "state": "provided",
          "value": "Example Person",
          "evidence_ids": ["field-1"]
        }
      ],
      "warnings": []
    }
  ],
  "unresolved": []
}
```

`record_ref` refers only to the server-validated scan plan; it is never a DAS customer ID. `row_ref` identifies one provisional repeatable child row within that record. `entity_ref` refers to an entity in original findings. `state` is `provided`, `ambiguous`, or `unreadable`; omitted fields are unprovided. Explicit `false`, `0`, and selected `no` are provided values. `unresolved` retains observations that cannot safely be assigned to a primary record or supported field. Any raw observation remains in immutable `DocumentFindingsV1`, even when the projection rejects it.

Laravel checks the contract version/hash, known field IDs, role and parent compatibility, type and enum values, row cardinality, provisional references, duplicate/conflicting values, source pages, and evidence IDs against the scan's original findings and record ownership. Never coerce an invalid value into another field. Invalid fields become review warnings without corrupting valid records; a structurally invalid record is rejected independently. A batch must not contaminate another primary record.

### Extracted Profile storage

Add `extracted_profiles` with a unique `(document_scan_id, record_ref)`, server ID, source pages, field schema hash/version, prompt/response contract version, immutable original projection JSON, current reviewed projection JSON, review status, reviewer and timestamps. It starts without a customer foreign key. Keep its logical customer modules/child rows in JSON rather than duplicating every customer table. Each value retains state, evidence references, record/entity/row ownership, and warnings. Original findings remain in `document_findings`; do not edit them.

Add append-only `extracted_profile_review_events` for field/ownership corrections with actor, time, previous and new reviewed value, and reason. The reviewed projection is updated atomically with the event and has a revision number. Ownership correction removes or reassigns dependent proposed values in the same transaction; unresolved values stay outside any mergeable profile until reviewed.

Phase 5 adds `extracted_profile_merges` with a unique submission key, source profile, selected customer, reviewer revision, strategy, timestamp, and JSON field-level changes containing old/new values and evidence references. Keep existing `CustomerActivityLogger` for the customer-level summary. Sensitive numbers remain private, masked in routine UI and omitted from logs; the profile/evidence access policy follows active-staff authorization.

## 3. Phase-by-phase delivery

Every phase starts by searching all callers of touched methods and checking its models, rules, views, fixtures, configuration, authorization, and migrations. Run focused tests, then the relevant full suite and Pint when PHP changes warrant it. Report actual results and stop for approval before the next phase.

### Phase 1 — Logical record segmentation

**Change:** Refine `DocumentRecordOwnership`, the initial `read()` plan, batch prompts, and batch reconciliation only where tests expose record isolation gaps. Preserve server-assigned `r1`/`r2` references and source page/region evidence. Carry ambiguous ownership as null plus an issue. Do not join people or repeated sections by name/heading alone; reconcile cross-batch continuation only with record ID and supporting context/evidence. Keep the existing single-record path working and preserve original findings.

**Tests:** One applicant over 14 pages with spouse/witness; nine applicants in one upload; two applicants on one page; one applicant across batches; repeated names/headings; missing or conflicting ownership; out-of-region evidence; malformed plan; unrelated document with no identifiable applicant. Existing segmentation and scanner regression tests must continue to pass.

**Exit:** Every independently identified primary record has distinct validated ownership, and uncertain content cannot enter an arbitrary profile. No Extracted Profile table or customer write is needed in this phase.

### Phase 2 — Complete dynamic Customer Profile schema

**Change:** Establish shared field definitions used by the customer forms and schema generator. Include all 138 audited business fields, nested/repeatable structure, conditionally editable fields, meaningful descriptions, current options/enums, and validation. Generate deterministic canonical JSON and hash; persist a versioned snapshot for scan use. Do not alter customer columns or meanings.

**Tests:** Exact field coverage and no technical attributes; every descriptor has a meaningful description; enums/options and conditional rules agree with save forms; stable serialization/hash; hash changes when a definition changes; historical snapshot remains readable. Check the request size and model response-format limits without making a paid call; if full coverage does not fit, report measurements and propose a coverage-preserving strategy before changing runtime behavior.

**Exit:** The complete schema is reproducible from DAS definitions and passes the coverage gate.

### Phase 3 — Schema-driven extraction and storage

**Change:** After `StoreDocumentFindings` succeeds, queue the separate profile pass described above. Add the versioned profile prompt and sparse response schema to `GeminiDocumentReader`. Reuse the existing private source/page batches, model configuration, queue and usage recorder. Validate and project values by logical record, then reconcile page-batch responses. Store one independent Extracted Profile per valid primary record, plus unresolved observations/warnings; do not create customer links. Use a separate profile-batch cache keyed by source scan, page range, model, prompt version, response contract and field-schema hash; leave the existing V1 batch cache alone. Make retries idempotent by unique scan/record key, and keep successfully validated sibling records when one projection fails.

**Tests:** Fake Gemini HTTP only: one and nine profiles, cross-batch applicant, related-person ownership, checkbox selected/unselected, false/zero, missing versus ambiguous, unsupported field, enum/type error, wrong page/evidence ID, duplicate value, retry and partial failure. Assert no customer tables change and `DocumentFindingsV1` stays intact.

**Exit:** A readable scan can produce independently stored, unlinked, structurally valid profiles using the full field context.

### Phase 4 — Review and correction

**Change:** Extend the document detail page with a profile list and compact module-grouped review page using existing Blade/Livewire patterns. Show populated, unprovided and unresolved states; original value, reviewed value, source page/evidence, warnings and per-profile status. Allow field and ownership corrections with optimistic revision checks. Preserve immutable original projection and append review events. Mark a profile reviewed only after unresolved mergeable values are addressed or explicitly excluded.

**Tests:** One and many profiles, field correction, ownership correction and dependent-value reconciliation, evidence retention, conflicting/ambiguous values, stale reviewer revision, unauthorized or inactive staff, and no customer mutation.

**Exit:** Staff can independently review every profile and trace each correction to its original extraction.

### Phase 5 — Manual match and merge

**Change:** Add an explicit `Match to Customer` action on one reviewed profile. Use the current customer search and `CustomerPolicy`; never let Gemini select a customer. Compare normalized primary identity where present and block a strong ID mismatch. Name alone does not establish identity. Build a field-by-field preview with additions, replacements, skips and conflicts. Map repeatable child rows to a selected existing row or a new row using existing identity/uniqueness rules: bank account identity hash, related-person identification or explicit staff selection, current tax jurisdiction, and current declaration rules. Ambiguous child matches require staff resolution.

**Fill Only Empty:** Default. A destination is empty only when its business value is null or absent; `false`, `0`, explicit `no`, and a populated collection are not empty. Apply reviewed provided values only. Preserve other fields and child rows.

**Overwrite:** Apply only reviewed provided values approved in preview. Do not clear absent fields or replace an entire child collection. Use existing customer validation, duplicate checks, normalization, derived columns, history rules and activity logging by extracting the necessary shared write operations from the existing save paths rather than bypassing them with generic model updates.

**Commit:** Reauthorize; lock the target customer and affected children; compare reviewer revision and current values with the preview; recalculate or reject a stale preview; revalidate business rules; apply inside one transaction; write field-level provenance and a unique submission key. A second submission with the same key returns the prior result without a second write. Applying one batch profile cannot apply its siblings.

**Replace Completely:** **Do not implement clearing yet.** Present a separate policy for business-owner approval specifying whole-profile versus selected-module scope, absent fields, child rows, allowed/prohibited deletion, confirmation and recovery. After approval, add deletion preview, explicit destructive confirmation, transaction and tests. If no approval is given, Phase 5 delivers Fill Only Empty and Overwrite and reports this strategy as blocked.

**Tests:** Empty/false/zero semantics; absent source fields; overwrite; same-profile replay; child deduplication; wrong customer; stale preview and concurrent edit; transaction rollback; authorization; audit/evidence; independent batch matching; destructive policy tests only after approval.

**Exit:** Different reviewed documents can progressively enrich one selected individual without unintended data loss.

### Phase 6 — End-to-end verification

**Change:** Add synthetic fixture scenarios for one applicant with related people, nine independent applicants, one applicant across batches, selected versus blank checkbox options, sequential birth-certificate/payslip/bank-statement enrichment, and repeated merge. Recheck schema coverage, privacy, review history, queue recovery, transaction safety and usage data. Run the relevant complete suite and formatting checks.

**Measurement:** Record input/output/cached/thought tokens and estimated cost where the API supplies them, without logging document contents. Mocked tests establish code behavior, not live Gemini accuracy. A bounded live-model accuracy study needs separate approval before paid calls.

**Exit:** All synthetic flows and regression tests pass, and limitations are reported with measured evidence.

## 4. Compatibility, rollout, and decision gates

- **Existing scans:** Leave their original findings readable. Do not fabricate profiles for them. A staff-initiated re-extraction may use the new prompt and the schema snapshot current at that time; record the version/hash. Existing completed scans and files are not overwritten.
- **Existing customers:** No migration changes their values. Merge updates reuse their existing validation, uniqueness, authorization, derived columns and history behavior. A preview never writes customer data.
- **Privacy:** Keep originals, page batches, findings, projections and review history behind active-staff authorization and private storage. Mask account and identity numbers in lists and audit summaries. Do not place raw document content in application logs or model usage records.
- **Operations:** Retain the queue/retry paths. Track prompt/schema versions in cache keys. Verify configured worker timeout and queue visibility in the target deployment before enabling profile extraction. Measure schema request size, latency and token usage; do not remove fields based on an assumed document type.
- **Approval gates:** Stop after each phase and report changed files, compatibility, migrations, tests, Gemini usage and risks. The business owner must separately approve Replace Completely semantics. Paid Gemini calls and model/concurrency changes also require approval under the original request.

**Known limit:** The 138-field count and logical path design are an audited baseline, not a generated artifact yet. Phase 2's coverage test is the control that makes future field changes visible. The current scanner validates structural evidence references; it cannot prove the model read a source value correctly without human review or a separately approved live evaluation.

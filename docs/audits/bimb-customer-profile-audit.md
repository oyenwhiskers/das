# BIMB Securities individual application — DAS Customer Profile coverage audit

**Audit date:** 16 October 2026. **Source:** supplied field inventory for the February 2025 form. The PDF was not needed to resolve any field ambiguity. **Scope:** repository migrations, models, relationships, Livewire validation and customer screens only. No database rows or running browser session were inspected. This is a design and UI code audit, not a claim about completeness of existing customer records.

**Snapshot notice:** The matrix and percentages below are the pre-remediation audit baseline. The subsequent implementation added the 9 Missing and 12 Partial items on 16 October 2026. The historical findings are retained; use the current customer screens and migrations for the implemented state. Existing legacy values still require human review where their original meaning was ambiguous.

## A. Executive summary

**DAS Customer Profile is not sufficient to reproduce all reusable information on the BIMB form.** The schema and screens cover most identity, address, employment, banking, tax residency, investment and PEP facts. The entire financial declaration block is absent. Several existing fields lose the form's exact meaning, particularly an explicit FATCA classification, named social platforms, property `Other` text, and separately structured related-person employer address parts.

Coverage is **complete rows ÷ all M rows** in the matrix below; a grouped row counts once only when it is one meaningful source field or selection group. Extra DAS options do not reduce coverage where BIMB choices remain losslessly selectable. Counts must be read as field coverage, not populated-customer completeness.

| Complete | Partial | Missing | Unverified | M total | Fully supported |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 72 | 12 | 9 | 0 | 93 | 77.4% |

Critical gaps: exact monthly income; four separate declared financial brackets; portfolio ownership; multi-select source of wealth and its `Other` detail; explicit other-broker `No`; and explicit FATCA US/non-US selection. The first eight are wholly absent. The last two have nearby data but cannot preserve the original declaration. The portfolio source inventory flags the fifth printed range as `RM500,001–RM1,000,00`; the apparent typo is preserved here and must be confirmed against the form owner before any correction.

### Evidence key

Each matrix row names its model/column and a UI evidence key. These links identify the implementation and its validation; the relevant migration defines the physical type. [Customers migration](../../database/migrations/2026_10_13_142147_create_customers_table.php), [identification migration](../../database/migrations/2026_10_13_142148_create_customer_identifications_table.php), [contact migration](../../database/migrations/2026_10_13_142149_create_customer_contacts_table.php), [address migration](../../database/migrations/2026_10_13_142150_create_customer_addresses_table.php), [relationship migrations](../../database/migrations/2026_10_15_110018_create_related_people_table.php) and [link table](../../database/migrations/2026_10_15_110019_create_customer_relationships_table.php), [employment migration](../../database/migrations/2026_10_15_114912_create_customer_employments_table.php), [employment status migration](../../database/migrations/2026_10_15_114913_add_employment_status_to_customers_table.php), [bank migration](../../database/migrations/2026_10_15_194128_create_customer_bank_accounts_table.php), [tax migration](../../database/migrations/2026_10_15_203826_create_customer_tax_information_tables.php), [investment migrations](../../database/migrations/2026_10_15_210838_create_customer_investment_profiles_table.php) and [brokerage table](../../database/migrations/2026_10_15_210839_create_customer_brokerage_accounts_table.php), [compliance migration](../../database/migrations/2026_10_15_211748_create_customer_compliance_tables.php).

**UI keys:** P = [personal/contact/address component](../../app/Livewire/Customers/Show.php) and [view](../../resources/views/livewire/customers/show.blade.php); R = [relationships component](../../app/Livewire/Customers/FamilyRelationships.php) and [view](../../resources/views/livewire/customers/family-relationships.blade.php); W = [employment component](../../app/Livewire/Customers/WorkFinance.php) and [view](../../resources/views/livewire/customers/work-finance.blade.php); B = [bank component](../../app/Livewire/Customers/BankAccounts.php) and [view](../../resources/views/livewire/customers/bank-accounts.blade.php); T = [tax component](../../app/Livewire/Customers/TaxResidency.php) and [view](../../resources/views/livewire/customers/tax-residency.blade.php); I = [investment component](../../app/Livewire/Customers/Investment.php) and [view](../../resources/views/livewire/customers/investment.blade.php); C = [compliance component](../../app/Livewire/Customers/Compliance.php) and [view](../../resources/views/livewire/customers/compliance.blade.php). [Customer model](../../app/Models/Customer.php) confirms relationships. `—` means no suitable model, column, validation or UI was found in these scoped files.

## B. Field coverage matrix

Status applies only to **M** rows. `UI` names the edit screen key above; `None` means absent. The explanation states any material semantic limit. `Keep` means no change recommended. `Add` and `refine` are recommendations only; nothing has been implemented.

| Page | Section | Exact BIMB field | Field type / original options | DAS module | Existing model / column | UI | Coverage status | Gap / explanation | Recommended correction |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Tax | Malaysian TIN | Text | Tax | `CustomerTaxIdentification.malaysian_tin`, encrypted | T | COMPLETE | Editable and separately stored. | Keep |
| 1 | FATCA | US Person for Tax Purposes | Yes/No/Not Declared | Tax | `CustomerFatcaDeclaration.us_person_for_tax`, nullable bool | T | COMPLETE | Tri-state input, dated history. | Keep |
| 1 | FATCA | US Permanent Resident Card Holder | Yes/No/Not Declared | Tax | `.us_green_card_holder`, nullable bool | T | COMPLETE | Independent answer. | Keep |
| 1 | FATCA | US Resident | Yes/No/Not Declared | Tax | `.us_resident`, nullable bool | T | COMPLETE | Independent answer. | Keep |
| 1 | FATCA | Non US Person / US Person | Explicit single selection | Tax | `classification()` derives value; no selected-value column | None | PARTIAL | Derivation cannot prove which box applicant selected, or preserve a conflicting selection. | Store selected classification separately from derived result. |
| 1 | FATCA | Declaration date/history | Date, repeatable declarations | Tax | `.declared_on`, `current_customer_key`, prior rows | T | COMPLETE | Replacements retain dated prior rows. | Keep |
| 1 | Foreign tax | Country/Jurisdiction of Tax Residence | Repeatable text | Tax | `CustomerTaxResidency.jurisdiction`; many records | T | COMPLETE | Multiple jurisdictions and history. | Keep |
| 1 | Foreign tax | Foreign TIN | Text per jurisdiction | Tax | `.foreign_tin`, encrypted | T | COMPLETE | Linked to its jurisdiction. | Keep |
| 1 | Foreign tax | Missing TIN Reason | A/B/C, single per jurisdiction | Tax | `.missing_tin_reason` char(1) | T | COMPLETE | Exact codes and mutual exclusion with TIN. | Keep |
| 1 | Foreign tax | Explanation for Reason B | Conditional text | Tax | `.missing_tin_explanation` | T | COMPLETE | Required when B, cleared otherwise. | Keep |
| 2 | Identity | Salutation | Text | Personal | `Customer.salutation` string | P | COMPLETE | Editable; gender default can be overridden. | Keep |
| 2 | Identity | Full Name | Text | Personal | `.full_legal_name` string | P | COMPLETE | Required on edit. | Keep |
| 2 | Identity | New NRIC/Passport/Registration Number | ID type + text | Personal | `.primary_identification_type/number`; `CustomerIdentification.type/number` | P | COMPLETE | NRIC, passport, other type; registration number can use Other. | Keep |
| 2 | Identity | Old NRIC | Text | Personal | `CustomerIdentification.old_nric` | P | COMPLETE | Stored on primary identity. | Keep |
| 2 | Identity | Date of Birth | Date | Personal | `Customer.date_of_birth` | P | COMPLETE | Editable. | Keep |
| 2 | Identity | Marital Status | Single: Single/Married/Divorced | Personal | `.marital_status` string | P | COMPLETE | All source choices, plus Widowed. | Keep |
| 2 | Identity | Nationality | Malaysian/Others specify | Personal | `.nationality` text | P | COMPLETE | Free text can losslessly hold Malaysia or specified nationality. | Keep |
| 2 | Identity | Country of Residence | Country text | Personal | `.country_of_residence` | P | COMPLETE | Independent from nationality. | Keep |
| 2 | Identity | Gender | Single: Male/Female | Personal | `.gender` | P | COMPLETE | Both choices, plus Other. | Keep |
| 2 | Identity | Race | Malay/Chinese/Indian/Others | Personal | `.race` text | P | COMPLETE | Free text can preserve each named choice or the specified other race. | Keep |
| 2 | Residential | Address Lines | Text lines | Address | `CustomerAddress.line_1/line_2`, residential type | P | COMPLETE | Separate residential row. | Keep |
| 2 | Residential | City | Text | Address | `.city`, residential row | P | COMPLETE | — | Keep |
| 2 | Residential | State | Text | Address | `.state`, residential row | P | COMPLETE | — | Keep |
| 2 | Residential | Postcode | Text | Address | `.postcode`, residential row | P | COMPLETE | Leading zero preserved. | Keep |
| 2 | Residential | Country | Text | Address | `.country`, residential row | P | COMPLETE | — | Keep |
| 2 | Residential | Property Status | Owned/Rented/Others specify | Address | `.occupancy_status`; `OccupancyStatus` enum | P | PARTIAL | Other is selectable, but no description field. | Add conditional Other text. |
| 2 | Mailing | Address Lines | Text lines | Address | `CustomerAddress.line_1/line_2`, mailing type | P | COMPLETE | Independent row or copy-from-residential. | Keep |
| 2 | Mailing | City | Text | Address | `.city`, mailing row | P | COMPLETE | — | Keep |
| 2 | Mailing | State | Text | Address | `.state`, mailing row | P | COMPLETE | — | Keep |
| 2 | Mailing | Postcode | Text | Address | `.postcode`, mailing row | P | COMPLETE | — | Keep |
| 2 | Mailing | Country | Text | Address | `.country`, mailing row | P | COMPLETE | — | Keep |
| 2 | Contact | Home Telephone | Telephone text | Contact | `CustomerContact.type=home/value` | P | COMPLETE | Separately edited. | Keep |
| 2 | Contact | Mobile Telephone | Telephone text | Contact | `type=mobile/value`; `Customer.primary_mobile` | P | COMPLETE | Separately edited. | Keep |
| 2 | Contact | Email | Email text | Contact | `type=email/value`; `Customer.primary_email` | P | COMPLETE | Separately edited. | Keep |
| 2 | Contact | Facebook/Instagram/Twitter Account | Platform-specific identities | Contact | One `type=social/value` contact | P | PARTIAL | One unlabeled input cannot identify or simultaneously store three distinct platform handles. | Add platform plus repeatable handle or three labeled values. |
| 2 | Family | Spouse / Next of Kin relationship type | Spouse; NOK specify relationship | Relationships | `CustomerRelationship.relationship/other_relationship/is_next_of_kin` | R | COMPLETE | Spouse and NOK flags coexist; Other detail is required. | Keep |
| 2 | Family | Full Name | Text per person | Relationships | `RelatedPerson.full_legal_name` or linked `Customer.full_legal_name` | R | COMPLETE | Identity belongs to related person/customer. | Keep |
| 2 | Family | NRIC/Passport/Registration Number | Type + text | Relationships | `RelatedPerson.identification_type/number` or linked customer ID | R | COMPLETE | Other ID type permits registration number. | Keep |
| 2 | Family | Date of Birth | Date | Relationships | `RelatedPerson.date_of_birth` or linked customer | R | COMPLETE | Optional for NOK, editable for unlinked person. | Keep |
| 2 | Family | Employer/Firm Name | Text | Relationships | `RelatedPerson.employer_name` | R | COMPLETE | Editable for unlinked person; linked customer's employment is separate. | Keep |
| 2 | Family | Employer Address | Text | Relationships | `RelatedPerson.employer_address` text | R | PARTIAL | One free text can hold the full postal wording but cannot separate its parts; linked customer's employment address is not edited here. | Use structured related-person employer address or linked employment reference. |
| 2 | Family | Employer City | Text | Relationships | Only `.employer_address` free text | R | PARTIAL | No dedicated city, so not reliably reusable. | Add city. |
| 2 | Family | Employer State | Text | Relationships | Only `.employer_address` free text | R | PARTIAL | No dedicated state. | Add state. |
| 2 | Family | Employer Postcode | Text | Relationships | Only `.employer_address` free text | R | PARTIAL | No dedicated postcode. | Add postcode string. |
| 2 | Family | Employer Country | Text | Relationships | Only `.employer_address` free text | R | PARTIAL | No dedicated country. | Add country. |
| 2 | Family | Occupation | Text | Relationships | `RelatedPerson.occupation` | R | COMPLETE | Editable. | Keep |
| 2 | Family | Home Telephone | Telephone text | Relationships | `RelatedPerson.home_telephone` or linked customer home contact | R | COMPLETE | — | Keep |
| 2 | Family | Mobile Telephone | Telephone text | Relationships | `RelatedPerson.mobile` or linked customer mobile | R | COMPLETE | NOK accepts either contact number. | Keep |
| 2 | Family | Email | Email text | Relationships | `RelatedPerson.email` or linked customer email | R | COMPLETE | — | Keep |
| 3 | Employment | Employment Status | Employed/Self-Employed/Retired/Others specify | Employment | `Customer.employment_status/_other` | W | COMPLETE | All source choices; extra Unemployed. Other detail required. | Keep |
| 3 | Employment | Occupation/Designation | Text | Employment | `CustomerEmployment.occupation` | W | COMPLETE | Repeatable record. | Keep |
| 3 | Employment | Employer/Firm Name | Text | Employment | `.employer_name` | W | COMPLETE | Employer-specific. | Keep |
| 3 | Employment | Employer General Telephone | Telephone text | Employment | `.general_telephone` | W | COMPLETE | Separate from direct line. | Keep |
| 3 | Employment | Company/Business Registration Number | String | Employment | `.registration_number` varchar(100) | W | COMPLETE | Preserves leading zeros and letters. | Keep |
| 3 | Employment | Industry | Text | Employment | `.industry` | W | COMPLETE | Independent. | Keep |
| 3 | Employment | Sub-Industry | Text | Employment | `.sub_industry` | W | COMPLETE | Independent. | Keep |
| 3 | Employment | Office/Business Address | Address lines | Employment | `.address_line_1/line_2` | W | COMPLETE | Employer row, not customer address. | Keep |
| 3 | Employment | City | Text | Employment | `.city` | W | COMPLETE | — | Keep |
| 3 | Employment | State | Text | Employment | `.state` | W | COMPLETE | — | Keep |
| 3 | Employment | Postcode | Text | Employment | `.postcode` string | W | COMPLETE | — | Keep |
| 3 | Employment | Country | Text | Employment | `.country` | W | COMPLETE | — | Keep |
| 3 | Employment | Office Direct Telephone (DID) | Telephone text | Employment | `.direct_telephone` | W | COMPLETE | Distinct from general. | Keep |
| 3 | Employment | Fax Number | Telephone text | Employment | `.fax` | W | COMPLETE | — | Keep |
| 3 | Employment | Employment Date | Date | Employment | `.start_date` | W | COMPLETE | Repeatable current and past jobs, start/end dates. | Keep |
| 3 | Employment | Retirement Date | Date | Employment | `Customer.retirement_date` | W | COMPLETE | Distinct from employment start/end. | Keep |
| 3 | Banking | Bank Name | Repeatable text | Banking | `CustomerBankAccount.bank_name` | B | COMPLETE | Multiple accounts supported. | Keep |
| 3 | Banking | Branch/Address | Text | Banking | `.branch_address` | B | COMPLETE | — | Keep |
| 3 | Banking | Account Type | Savings/Current/Others | Banking | `.account_type` string | B | COMPLETE | All three selectable. | Keep |
| 3 | Banking | Account Number | String | Banking | `.account_number` encrypted text | B | COMPLETE | Validation preserves leading zero; no numeric cast. | Keep |
| 3 | Financial | Monthly Income | Exact RM amount | Financial | — | None | MISSING | No salary or income amount field. | Add exact decimal amount with currency. |
| 3 | Financial | Personal Gross Annual Income | One of six source ranges | Financial | — | None | MISSING | No declared bracket. | Add source bracket code. |
| 3 | Financial | Joint Annual Income with Spouse | One of six source ranges | Financial | — | None | MISSING | Must not reuse personal amount. | Add independent bracket code. |
| 3 | Financial | Net Asset | One of six source ranges | Financial | — | None | MISSING | No net-asset bracket. | Add bracket code. |
| 3 | Financial | Net Investment Portfolio | One of six source ranges | Financial | — | None | MISSING | No portfolio bracket; source fifth label apparently mistyped. | Add separate bracket code; preserve source label metadata. |
| 3 | Financial | Portfolio Personal/Joint with Spouse | Single selection | Financial | — | None | MISSING | No ownership context. | Add portfolio ownership choice. |
| 3 | Financial | Source of Wealth/Funds | Multi-select: ten named + Others | Financial | — | None | MISSING | No repeatable selections. Salary is a source, not an amount. | Add controlled multi-select. |
| 3 | Financial | Source of Wealth/Funds — Others specify | Conditional text | Financial | — | None | MISSING | No Other details. | Add conditional text. |
| 4 | Investment | Investment Objectives | Multi-select, four choices | Investment | `CustomerInvestmentProfile.objectives` JSON array | I | COMPLETE | Controlled keys, validated array, all source choices. | Keep |
| 4 | Investment | Risk Tolerance | Conservative/Moderate/Aggressive | Investment | `.risk_tolerance` string | I | COMPLETE | Single select. | Keep |
| 4 | Investment | Trading Knowledge | Beginner/Intermediate/Advance | Investment | `.trading_knowledge` string | I | COMPLETE | `advanced` is semantically equivalent to Advance. | Keep |
| 4 | Investment | Investment Products | Multi-select: Local/Foreign Equities; two margin products; Others | Investment | `.products` JSON array | I | COMPLETE | Controlled choices and multi-select; JSON is constrained by validation. | Keep |
| 4 | Investment | Investment Products — Others specify | Conditional text | Investment | `.other_product_details` | I | COMPLETE | Required if Other selected. | Keep |
| 4 | Investment | Other Broker Accounts: Yes/No | Explicit single selection | Investment | Only account rows | None | MISSING | Zero rows cannot prove explicit No; rows do not date the answer. | Add tri-state declaration with date/source. |
| 4 | Investment | Broker Name | Repeatable text | Investment | `CustomerBrokerageAccount.broker_name` | I | COMPLETE | Multiple records. | Keep |
| 4 | Investment | Broker Branch/Address | Text | Investment | `.branch_address` | I | COMPLETE | — | Keep |
| 4 | Investment | Broker Account Type | Trading/Margin | Investment | `.account_type` | I | COMPLETE | Both choices validated. | Keep |
| 4 | PEP | Applicant/family/close associate PEP declaration | Yes/No/Not Declared | Compliance | `CustomerComplianceDeclaration.kind=pep/answer` | C | COMPLETE | Explicit tri-state, dated history, source and confirmation. | Keep |
| 4 | PEP | Related person Full Name | Repeatable text | Compliance | `RelatedPerson.full_legal_name` or linked relationship/customer | C | PARTIAL | Self, family, or new associate supported, but a new associate's name is disabled on edit and has no separate person editor. | Permit reviewed identity correction for standalone associate. |
| 4 | PEP | Relationship/Association | Text | Compliance | `CustomerPepAssociation.relationship` | C | PARTIAL | Separately stored, but standalone associate relationship input is disabled on edit. | Permit relationship correction with history. |
| 4 | PEP | Occupation / public position | Text | Compliance | `.position` | C | PARTIAL | UI combines occupation and public position in one input; both cannot be distinguished. | Separate occupation and public position or derive occupation from linked person. |
| 4 | PEP | NRIC | ID type + number | Compliance | `RelatedPerson.identification_type/number` or linked identity | C | PARTIAL | New person input or link, but standalone associate ID fields disappear on edit. | Permit reviewed ID correction for standalone associate. |
| 4 | PEP | Employer/Country | Text fields | Compliance | `.organization/.country` | C | COMPLETE | Both independently entered. | Keep |
| 4 | PEP | Current/former/seeking/considered status | Single choice | Compliance | `.position_status` | C | COMPLETE | All lifecycle choices selectable; repeatable dated associations. | Keep |

### Excluded source fields and pages

| Page | Classification | Source fields/groups | Customer-profile finding |
| --- | --- | --- | --- |
| 1 | A/O | Trading/Margin account type, margin limit, face-to-face verification, office client and RMR/DLR codes | Application and office data; excluded. |
| 2–3 | A | BSSB personnel relationship Yes/No and repeatable person/relationship/NRIC; existing BSSB client relationship Yes/No and person details; LTH group employee Yes/No | Institution-specific questions; excluded. |
| 3 | A | Malaysian-only and no-joint-account restrictions | Form rules, not global customer banking rules. DAS permits country and joint ownership. |
| 4 | A | BISonline Yes/No and EBC login ID; e-contract/e-statement email/BISonline; cheque/e-payment preference | Application service choices; excluded. |
| 5 | E | Signed clauses 1–11 including bankruptcy, correctness, terms, consent, payments, credit checks, trading and marketing statements | Evidence/legal declarations, not separate customer answers. DAS's independently sourced bankruptcy declaration does not turn the printed statement into a checkbox. |
| 5 | A/E | Principal/third-party choice and conditional party name, identification, registered address; applicant/witness signatures, names, IDs, dates | Application declaration and evidence; witness is not applicant master data. |
| 6 | E | Trading checklist: certified ID, LTH staff ID, one-month salary slip, tax assessment, one-month bank statement; margin checklist: certified ID, three-month salary slips, three-month bank statements, tax assessment; respective AND/OR conditions | Supporting evidence workflow; excluded. |
| 6 | O/E | Non-face-to-face methods: HR confirmation, cheque ≥RM100, video, BIMB group confirmation, Others; remarks, verifier signature/name/ID/date | Verification evidence; excluded. |
| 7 | O/E | Dealer and marketing/trading representative recommendation checkboxes, identities, signatures, witnesses, dates, recommended limits | Institution workflow; excluded. |
| 8 | O | Trading limit/justification/supporting description; client/brokerage/CDS fields; Low/Medium/High internal risk; High Net Worth Yes/No; sanction/PEP/other/adverse media/watch-list vetting; share-margin bankruptcy/discharge; remarks and staff process/approval/review dates | Office assessment; not customer-declared compliance. |
| 9–14 | S | Terms 1–37 | Static contract text; no standalone customer field identified. |

## C. Module coverage

| DAS module | Complete | Partial | Missing | Assessment |
| --- | ---: | ---: | ---: | --- |
| Personal & Identification | 10 | 0 | 0 | Source identity values and choices can be entered. |
| Contact & Address | 13 | 2 | 0 | Separate addresses; property Other text and platform-specific social handles need work. |
| Relationships | 9 | 5 | 0 | Linked/unlinked and multiple records work; related-person employer address parts are unstructured. NOK name, relationship and number are conditionally enforced. |
| Employment | 16 | 0 | 0 | Separate current/past jobs and retirement date. |
| Banking | 4 | 0 | 0 | Multiple accounts, string number, type, branch and joint ownership. |
| Financial | 0 | 0 | 8 | Entire reusable financial block absent. |
| Tax & Residency | 9 | 1 | 0 | Three independent FATCA answers and multi-jurisdiction TINs; selected US classification absent. |
| Investment | 8 | 0 | 1 | Controlled selections and brokerage rows; explicit other-broker No absent. |
| Compliance | 3 | 4 | 0 | PEP declaration/history and people; standalone associate identity cannot be edited, and occupation and public position share one field. |
| **Total** | **72** | **12** | **9** | **93 M rows**; **77.4% complete**. |

## D. Complete checkbox comparison

| Selection group | BIMB options / behavior | Actual DAS options / behavior | Result |
| --- | --- | --- | --- |
| Marital | Single, Married, Divorced; one | Single, Married, Divorced, Widowed; one | Complete |
| Gender | Male, Female; one | Male, Female, Other; one | Complete |
| Race | Malay, Chinese, Indian, Others | Free text; one value, all literal choices and other detail possible | Complete for lossless value; no curated selector |
| Property | Owned, Rented, Others specify | Owned, Rented, Other; one; no details | Partial |
| Employment status | Employed, Self-Employed, Retired, Others specify | Employed, Self-Employed, Unemployed, Retired, Other with required description | Complete |
| Personal annual income | ≤30k; 30,001–60k; 60,001–120k; 120,001–180k; 180,001–300k; >300k | None | Missing |
| Joint annual income | ≤50k; 50,001–100k; 100,001–200k; 200,001–300k; 300,001–400k; >400k | None | Missing |
| Net assets | ≤50k; 50,001–200k; 200,001–500k; 500,001–1m; 1,000,001–3m; >3m | None | Missing |
| Net portfolio | ≤50k; 50,001–100k; 100,001–300k; 300,001–500k; source prints `500,001–1,000,00`; >1m; Personal/Joint with Spouse | None | Missing; source typo unresolved |
| Source of wealth/funds | Savings; Salary; Business/Investment Income; Rental Income; Inherited Wealth; Shareholdings; Asset; Directorship; Credit Facility; Retained Earnings; Others specify; multiple | None | Missing |
| Investment objectives | Short Term (<1 year); Long Term (>3 years); Dividend Income; Capital Growth; multiple | Same four semantic choices, checkbox array | Complete |
| Risk tolerance | Conservative, Moderate, Aggressive; one | Same; one | Complete |
| Trading knowledge | Beginner, Intermediate, Advance; one | Beginner, Intermediate, Advanced; one | Complete |
| Investment products | Local equities, foreign equities, short-term share margin, share margin, Others specify; multiple | Same semantic five keys; checkbox array and required Other detail | Complete |
| Other broker declaration | Yes/No, separate from repeatable accounts | No declaration choice; accounts only | Missing |
| FATCA | Three separate Yes/No questions, plus selected Non US Person/US Person | Three separate Yes/No/Not Declared; computed US/Non-US/Undetermined, no explicit classification choice | Partial overall |
| PEP | Yes/No and repeatable people | Yes/No/Not Declared dated history, repeatable people; current/former/seeking/considered | Complete declaration; partial person occupation/public-position separation |

## E. Missing field and refinement list

`Migration impact` refers to moving existing DAS data; none of these recommendations justifies inferring a past declaration from a blank record.

| Module | Missing field/option | Recommended type | DB change? | UI change? | Data migration impact | Priority |
| --- | --- | --- | --- | --- | --- | --- |
| Financial | Monthly income | Nullable decimal RM amount plus currency/context | Yes | Yes | Existing values remain unknown | Critical |
| Financial | Personal annual bracket | Controlled nullable bracket code | Yes | Yes | No derivation from salary | Critical |
| Financial | Joint annual bracket | Separate controlled code | Yes | Yes | Existing values unknown | Critical |
| Financial | Net asset bracket | Separate controlled code | Yes | Yes | Existing values unknown | Critical |
| Financial | Portfolio bracket | Separate controlled code with source label | Yes | Yes | Confirm printed fifth label before mapping | Critical |
| Financial | Portfolio ownership | Personal/Joint nullable choice | Yes | Yes | Existing values unknown | Critical |
| Financial | Wealth/funds sources | Controlled multi-select; array or child rows with validation | Yes | Yes | Existing values unknown; no inferred Salary | Critical |
| Financial | Wealth/funds Other details | Conditional text | Yes | Yes | Existing values unknown | High |
| Investment | Explicit other-broker Yes/No | Tri-state dated declaration, optionally source | Yes | Yes | Zero account rows stay Not Declared | Critical |
| Tax | Explicit US/Non-US selection | Nullable selected classification on dated FATCA record | Yes | Yes | Historic computed value must not be backfilled as selected | High |
| Address | Property Other description | Nullable text on residential address | Yes | Yes | Existing `other` remains unspecified | Medium |
| Contact | Social platform per handle | Platform key plus repeatable contact or labeled fields | Yes | Yes | Existing unlabeled social values require review; do not guess platform | Medium |
| Relationships | Related-person employer address parts | Nullable address line/city/state/postcode/country or linked employment | Yes | Yes | Existing free text retained; parse only with review | Medium |
| Compliance | Separate occupation and public position | Nullable occupation field or linked-person occupation distinct from `.position` | Yes | Yes | Existing combined values require review | Medium |
| Compliance | Standalone PEP associate name, relationship and ID corrections | Existing `RelatedPerson` and association fields with controlled correction flow | No | Yes | Preserve prior association rows; review corrections | Medium |

## F. Recommended remediation order

1. Add a small, dated financial declaration record with eight independent source facts. Use controlled codes for brackets and wealth sources; keep exact monthly amount distinct. Confirm the portfolio printed-range typo with the form owner before fixing labels. This has highest form-completion and data-integrity impact.
2. Add explicit dated other-broker Yes/No/Not Declared and FATCA selected classification. Never infer past `No` from zero accounts or source-selected classification from the derived value.
3. Add conditional property Other text and platform-labeled social contacts. Preserve existing unlabeled social values for manual review.
4. Structure related-person employer address parts, separate occupation from public position, and permit reviewed identity corrections for standalone PEP associates. Keep existing text available during any future migration.
5. Add focused validation and feature tests for the new declarations and the source choice mappings when implementation is approved. This audit made no application changes.

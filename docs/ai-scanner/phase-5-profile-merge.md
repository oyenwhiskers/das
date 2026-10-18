# Phase 5 match and merge

A reviewed profile can be matched to an existing customer from the review page. Search uses the customer search scope. Selecting a customer requires permission to update that customer and to view the document. The profile does not create a customer, and Gemini does not choose one.

Identity uses the same normalized identification number and type comparison as the duplicate checker. A provided primary identification that differs from the selected customer blocks the merge. A missing identification on either side does not block it. A matching name, email, or mobile is shown only as a hint.

The preview compares reviewed values whose state is `provided`. Fill only empty is the default: a destination is empty only when the business value is null or absent. `false`, `0`, explicit `no`, and a non-empty child collection are not empty. Overwrite applies the provided values still included in the preview. It does not clear omitted fields or replace a whole child collection. Silence on a designation or end date does not change `is_primary`, `is_active`, or `ended_on`.

Bank accounts match by the existing identity hash. A related person matches by normalized identification, otherwise staff choose an existing row or a new row; another customer is not linked automatically. Tax residency matches the current jurisdiction key. Employment and brokerage rows have no identity hash, so an existing row is chosen by staff, or stored as new when the customer has none. Singular declarations compare field by field. The preview is computed for the request and is not stored. Identification, tax, and account numbers are masked the same way as the review table.

Each customer screen’s save transaction now lives in `CustomerProfileWrites`. The screens and the merge call those writers, so validation, normalization, derived columns, duplicate checks, primary-designation rules, and activity logs stay with the module. Livewire still owns form state and notifications.

Confirming a preview rechecks both policies, locks the customer, and recomputes the preview. A changed profile revision or compared customer value is rejected. The writers run in one transaction. `extracted_profile_merges` stores the unique submission key, source profile, customer, reviewer revision, strategy, and field changes with the previous value, new value, and evidence IDs. The same key returns the stored merge and does not write again. One customer activity summary names the document record and strategy and omits raw numbers. Sibling profiles on the same scan stay untouched. No customer foreign key is added to `extracted_profiles`.

Replace Completely is not implemented. It stays blocked until a business owner defines which absent fields and child rows may be deleted.

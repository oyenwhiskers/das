# Phase 2 Customer Profile schema

The shared definition source is `CustomerProfileSchema`. It emits 138 logical business paths in stable path order with fixed descriptor keys. The coverage test locks the exact paths and eight module counts. Form option validation and the financial/investment display labels read the same definitions. Save-time cross-field and database checks remain in their existing components.

Each new document scan records the SHA-256 hash of the canonical versioned schema. The full JSON is stored once per hash in `customer_profile_schema_snapshots`, so historical scans retain their original field meanings. Existing scans keep a null schema hash; no customer data or original findings are migrated.

The canonical schema measures **60,072 UTF-8 bytes** on this revision. This is roughly 15,000 tokens if using a four-bytes-per-token estimate, not a measured model token count. The configured `gemini-3.1-flash-lite` [model](https://ai.google.dev/gemini-api/docs/models/gemini-3.1-flash-lite) lists a 1,048,576 input-token limit, 65,536 output-token limit, and structured output support. The Phase 3 response format is a small fixed envelope with sparse values; the full 138-field schema is prompt context, not an expanded JSON response schema. Google does not state a separate response-format byte limit in the [Interactions API reference](https://ai.google.dev/api/interactions-api-v1). No paid request or token-count call was made. Phase 3 must measure the complete request, including source pages and findings, before enabling runtime extraction.

The migration was applied in the development database. Focused and full PHP tests pass. No customer columns or write paths changed.

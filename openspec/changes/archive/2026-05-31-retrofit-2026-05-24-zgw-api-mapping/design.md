# Design — retrofit zgw-api-mapping

Retrofit change. Tasks describe retroactive annotation, not new implementation work. The 5 REQs in this delta describe the procest-side ZGW surface (controllers, service, middleware, default-seed repair step) — the existing `zgw-api-mapping` spec focuses on the OpenRegister-side mapping engine.

## Method
- Survey each cluster file by public-method shape (file-level read, no per-method tracing)
- Group methods by observable behavior (REST resource handling, shared service surface, auth middleware, install seed)
- File-level `@spec` tag added to every cluster file (or appended where a Bucket 1 `@spec` already exists)

## Out of scope
- Per-method confidence scoring (deferred; will surface in coverage v2 once Vue sources are bucketed)
- Splitting the 32-method DrcController into per-resource controllers (future work)
- Resolving SendEmailHandler duplicates — handled under automatic-actions retrofit

# Design — retrofit zgw-business-rules-compliance

Retrofit change. Tasks describe retroactive annotation, not new implementation work. Adds five numbered REQs to a previously delta-only spec, covering the rules-service implementation surface invoked by ZGW controllers before write persistence.

## Method
- File-level survey of public method names + parameter shapes; minimal per-method tracing
- One REQ per service (base + facade + three per-API services) — keeps the cluster within the 5-REQ cap

## Out of scope
- ZgwZrcRulesService coverage (folded into enforcement-lhs; will be reverse-specced separately if Bucket 2 still reports it after the next coverage scan)
- Reorganising rules into JSON manifests (future architectural decision; documented as a TODO in spec notes)

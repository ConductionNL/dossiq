# Design — retrofit automatic-actions

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Method
- File-level survey by class signature + public method shape
- Group 10 files into 5 logical REQs (contract types + registry + 3 handler families)

## Out of scope
- Resolving the SendEmailHandler duplicate (Transitions/SendEmailHandler is owned by status-transition-engine)
- Adding new handler types (covered by the existing automatic-actions framework REQs)

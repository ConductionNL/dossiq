# Design — retrofit case-management (sharing + transfer + email + public access)

Retrofit change. Tasks describe retroactive annotation, not new implementation work. REQ numbers start at 101 to avoid collision with existing case-management REQs (the spec has 62 unnumbered named requirements; numbering this delta from 101 leaves room for future renumbering).

## Method
- File-level survey of public method names
- Group 7 files into 5 cohesive REQs by domain action

## Out of scope
- Renumbering the existing 62 named requirements (separate cleanup)
- Refactoring CaseEmailService to use the OpenRegister attachment API (planned in deelzaak-support follow-up)

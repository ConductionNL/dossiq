# Design: field-rules-declared

## D-1. The first list is short and named

Five fields, two groups (`dossiq-coordinators`, `dossiq-quality`), in the
proposal. Anything more is a case-type decision and waits for
`field-rules-by-state`.

## D-2. No dossiq filtering

The form and the data panel render what the platform returns. A vitest
asserts no `src/` code branches on a role for these fields.

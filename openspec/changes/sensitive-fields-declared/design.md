# Design: sensitive-fields-declared

## D-1. Inventory before declaring

Task 1.1 lists every property that holds a BSN or a special-category
value: `git grep -n -i "bsn\|burgerservicenummer" lib/Settings/`, and every
schema with `gdprClassification`. The list is the declaration's scope; it
is recorded in the tasks file.

## D-2. One group, one rule shape

Each listed field gets the field rule the openregister spec defines:
readable by group `dossiq-sensitive`, hidden otherwise, reveal audited.
The group is created by the repair step if absent.

## D-3. Retire what the platform now does

`CitizenLookupGuard` loses its field check; the rate limit stays.
`sociaalDomeinAuditLog` rows of kind reveal stop being written; the log
keeps its other kinds.

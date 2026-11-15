# Tasks: import-vocabulary-guard

- [x] 1. Drop `inputControl: textarea` from `aanvullingsverzoek.applicantAnswer`; bump the schema to 1.3.1 and the register to 0.20.26.
- [x] 2. Add the OpenRegister accepted-vocabulary snapshot.
- [x] 3. Check every shipped property key, closed-list value and extending-form role against it.
- [x] 4. Live: re-import on :8098 with the OpenRegister fix; no `PARTIAL IMPORT`, the case schema carries `hideOnCreate`.

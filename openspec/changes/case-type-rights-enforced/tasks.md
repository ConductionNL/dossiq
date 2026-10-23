# Tasks: case-type-rights-enforced

Depends on OpenRegister `rbac-matrix-app-rows-and-file-verbs`.

## 1. Declaration

- [ ] 1.1 caseType 1.34.0 fragment: `$ownTeam`, `statuses`, `documentActions` on rights rows; seed rows for Omgevingsvergunning regulier
- [ ] 1.2 Case schema: matrix on `assignedGroup`, `{groups: true}`, `rowsFrom: dossiq.case-type-rights`; base file verbs (design D-3)

## 2. Enforcement

- [ ] 2.1 `CaseTypeRightsRows` listener (design D-2); PHPUnit per table row
- [ ] 2.2 Access tests 1 to 6 (design) through OpenRegister's real permission handler

## 3. What people see

- [ ] 3.1 `CaseTypeRightsByRole`: Eigen team, statuses, documents, Besluiten from the mandate register; PHPUnit
- [ ] 3.2 Rights tab: department "Eigen team", status multi-select, documents choice; vitest
- [ ] 3.3 Tenant setup checklist "Mandaten ingesteld"; PHPUnit
- [ ] 3.4 l10n

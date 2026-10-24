# Tasks: case-type-rights-enforced

Depends on OpenRegister `rbac-matrix-app-rows-and-file-verbs`.

## 1. Declaration

- [x] 1.1 caseType 1.34.0 fragment: `$ownTeam`, `statuses`, `documentActions` on rights rows; seed rows for Omgevingsvergunning regulier
- [x] 1.2 Case schema: matrix on `assignedGroup`, `{groups: true}`, `rowsFrom: dossiq.case-type-rights`; base file verbs (design D-3)

## 2. Enforcement

- [x] 2.1 `CaseTypeRightsRows` listener (design D-2); PHPUnit per table row
- [x] 2.2 Access tests 1 to 6 (design) through OpenRegister's real permission handler, PHP and SQL list paths (CaseTypeRightsEnforcedAccessTest; runs against the sibling openregister checkout, skips without it)

## 3. What people see

- [x] 3.1 `CaseTypeRightsByRole`: Eigen team, statuses, documents, Besluiten from the mandate register; PHPUnit
- [x] 3.2 Rights tab: department "Eigen team", status multi-select, documents choice; vitest caseTypeRightsEditor (needs nc-vue feat/field-list-multiselect-and-endpoint-options)
- [ ] 3.3 Tenant setup checklist "Mandaten ingesteld": not built; the row on board DqTenant is a Mandaten card history row filled by the example set (tenantMandate), not a checklist step
- [x] 3.4 l10n

# Proposal: case-type-rights-enforced

## Why

A case type's Rights tab declares who may do what (`caseType.rightsMatrix`), and the Overview shows it per role (`case-type-rights-by-role`). Nothing enforces it. The case schema grants read and update only to the assignee and the background service (`66-notification-routing.json`), so the matrix is a promise the system does not keep. The by-role projection also listed three things the matrix cannot say. Ruben decided them on 23 October 2026:

1. A rights row can name **own team**: resolved at check time to the caller's own team. A real access rule.
2. A rights row can be **limited to statuses** (the case type's phases). Outside them the role cannot edit.
3. The **Besluiten** column reads the **mandate register** ("Tot € 50.000"), not a verb in the matrix. The same register answers "Mandaten ingesteld" on the tenant page.
4. **Documents get their own rights per role**: read, add and delete a case's files, enforced on the files.

## What changes

- **Rights rows** gain `department: "$ownTeam"`, `statuses` (statusType ids; limits the row's edit verbs) and `documentActions` (`read`, `add`, `delete`). Schema `caseType` 1.34.0.
- **Enforcement in OpenRegister**, through its generic matrix (OpenRegister change `rbac-matrix-app-rows-and-file-verbs`). The case schema declares a matrix on `assignedGroup`, user source `{groups: true}`, `rowsFrom: "dossiq.case-type-rights"`, and the base file verbs for the assignee and the background service. dossiq's listener `CaseTypeRightsRows` turns the current case types' rows into matrix rows (design D-2). dossiq still computes no effective permission: it translates its declaration into OpenRegister's vocabulary, and OpenRegister decides.
- **The by-role projection** (`CaseTypeRightsByRole`) shows the new values: See cases "Eigen team"; Handle "Alle fasen", the status names, or "Nee"; Decide from the mandate register ("Tot € 50.000", "Onbeperkt", "Nee"); Documents from `documentActions` ("Alles", "Toevoegen", "Lezen", "Nee"), falling back to the old verb reading for rows without it.
- **Rights tab editor**: the department picker offers "Eigen team", a status multi-select per row, and a documents choice per row.
- **DqTenant**: the setup checklist row "Mandaten ingesteld" is done when the organisation has an active mandate.

## What does not change

- The matrix only adds ways in. The assignee keeps reading and editing their own case whatever the matrix says; "outside the statuses the role cannot edit" is about the role's grant.
- Case types without rights rows keep today's access.
- Mandate enforcement on decisions stays where it is (`MandaatCheckService`).

## Reach

dossiq: schema fragment, listener, projection, Rights tab config, l10n, tests. OpenRegister: the generic change named above, built first. The DqZaaktypeOverzicht board already draws these shapes (Eigen team, Fase 1 / Alle fasen, Tot € 50.000, Toevoegen / Alles / Lezen), so no board change is needed.

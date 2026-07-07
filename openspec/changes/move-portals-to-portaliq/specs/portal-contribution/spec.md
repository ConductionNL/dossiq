# Portal contribution — multi-audience move to Portaliq

@e2e exclude The portal contribution is a dependency-free declarative provider class (`OCA\Procest\Portal\PortalContributionProvider`) read by the SEPARATE Portaliq app; it renders in Portaliq's shell, not in any procest browser surface, so there is no procest-only Playwright surface that drives it. Its behaviour (three audiences, per-audience collections/actions, fields projection, null for unserved audiences) + the register-drift pin on every scopeField/projected-field are proven by the PHPUnit `PortalContributionProviderTest`. The subject-scoped read/verify/projection path is owned and e2e-covered by Portaliq (contract v2.2 `PortalObjectReader`). The retirement half (deleted views/nav/routes) removes procest surfaces, so it has no positive browser assertion of its own.

## ADDED Requirements

### Requirement: REQ-PORTAL-001 — The provider MUST declare three portal audiences (supplier, citizen, inspector)

`OCA\Procest\Portal\PortalContributionProvider` MUST expose
`getAudiences()` returning exactly `['supplier','citizen','inspector']` and keep
`getAudience()` returning `'supplier'` as the contract-v1 fallback. It MUST remain
a plain class — no Portaliq import, no `implements`, no info.xml dependency, no
constructor dependencies — so it is inert when Portaliq is absent.
`getContribution($subject)` MUST branch on `$subject['audience']` and MUST return
`null` for any audience procest does not serve (fail-closed, ADR-005).

#### Scenario: Provider advertises all three audiences

- GIVEN the Portaliq registry probes the procest provider
- WHEN it calls `getAudiences()`
- THEN it receives `['supplier','citizen','inspector']` and `getAudience()` returns `'supplier'`

#### Scenario: Unserved audience contributes nothing

- GIVEN a resolved subject whose `audience` is not one procest serves
- WHEN `getContribution($subject)` is called
- THEN it returns `null`

### Requirement: REQ-PORTAL-002 — The citizen audience MUST expose the 'Mijn gemeente' surface as subject-scoped, field-projected collections plus one safe create

For `audience: 'citizen'`, `getContribution()` MUST return the collections
`mijnZaken` (`case`, scopeField `portaalSubject`), `berichten`
(`portaalBericht`, `kind: 'inbox'`, scopeField `recipientRef`) and `verzoeken`
(`portaalVerzoek`, scopeField `submitterRef`), each carrying a `fields` whitelist
that omits staff/internal columns, and exactly one action `createKlacht`
(`portaalVerzoek`, scopeField `submitterRef`) whitelisting only citizen-authored
content with no case cross-reference. The bezwaar and message-reply creates MUST
NOT be declared (deferred write-IDOR, portaliq#16).

#### Scenario: Citizen sees their own cases, inbox and requests

- GIVEN a resolved citizen subject
- WHEN `getContribution()` runs for `audience: 'citizen'`
- THEN the manifest lists `mijnZaken`, `berichten` and `verzoeken`, each with a `fields` whitelist, and a single `createKlacht` action

#### Scenario: Every citizen scopeField and projected field exists on its schema

- GIVEN the citizen collections' schemas in `procest_register.json`
- WHEN each collection's `scopeField` and each `fields` entry is checked against the schema properties
- THEN every one exists (register-drift pin)

### Requirement: REQ-PORTAL-003 — The inspector audience MUST expose an external field inspector's assigned inspections, scoped by a non-NC-account reference

For `audience: 'inspector'`, `getContribution()` MUST return the read collections
`inspectieRapporten` (`inspectieRapport`) and `checklistRuns`
(`inspectionChecklistRun`), both scoped by `assignedInspectorRef` — the external
inspector's pseudonymous portal reference, distinct from the internal `inspector`
NC-user-UID column — and both field-projected to the inspector's own result-level
data. No create action is declared (deferred run-submit, portaliq#16).

#### Scenario: External inspector sees only their assigned inspections

- GIVEN a resolved inspector subject
- WHEN `getContribution()` runs for `audience: 'inspector'`
- THEN the manifest lists `inspectieRapporten` and `checklistRuns`, both scoped by `assignedInspectorRef`, with no create action

### Requirement: REQ-PORTAL-004 — The in-app portal Vue surfaces MUST be retired while the backend API and schemas remain

The in-app supplier portal (`/leverancier`), citizen portal (`/portaal/*`) and
field-inspection nav page (`/inspecties`) Vue views, their manifest fragments,
their `PortaalGroup` nav group and their routes MUST be removed from the procest
frontend. The backend controllers/services and their `/api/leverancier-portaal/*`,
`/api/portaal/*` and `/api/inspections/*` endpoints, and the OpenRegister schemas,
MUST remain unchanged (Portaliq reads OpenRegister directly).

#### Scenario: Retired portal nav entries no longer render

- GIVEN the procest app navigation is built from the manifest fragments + menu-layout
- WHEN the sidebar renders
- THEN no `LeverancierDashboard`, `MijnZaken`, `MijnNotificaties` or `Inspecties` menu entry and no `PortaalGroup` group appears

#### Scenario: Backend supplier/portal/inspection endpoints still resolve

- GIVEN the retired in-app views are deleted
- WHEN a request hits `/api/leverancier-portaal/*`, `/api/portaal/*` or `/api/inspections/*`
- THEN the backend controllers still resolve (only the in-app Vue surfaces were removed)

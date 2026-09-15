# Tasks: starter-content-and-templates

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 3, candidates
C-configuration-3 (matrix hole), C-configuration-94, C-configuration-5,
C-configuration-12, C-configuration-23, C-configuration-24,
C-configuration-30, C-configuration-70, C-intake-17 and
C-access-and-privacy-77, with C-configuration-59, C-configuration-77 and
C-configuration-85 declared into buildiq and portaliq. Decisions D6
(every must enters) and D17 (six members recorded, not built). Waits on
nothing.

- [x] 1.1 `caseType`: one declared block of handling switches, the default
  group, the default handler, the automatic messages and the intake
  screen (D-2).
  - `tests/unit/Service/CaseTypeHandlingSwitchesTest.php`
  - `@spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md`
- [x] 1.2 Move every scattered reader onto that block: the routing
  strategies, `CaseTypeReader`, `TermijnNotificationService` and the
  manifest intake screen (D-2).
- [x] 1.3 `case-type-publish-validation`: refuse a declared switch no
  reader reads (D-2).
  - `tests/unit/Service/CaseTypePublishValidationTest.php`
- [x] 2.1 `SeedDataService` and `TenantSeedService`: stamp every seeded
  object with the set name and version (D-1).
  - `tests/unit/Service/SeedDataOriginTest.php`
- [x] 2.2 The shipped configuration screen: shipped and untouched, shipped
  and changed here, or ours, per object (D-1).
  - `tests/vitest/shippedConfiguration.spec.js`
- [x] 2.3 Adoption of a newer shipped version, per object, never
  overwriting a local change (D-1).
- [x] 3.1 `roleType`: the named municipal role set, shipped dormant, with
  adoption recorded and reversible while unused (D-8).
  - `tests/unit/Service/MunicipalRoleSetTest.php`
- [x] 4.1 `caseType`: derive and name the draft, in use and retired state
  from `isDraft`, `validFrom` and `validUntil` (D-3).
  - `tests/unit/Service/CaseTypeLifecycleStateTest.php`
- [x] 4.2 A retired case type takes no new case, keeps its cases readable
  and lets a running case finish; retire and restore are recorded (D-3).
- [x] 5.1 `CaseTypeCopyService`: copy from a case type marked as a
  template, and report anything the copy could not carry (D-4, D-5).
  - `tests/unit/Service/CaseTypeCopyServiceTest.php`
- [x] 5.2 Domain copy: case types, role types, templates and code lists,
  as a declared list (D-4).
  - `tests/unit/Service/DomainCopyServiceTest.php`
- [x] 6.1 The case template: a case row marked as a template, excluded
  from every working list, count and term calculation (D-5).
  - `tests/unit/Service/CaseTemplateTest.php`
- [x] 6.2 Start a case from a template, recording which one (D-5).
  - `tests/vitest/caseFromTemplate.spec.js`
- [x] 7.1 `TemplateLibraryService`: a `kind` covering document, mail,
  task, note, approval and result, scoped to case types (D-6).
  - `tests/unit/Service/TemplateLibraryKindTest.php`
- [x] 7.2 Offer the template where each kind is created, in the task
  dialog, the note editor, the approval and the close form (D-6).
- [x] 7.3 The reusable process step: referenced, not copied, with both
  directions readable and deletion refused while in use (D-6).
  - `tests/unit/Service/ReusableProcessStepTest.php`
- [x] 8.1 The connection test on every connection screen, starting with
  `src/views/settings/StufEndpoints.vue`: a real call, a timeout, the
  measured moment, and not tested as the default (D-7).
  - `tests/unit/Controller/ConnectionTestControllerTest.php`
  - `tests/vitest/connectionTest.spec.js`
- [x] 9.1 Declare the three other-app halves and hand them over with their
  candidate ids: the reusable form block (C-configuration-59, buildiq),
  the portal home tiles (C-configuration-77, portaliq) and the free text
  block (C-configuration-85, buildiq).
- [x] 9.2 Dutch and English strings.
- [x] 9.3 `tests/e2e/starter-content-and-templates.spec.ts`: the handling
  switch changed once, a shipped object read as changed here, the role
  set adopted, a case type retired with its cases still open, a domain
  copied, a case started from a template, a task template offered, a
  reusable step changed once for two case types, and a StUF endpoint
  probed; `openspec validate starter-content-and-templates --strict`.

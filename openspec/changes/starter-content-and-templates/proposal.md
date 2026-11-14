---
kind: code
depends_on: []
---

# Proposal: starter-content-and-templates

Round 4 discovery, cluster 3 "What a new instance starts with"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Twenty-one candidates,
twenty-seven passers, nineteen driven, proving system OpenProject. Owner
dossiq, size M, no decision of its own. This change carries thirteen of
the twenty-one; `first-run-and-the-tour` carries the other two that are a
person being taught, and six are recorded rather than built.

The register records why the cluster carried nothing until now
(`procest/_gaps/gap-register.json`, `discovery.counts.uncarried_reason`,
cluster 3): "no change opened; the plan hands the seed to dossiq
`lib/Settings/vth-templates/` and `TenantSeedService` and the walkthrough
to buildiq, and neither repo indexed it".

## Why the cluster is two changes

Two nouns, and the build plan splits them itself: "extend dossiq
`lib/Settings/vth-templates/` and `TenantSeedService`; buildiq owns the
walkthrough". What ships in the box is content dossiq authors, seeds and
copies. Being walked through a first run is a person being taught, it is
buildiq's surface, and dossiq only declares the steps. Keeping them in
one change would have put a buildiq dependency in front of thirteen
requirements that wait on nothing.

## What is actually there

Read against `development` at `172d364f` while writing this.

- Seed content exists and is broad: `lib/Settings/vth-templates/` holds
  six case types, and `lib/Settings/` carries `bezwaar_seed_data.json`,
  `case_flow_seed_data.json`, `termijnbewaking_seed_data.json`,
  `kcc_werkplek_seed_data.json`, `lhs_matrix_seed.json` and
  `vth_seed_data.json`, loaded by `SeedDataService` and `TenantSeedService`.
  So C-configuration-94 is nearer `yes` than the lane's read, which is
  what the register already says.
- Copying exists at one level only: `lib/Service/CaseTypeCopyService.php`
  copies one case type. Nothing marks a case type as a template, nothing
  copies a whole configured domain, and nothing presets a case.
- `lib/Service/TemplateLibraryService.php` and
  `lib/Service/EmailTemplateService.php` cover documents and mail. There
  is no template for a task, a note, an approval or a result.
- `src/views/settings/EmailSettings.vue:256` has `testConnection`. It is
  the only connection screen that probes. `src/views/settings/StufEndpoints.vue`
  lists endpoints and never calls them.
- Roles are configurable and the shipped set is empty: `roleType` carries
  no seeded municipal roles.

So the cluster is not "dossiq has nothing". It is one seed that stops
short of the things a gemeente copies on its second day.

## The candidates this change carries

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-configuration-3 | must, matrix hole | yes | a case type carries its own handling switches, set in configuration |
| C-configuration-94 | must | yes | the product ships ready made case type configuration the customer adapts |
| C-configuration-5 | should | yes | a case type is taken out of use without being deleted, and can be brought back |
| C-configuration-12 | should | yes | a connection is tested from the configuration screen before it is relied on |
| C-configuration-23 | should | yes | a new case type is created by duplicating an existing one or a template |
| C-configuration-24 | should | partial | a new domain is created from a template or a copy, carrying its configuration |
| C-configuration-30 | should | no | a process is saved as a reusable template and a designed step is reused in another workflow |
| C-configuration-70 | should | no | templates are kept for tasks, notes, approvals and outcomes, not only for documents and mail |
| C-intake-17 | should | partial | a record starts from a saved template that presets its fields |
| C-access-and-privacy-77 | should | partial | the product ships a named municipal role set ready to use |
| C-configuration-59 | should | no | forms are built from reusable blocks rather than from empty fields |
| C-configuration-77 | should | no | the citizen's home page is composed from administered tiles |
| C-configuration-85 | should | no | the owner of a page places free text and HTML on it |

The last three are other apps' surfaces. They are named under Ownership
and dossiq builds no half of them here beyond the declaration.

The proving evidence, verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-configuration-3, `configuration.tsv:54`: "xxllnc-zaken: Case type >
  Registratieformulier (case-type-editor-anatomy.md)". The lane's note:
  "Both are a list of behaviour toggles held on the case type. matrix
  hole".
- C-configuration-24, `configuration.tsv:34`: "openproject: Project
  settings Copy project, app/services/projects/copy/ 17 dependent
  services, templated_controller.rb". Seven driven passers, the widest in
  the cluster.
- C-configuration-23, `configuration.tsv:52`: "taiga: POST /projects from
  project-templates (models.py:989-1025)".
- C-configuration-70, `configuration.tsv:98`: "glpi: Task, solution,
  followup and validation templates (dropdowns TaskTemplate,
  SolutionTemplate, ITILFollowupTemplate, ITILValidationTemplate)".
- C-configuration-12, `cross-area.tsv:5`: "redmine: post
  'admin/test_email' and get 'test_connection' on auth_sources". The
  lane's clause: "mail that silently stopped is how a term is missed".
- C-configuration-5, `configuration.tsv:35`: "xxllnc-zaken: Catalogus,
  Meer opties (case-type-editor-anatomy.md)".
- C-intake-17, `intake.tsv:47`: "huly: Tracker, issue templates
  (menu-tree.md)". Its clause: "twelve standaardzaken that differ in four
  fields each".
- C-access-and-privacy-77, `access-and-privacy.tsv:90`: "xxllnc-zaken:
  Gebruikers (Gebruikers.md)". Its clause: "twenty-one seeded roles is a
  week of implementation the buyer does not pay for".

**D6 was answered relevance-led**, so both `must` candidates enter whatever
their passer count, and C-configuration-94 enters on three documented
passers alone. **D17 was answered for a broad market**: six of this
cluster's members are vendor-model claims rather than capabilities, and
they are recorded below rather than disqualified.

## What changes

- A case type carries its handling switches on itself: the default group,
  the default handler, which mails go out, and which intake screen is
  used. They are read from one place and not scattered across services.
- The shipped configuration is named, versioned and adoptable. An
  administrator sees what shipped, what they changed, and what a new
  version of the shipped set would change.
- A named municipal role set ships beside it, and adopting it is one act
  that can be undone.
- A case type is retired and restored. Retired means no new cases and
  every existing case stays readable and finishable.
- A case type is created by copying an existing one or one marked as a
  template, and a whole domain (its case types, roles, templates and
  code lists) is created by copying another.
- A case starts from a saved case template that presets its fields.
- The template library covers tasks, notes, approvals and results, not
  only documents and mail, and a designed process step is reused in
  another case type.
- Every configured connection is tested from its own screen, with the
  failure named.

## Ownership

dossiq builds the seed, the copies, the retirement and the template
library. It is dossiq's own configuration and dossiq's own files.

Three candidates are another app's surface and dossiq only declares into
them:

- C-configuration-59, reusable blocks in the form builder: **buildiq**.
  Its `openspec/specs/form-editor-logic/spec.md` owns the builder and the
  register already names it for rows 3.12 and 11.4. dossiq's half is that
  a case type points at a form built there.
- C-configuration-77, the citizen's home page from administered tiles:
  **portaliq**. dossiq contributes its case types to the tile source and
  hosts no portal page, per the archived `move-portals-to-portaliq`.
- C-configuration-85, free text and HTML on a page: **buildiq**
  `page-layout-per-case-type`, the change the register names for rows
  11.6, 11.7 and 11.8.

### Needs a change in buildiq

Neither buildiq slug carries a free text block or a reusable form block
today. `page-layout-per-case-type` is named by the register as a slug
with no artefact on buildiq `development`, and `form-editor-logic` is a
spec that does not mention blocks. A follow-up lane should open, in
buildiq, a change covering the reusable block library
(C-configuration-59) and the free text block (C-configuration-85).

## Recorded, not built

Six members are vendor-model or positioning claims, not behaviour. D17
keeps them in the corpus; this change records them and builds none.

| candidate | why not built |
|---|---|
| C-configuration-60 | "governed alternatives inside the tools people already use" is a positioning claim. The lane itself says "Positioning claim, no testable behaviour named" |
| C-configuration-76 | the case system hosted inside another vendor's platform. A competing architecture, and the fleet's answer is Nextcloud |
| C-configuration-79 | reconfiguration for new law without the vendor. Already true of an open source fleet; nothing to build |
| C-configuration-83 | co-development with named launching municipalities. A development model. The lane rated dossiq "not applicable, the fleet is open source" |
| C-configuration-102 | a family of adjacent applications beside the case system. The fleet is that shape already |
| C-configuration-103 | vendor monitoring sold as a module. An operational service, not a product capability |

## ADRs

- Company ADR-011: search OpenRegister before implementing a utility. The
  copy walks OpenRegister objects and reuses its own copy machinery.
- Company ADR-102: config absence fails closed with a status. A case type
  whose shipped origin cannot be resolved refuses to publish rather than
  silently losing its switches.
- dossiq `openspec/specs/case-type-seed-data/spec.md` REQ-CT-02 is the
  requirement this extends.

## Capabilities

- Modified: `case-type-seed-data`: the shipped set is named, versioned,
  adoptable and retirable, and the handling switches sit on the case type.
- Modified: `template-library`: templates cover tasks, notes, approvals,
  results and process steps, and a case starts from one.
- Modified: `admin-settings`: a connection is tested from its own screen.

## Impact

`lib/Settings/vth-templates/`, `lib/Settings/*_seed_data.json`,
`lib/Service/SeedDataService.php`, `lib/Service/TenantSeedService.php`,
`lib/Service/CaseTypeCopyService.php`,
`lib/Service/TemplateLibraryService.php`, the `caseType` and `roleType`
schemas, `src/views/settings/StufEndpoints.vue`, Dutch and English
strings.

## Out of scope

- The form builder itself. buildiq, rows 3.12, 11.4 and Q1.15.
- The portal home page. portaliq, cluster 7.
- The page layout per case type. buildiq, rows 11.6 to 11.8.
- The field vocabulary a case type may use. dossiq
  `casetype-field-vocabulary`, wave 1.

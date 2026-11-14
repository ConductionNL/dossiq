---
kind: code
depends_on: []
---

# Proposal: first-run-and-the-tour

Round 4 discovery, cluster 3 "What a new instance starts with"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner dossiq, size S. This
is the second of the two changes cluster 3 is split into, and it carries
the two members that are a person being taught rather than content being
shipped: C-configuration-43 and C-configuration-96. The other thirteen
are in `starter-content-and-templates`, which also records why the split
exists: the build plan hands the seed to dossiq and says "buildiq owns
the walkthrough", so the two halves wait on different repos.

## Why

A gemeente onboards two hundred behandelaars once. The lane's clause on
C-configuration-96: "a gemeente onboards two hundred behandelaars once
and a tour is cheaper than two hundred hours of training".

The administrator's half is different and worse. dossiq's first run
checks that OpenRegister answers and then lets go. Everything a working
instance needs after that, the organisation, the mail account, the case
types, the roles, is found by hunting through settings screens.

## What is actually there

Read against `development` at `172d364f`.

- `src/manifest.json:66` carries a `walkthrough` block with
  `completionConfigKey: walkthrough_completed_version`. So
  C-configuration-96 reads `yes`, as the register has it.
- `src/manifest.json` carries a `setup` block, specified in
  `openspec/specs/first-time-setup/spec.md` REQ-SETUP-PRO-001. Its steps
  are `welcome`, `demo-data`, `load-demo-data`, `register-check`,
  `dwangsom-secret` and `done`. Exactly one is required, and it is
  `register-check`.
- `lib/Controller/SetupController.php` and
  `lib/Controller/TenantOnboardingController.php` exist.

The lane's read is precise and stands: "partial, the walkthrough teaches
the ui and configures nothing". The wizard gates on a register and the
tour teaches a screen. Nothing walks an administrator through the
minimum an instance needs to take its first real case.

## The candidates this change carries

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-configuration-43 | should | partial | an administrator is walked through the minimum configuration on first run |
| C-configuration-96 | should | yes | the product walks a new user through its surfaces in their own environment |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-configuration-43, `configuration.tsv:33`: "zammad: Getting started and
  first steps (config/routes/first_steps.rb, getting_started.rb)". Two
  driven passers, Frappe Helpdesk and Zammad, and the lane's note: "Both
  describe a first run wizard that sets up the product".
- C-configuration-96, `configuration.tsv:67`: "plane: user-onboard and
  user-tour routes, Profile.is_tour_completed". The lane's note: "Both
  teach the end user inside the running product".

Neither is a `must`, so **D6** admits them on relevance: both are
`should`, both are directly about a buyer's first hour, and one of the
two is already half shipped. **D17** does not reach this cluster's
members.

## What changes

- The first run names the minimum an instance needs before it can take a
  case, and reports per item whether it is done: the organisation, the
  mail account, at least one published case type, at least one role with
  a holder, and the working calendar.
- Each item links to the screen that satisfies it and re-reads itself
  when the administrator comes back.
- The wizard states what is not done rather than gating on it. Only
  `register-check` gates, as it does now.
- The tour is per surface and per person, so a handler who joins in
  month nine gets the tour and an administrator does not get it again.
- A tour step names the surface it teaches, and a step whose surface is
  gone is reported rather than silently skipped.

## Ownership

dossiq declares the readiness items, reads whether each is satisfied, and
declares its tour steps. It builds no wizard component and no tour
component.

- The wizard shell is **nextcloud-vue**'s `CnSetupWizard`, already
  consumed by `first-time-setup` REQ-SETUP-PRO-001.
- The tour surface is **buildiq**'s. The build plan says so: "buildiq owns
  the walkthrough". The register names `buildiq/page-layout-per-case-type`
  for the page layout half and nothing for the tour.

### Needs a change in buildiq

No buildiq slug in the register's `changes_by_repo` covers a per-surface,
per-person product tour. The tour block dossiq already ships is a manifest
declaration with no owner on the other side. A follow-up lane should open
a buildiq change for the tour runner: per-surface steps, per-person
completion, and a step whose surface is gone reported rather than
skipped. Until it exists, dossiq's declaration is read by the walkthrough
it already has.

## ADRs

- Company ADR-102: config absence fails closed with a status. A readiness
  item that cannot be read reads as not done, never as done.
- dossiq `openspec/specs/first-time-setup/spec.md` REQ-SETUP-PRO-001 and
  REQ-SETUP-PRO-003 are what this extends. Their lesson is carried
  forward: the declared step list and the reported step list agree in
  both directions, and no step is offered that cannot be fulfilled.

## Capabilities

- Modified: `first-time-setup`: the first run names the minimum
  configuration, reports it per item, and does not gate on it.

## Impact

`src/manifest.json` (the `setup` and `walkthrough` blocks),
`lib/Controller/SetupController.php`,
`lib/Controller/TenantOnboardingController.php`, Dutch and English
strings.

## Out of scope

- The wizard component. nextcloud-vue `CnSetupWizard`.
- The tour runner. buildiq, named above.
- What ships in the box. dossiq `starter-content-and-templates`.

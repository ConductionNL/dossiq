# Seed objects and packages reach upgraded instances

## What was wrong

dossiq ships the Woo case type in `register.d/81-woo-verzoek.json` at
`@self.version` 1.4.7, with `handling.defaultGroup: behandelaars` and
`workflowDefinition: cb967581-…`, and the flow itself in the auto-install
package `packages/woo-verzoek/`. Fresh installs were fine. On the upgraded
Zuiddrecht instance (14 Oct) the stored case type kept `handling: null`, no
`workflowDefinition` and version 1. Staff in `behandelaars` could not see
portal-filed Woo requests and the stage guards were absent.

The instance log showed what happened during `occ upgrade`:

1. 18:17:59, InitializeSettings imported the register. OpenRegister tried to
   update the case type (1.4.7 is higher than 1) and dossiq's
   `CaseTypePortalWithdrawalListener` refused it: "A case in Ontvangst cannot
   move to Afgehandeld, so a withdrawal there would fail."
2. 18:18:03, InstallShippedPackages installed `woo-verzoek` 1.0.0 and wrote the
   flow. The package step already runs on upgrade; it was not the gap.
3. 20:06, a forced re-import was refused the same way, with the flow present.

The listener judged the case type by `CaseTypeStore::activeTemplate()`: the
first active template of the type. The instance still held an older active
basis flow (written by MigrateWorkflowDefinitions before dossiq shipped one),
which has no move from Ontvangst to Afgehandeld. The flow the incoming case type
names was never looked at.

## What changes

- `CaseTypeStore::activeTemplate()` takes an optional named flow and answers the flow a case type names in
  `workflowDefinition`, else its active one. The withdrawal listener judges a
  save by the flow the incoming payload names.
- InstallShippedPackages imports the register configuration again, unforced,
  after it installed a package. A seed that names a row the package writes is
  written in the same upgrade. With OpenRegister's matching change (an import a
  listener refused part of is not remembered as done) this second import does
  the work; otherwise it is a no-op.

## Out of scope

A package raised to a new version adds the rows it lacks and leaves rows already
present alone (the existing rule: a local edit survives). Refreshing rows that
are untouched since they were stamped is a follow-up.

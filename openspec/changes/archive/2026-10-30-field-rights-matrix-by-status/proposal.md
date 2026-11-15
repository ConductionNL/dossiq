# Proposal: field-rights-matrix-by-status

## Why

Board DqZaaktypeVelden draws "Wie wat ziet en invult, per rol en status": a matrix of fields by role and status with
Verplicht, Bewerken, Alleen lezen and Verborgen. Live lists the stored field rules ("per groep en status"), and a
field rule cannot be limited to statuses at all.

Coordinator decision, 24 Oct: keep the model (in Nextcloud a role is a group, so fieldRoleRules per group are per
role), build the matrix as a view over the rules, add an optional `statuses` list to a rule (empty means every
status) and enforce it where field rights are already published. The labels say "rol".

## What changes

- caseType 1.41.0: `fieldRoleRules[].statuses` (status type ids, optional).
- Enforcement: `FieldRoleRuleDeclaration::lifecycleFields()` takes the status whose lifecycle state is built and
  leaves out a rule limited to other statuses; `CaseStateFieldRuleProjector` builds the role entries per state. A
  status-limited rule publishes no schema-wide property authorization block, which would hold in every status.
- View: `CaseTypeFieldRightsWidget` on the Fields tab, a matrix with a column per status of the case type and a row per
  field a rule names or a status requires (statusType fieldRules `required`, a property's `requiredAtStatus`). A cell
  says Verplicht, "Verborgen voor {rol}" / "Alleen lezen voor {rol}", or Bewerken when nothing restricts the field.
- The card is titled "Wie wat ziet en invult, per rol en status". The rules with their reasons stay on the case's
  access tab.

## Reach

One schema fragment (+ recorded digest), two PHP services, a util, a widget, the manifest, l10n.

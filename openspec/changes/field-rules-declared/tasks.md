# Tasks: field-rules-declared

Tier: V1. Kind: config. Row 13.8.

- [x] 1.1 `lib/Settings/register.d/67-field-role-rules.json`: `caseType.fieldRoleRules`
  beside the rights matrix, with the two group lists named for what they do;
  `dossiq-coordinators`, `dossiq-quality` and `dossiq-risk-assessment` added to
  `ProvisionAssignedGroups::ASSIGNED_GROUPS`.
  - `@spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md`
- [x] 1.2 `lib/Service/Access/FieldRoleRuleDeclaration.php`: one declaration,
  two published shapes, opposite polarity, never derived from each other.
- [x] 1.3 `lib/Service/Access/CaseFieldRoleProjector.php`: the property half,
  merging per property with a ledger in the schema configuration so a rule can
  come off again; re-applied after the register import.
- [x] 1.4 `CaseStateFieldRuleProjector::statesOf()` folds the role rules into
  every state the case type owns, so one writer owns the states block.
- [x] 1.5 vitest: no role branch on these fields under `src/` (D-2), with a
  control proving the sweep can fail.
- [x] 2.1 `tests/e2e/field-rules.spec.ts`, written and tagged. Two accounts in
  two groups read one case: the probe is the restricted account, the control is
  the holding account. Not run in this lane.

## What was decided against the proposal

- **The rules are authored per case type, not shipped on the `case` schema.**
  The proposal read as five static rules in the register JSON. Shipping those
  would restrict `confidentiality`, `competentAuthority` and `statutoryTerm` on
  every instance on upgrade, for handlers who can change them today. The five
  fields survive as the worked example, in the tests and the e2e fixture.
- **`required` is not a role rule.** Requiring a field of one role and not
  another is a rule about the work, and the status half owns it per state.
- **`documentPresent` stays unpublished**, as the status half said: a document
  hangs off the case as a related object and OpenRegister's condition document
  cannot see it.

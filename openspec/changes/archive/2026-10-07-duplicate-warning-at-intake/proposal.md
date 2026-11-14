---
kind: config
depends_on: []
---

# Proposal: duplicate-warning-at-intake

Competitor gap register, row 2.24 "Duplicate detection at intake"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner openregister, slug `duplicate-warning-at-intake
(dossiq)`, size S. This is dossiq's half; the detection is OpenRegister's
declared dedup rule set.

## Why

Nothing on the intake path asks whether this case already exists.
`grep -ril "duplicate\|duplicaat" lib/` finds nothing there. The same
applicant with the same subject in the same week becomes a second case,
and `case-merge` is the repair for a mistake a warning would have
prevented.

The best competitor in the register: Zammad 7, core
`admin.ticket_duplicate_detection` with a permission level
(`_round3/compare/proposed-rows.md`).

## What changes

- The `case` schema declares dedup rules in the `duplicate-detection`
  vocabulary: same requester and case type within 30 days; same address
  and case type; subject similarity above a threshold.
- The New case form (`#MyWorkHome` header action and `#Cases`) shows the
  matches as a warning panel before save, with a link to each.
- `caseType.duplicatePolicy`: `warn` (anyone may continue) or `block`
  (only `dossiq-coordinators` may continue), Zammad's permission level as
  a case-type setting.
- The portal intake contributes the same rules, so portaliq warns the
  applicant with the platform's RBAC-scoped answer (the applicant sees
  only their own matches).

## Ownership

dossiq builds the declaration, the policy and the form panel. It consumes
openregister `duplicate-detection` (spec exists) for the evaluation, and
portaliq's contribution contract (ADR-046) for the portal half.

## ADRs

- Company ADR-031: rules are declared.
- Company ADR-023: the evaluation is RBAC-scoped by the platform; the
  policy is dossiq's action rule.

## Capabilities

- Modified: `friendly-case-create-form`: the form warns about likely
  duplicates.

## Impact

`lib/Settings/dossiq_register.json` (`case` dedup rules,
`caseType.duplicatePolicy`); the create form's warning panel;
`lib/Portal/PortalContributionProvider.php`; one e2e spec.

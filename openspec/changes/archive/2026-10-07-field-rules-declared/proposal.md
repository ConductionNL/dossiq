---
kind: config
depends_on: []
---

# Proposal: field-rules-declared

Competitor gap register, row 13.8 "Field-level permissions"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner openregister, slug `field-rules-declared
(dossiq)`, size S. This is dossiq's half; the mechanism is OpenRegister's
`row-field-level-security`. Sibling of `sensitive-fields-declared` (row
5.6): that one is personal data behind an extra group with an audited
reveal; this one is ordinary fields hidden or read-only per role.

## Why

dossiq declares field rules on shares and nowhere else.
`caseShare.fieldExclusions` hides fields from a partner; a handler sees
and edits every field of the case, including `qualityScore`,
`confidentiality` and `competentAuthority`, which a quality officer or a
coordinator owns.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/shared/repositories/attribute_acl.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- Per-role field rules declared on the `case` schema: read-only for
  handlers on `confidentiality`, `competentAuthority`, `statutoryTerm`;
  hidden for handlers on `qualityScore` and `qualityStatus`; editable for
  the coordinator and quality groups.
- The case form and the data panel follow the rules without dossiq code:
  the platform filters the payload and the form.
- Rules by state (row 11.25, openregister `field-rules-by-state`) are
  consumed when they land; this change declares by role only.

## Ownership

dossiq builds the declarations. It consumes openregister
`row-field-level-security` (spec exists). Rules by state are to be
specified in openregister under `field-rules-by-state`.

## ADRs

- Company ADR-023 rule 1: no app-side field filtering.
- Company ADR-031: declared, not coded.

## Capabilities

- Modified: `security-hardening`: field rules per role on the case.

## Impact

`lib/Settings/dossiq_register.json` (`case` field rules); a repair step
for the two groups if absent; one e2e spec. No PHP filtering.

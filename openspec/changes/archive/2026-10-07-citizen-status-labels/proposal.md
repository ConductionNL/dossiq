---
kind: config
depends_on: []
---

# Proposal: citizen-status-labels

Competitor gap register, row Q6.19 "does the citizen see a status
vocabulary of their own, mapped from the internal one"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S.

## Why

A citizen reads "Toets register B" on the public status page. `statusType`
carries `name`, `description`, `order`, `isFinal`, `colour`,
`hiddenInLists` and `checklist`, and one vocabulary serves the handler and
the applicant. `#PublicStatus` (`PublicStatusPage`) renders the internal
name. ZGW gives the statustype a `statustekst` for exactly this, and
dossiq's mapping does not carry it.

The best competitor in the register: JSM Cloud (documented), a status
name to show the customer per request type; no driven yes yet
(`_round4/compare/proposed-rows-batch8.md`).

## What changes

- `statusType.publicLabel` and `statusType.publicDescription`, optional;
  empty means the internal name is shown, so nothing changes until an
  author fills them in.
- `#PublicStatus` renders the public label and description.
- The portal contribution (`portal-contribution`, ADR-046) hands portaliq
  the public label as the status a citizen sees.
- The case type authoring surface shows the two fields on the status row
  (REQ-CT-10's status editor).
- The ZGW mapping writes `publicLabel` to `statustype.statustekst`.

## Ownership

dossiq builds the property, the two readers and the mapping: the words a
citizen reads for a status are the statustype's. It consumes portaliq's
contribution contract (ADR-046, dossiq `portal-contribution` spec,
shipped) for the portal half.

## ADRs

- Company ADR-046: the portal reads the contribution; dossiq renders
  nothing in portaliq.
- Company ADR-031: the label is declared data.
- Company ADR-025: the label is register content per instance, not an
  l10n string.

## Capabilities

- Modified: `case-types`: a status has a public label.

## Impact

`lib/Settings/dossiq_register.json` (`statusType`); `src/views/`
`PublicStatusPage`; `lib/Portal/PortalContributionProvider.php`;
`lib/Service/Zgw/` mapping; `tests/e2e/case-type-status-authoring.spec.ts`
extended; one e2e spec.

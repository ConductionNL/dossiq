---
kind: config
depends_on: []
---

# Proposal: knowledge-base-on-the-case

Gap scan of the parity ledger, 2026-09-18, pack d, row 11.26 "Knowledge base
with per-role visibility". Added from round 3 against GLPI 11.0.8 and Zammad
7.1.3, both rated `yes`. dossiq rated `no`.

## Why

A handler who meets an unusual bezwaar has nowhere to read how this
municipality handles it. The work instruction lives in a Word file on a share,
or in the head of the colleague who is on holiday. GLPI and Zammad both put a
knowledge base next to the ticket, and both scope an article to a role.

dossiq needs to build none of it. Nextcloud Collectives is a wiki whose pages
are scoped to a team, which is exactly per-role visibility, and OpenRegister
already ships `collectives` as one of its app agnostic integration leaves.
OpenRegister also ships `integration-xwiki`, status done, for a municipality
that already runs XWiki, where XWiki's own permissions govern access.

dossiq declares neither. `case.linkedTypes` is mail, calendar, forms, photos,
maps, shares and decidesk-decisions, and the open `leaf-integrations` change
adds talk and deck. The only hit for a knowledge base anywhere in dossiq is a
sentence in an AI prompt.

## What changes

- `collectives` joins `case.linkedTypes`, so a case can link the page that
  explains it.
- `caseType.knowledgeBasePage` references the collective page that is the work
  instruction for that type. The case page shows it without anybody linking
  anything, because the instruction belongs to the type, not to the case.
- A Knowledge tab on the case renders the linked pages through the existing
  leaf, read only. Editing happens in Collectives, which is where the history,
  the versions and the team live.
- Visibility is the team's. A collective is scoped to a Nextcloud team, and
  `roleType.ncGroupId` already names the group behind a role, so a role sees
  an article when its group is in the team. dossiq enforces nothing and
  copies nothing.
- `xwiki` joins `case.linkedTypes` too, for the municipalities that run it.
  The two leaves are alternatives, and neither is required.

## Ownership

Nextcloud Collectives is the knowledge base. OpenRegister owns the leaves and
their visibility. dossiq declares which leaves a case carries and which page a
case type points at.

## ADRs

- Company ADR-022: consume the leaf, build no second wiki.
- Company ADR-031: the reference is schema data.

## Capabilities

- Added: `case-knowledge-base`: the work instruction for this case type is on
  the case.

## Impact

`lib/Settings/dossiq_register.json` (`case.linkedTypes`,
`caseType.knowledgeBasePage`), `src/registry.js` (the leaf tab),
`src/manifest.json` `#CaseDetail` and `#CaseTypeDetail`, one vitest test, one
e2e spec. No PHP. Requires the `collectives` app; the tab SHALL be absent
when it is not installed.

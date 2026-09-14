---
kind: code
depends_on: []
---

# Proposal: live-updates-on-the-case-page

Competitor gap register, row 2.20 "Live updates on the case page"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner openregister, slug `none needed (verify the
spec is wired, re-rate)`. Re-read on 2026-09-13
(`docs/research/competitor-gap-re-read-2026-09-13.md`): verified, and it
is not wired, so the re-read opens this change as the one new gap. Size S.

## Why

The spec exists and the page does not use it.
`openspec/specs/realtime-updates-ui/spec.md` requires store-rendered views
to subscribe to live updates for their scope. `liveUpdatesPlugin` is
referenced in `src/views/workflow-board/WorkflowBoard.vue:176` and
`src/views/cases/DeelzaakDetail.vue:159` and nowhere else;
`#CaseDetail/case-flow-runs` polls every 15 seconds (`pollSeconds: 15`,
`src/manifest.json:1321`) and the rest of the case page refreshes when you
do.

The best competitor in the register: GZAC/Valtimo,
`frontend/projects/valtimo/sse/` (`_round2/compare/M1-functionality.md`).

## What changes

- The case store used by `#CaseDetail` installs `liveUpdatesPlugin` for
  the viewed case, so the header, the panels and the terms refresh on the
  object's update event.
- `case-flow-runs` drops `pollSeconds` and refreshes on the run events the
  plugin delivers.
- Nothing else: the transport (notify_push) and the plugin are the
  platform's.

## Ownership

dossiq builds two subscriptions and removes one poll. It consumes
OpenRegister's notify_push transport and nextcloud-vue's
`liveUpdatesPlugin`, both shipped.

## ADRs

- Company ADR-071: the shared frontend runtime provides the plugin.
- Company ADR-080: the store plane is where the subscription lives.

## Capabilities

- Modified: `realtime-updates-ui`: the case page subscribes.

## Impact

`src/store/` case store; `src/manifest.json` `#CaseDetail` `case-flow-runs`;
vitest; the existing e2e spec for the case page gains a scenario.

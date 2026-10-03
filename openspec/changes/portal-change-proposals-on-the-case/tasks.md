# Tasks: portal-change-proposals-on-the-case

Tier: V1. Kind: config. Half: portaliq `change-proposal-queue`; dossiq row 2.21.

## 1. The resident's proposal

- [ ] 1.1 `proposeCaseChange` in `citizenActions()` and its id in `mijnZaken.rowActions` (design D1).
  - unit: `PortalContributionProviderTest` asserts the action, `proposable` `['title', 'description']`, and the row action id

## 2. The handler's queue

- [ ] 2.1 `portaliq-change-proposal-queue` in `case.linkedTypes` through `lib/Settings/register.d/77-portaliq-change-proposals.json`, and the integration widget on `CaseDetail` (design D2).
  - unit: a schema test asserts the linked type; `npm run check:manifest`

## 3. Live check

- [ ] 3.1 With portaliq T05 and T06 landed: a resident proposes a new title from the portal, the handler sees it under "Voorgestelde wijzigingen" on the case and accepts it, and the case carries the new title. Record in the PR whether the portal showed the propose button (design D3).

## 4. Validation

- [ ] 4.1 `openspec validate portal-change-proposals-on-the-case --strict`, `npm run lint`, `composer check:strict` once before push.

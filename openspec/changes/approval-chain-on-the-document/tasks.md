# Tasks: approval-chain-on-the-document

Tier: MVP. Kind: code. Size M. Parity ledger row 3.13. The engine and the
surface are decidiq's `document-approval-chain-leaf`
(`ConductionNL/decidesk`, nine of ten tasks done on `parity/round2`). Nothing
below builds a route, a step or an action.

## 1. Check the leaf is actually on the page

- [ ] 1.1 Open a real case page with decidiq installed and read the network
  log: does the bundle that registers `decidiq-approval-chain` load, and is
  the id in `window.OCA.OpenRegister.integrations` by the time the sidebar
  renders? A cross-app leaf that never registers renders the same notice as
  an uninstalled decidiq, so this is checked before anything is built and the
  finding is written down either way.
  - If it does not register, this change stops here and the finding goes to
    decidiq, because a wrapper around an absent leaf is a surface that can
    only ever say "unavailable".

## 2. The surface

- [ ] 2.1 `src/components/tabs/ApprovalChainLeafTab.vue`: the wrapper, built
  the way `BesluitvormingLeafTab.vue` is built, resolving the leaf at render
  time and handling the component and the mount render modes.
  - `@spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md`
  - `tests/vitest/approvalChainLeafTab.spec.js`
- [ ] 2.2 `src/registry.js` and `src/manifest.json`: the registry key and the
  placement on the document record the file row resolves to, with the
  `informatieobject` register, schema and object id forwarded as context.
- [ ] 2.3 The unavailable notice, asserted with the leaf absent from the
  registry. The test drives the absent case first, because that is the state
  every instance without decidiq is in.

## 3. Reading the outcome

- [ ] 3.1 The per-row marker on the Files tab, read from decidiq's route
  state on load and never stored on the document.
  - `tests/vitest/documentRowApprovalMarker.spec.js`
- [ ] 3.2 `lib/Service/Zaakdossier/InformatieobjectStatusLifecycle.php`:
  refuse `definitief` while a route is open, naming the route and its step;
  offer the transition once the route completed approved, naming the route as
  its ground.
  - `tests/Unit/Service/Zaakdossier/InformatieobjectApprovalGuardTest.php`
- [ ] 3.3 `tests/e2e/approval-chain-on-the-document.spec.ts`: the reviewer
  path, the bystander path and the refused lock. Drive the refusal before the
  approval, so a guard that stops working reddens the test rather than
  passing quietly.

## 4. What this change does not touch

- [ ] 4.1 The beschikking path (`/api/beschikkingen/{id}/akkoord`,
  `/onderteken`) stays as it is. It is a statutory sign-off with its own
  document and its own signature, not a review route, and folding the two
  would put a reviewer's approve button on a besluit.

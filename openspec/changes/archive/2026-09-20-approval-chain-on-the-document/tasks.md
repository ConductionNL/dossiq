# Tasks: approval-chain-on-the-document

Tier: MVP. Kind: code. Size M. Parity ledger row 3.13. The engine and the
surface are decidiq's `document-approval-chain-leaf`
(`ConductionNL/decidesk`, nine of ten tasks done on `parity/round2`). Nothing
below builds a route, a step or an action.

## 1. Check the leaf is actually on the page

- [x] 1.1 Open a real case page with decidiq installed and read the network
  log: does the bundle that registers `decidiq-approval-chain` load, and is
  the id in `window.OCA.OpenRegister.integrations` by the time the sidebar
  renders?
  - ANSWERED FROM THE REGISTRATION, NOT A NETWORK LOG, because this lane's
    browser service is down (`ConnectionRefused` on all three connections) and
    the phase runs no Playwright. Read against decidiq `parity/round2`,
    `lib/Listener/RegisterApprovalChainLeafListener.php`:
    `loadStrategy: LOADS_VIA_OWN_SCRIPT`, and the listener's own docblock says
    the registration ships in `decidiq-integration-init.js`, added on EVERY
    page by `Util::addInitScript` in `Application::boot`, so there is no
    `decidiq-leaves.js` and the absence of one is not evidence the surface is
    dark. That is the exact trap this task was written to catch, and decidiq
    had already written the answer down.
  - Two more facts the registration settles, both of which the wrapper had to
    get right: `renderMode: RENDER_MODE_MOUNT`, so the mount branch is the one
    that normally renders; and `kinds: [KIND_RENDER_SURFACE]` with a null
    IntegrationProvider, so there is no app-local store behind the leaf and
    dossiq has nothing to serve it.
  - The e2e spec still probes the live instance: its first test asserts the
    notice on an instance WITHOUT decidiq and its fourth asserts the notice is
    absent on one with it, so a leaf that silently stopped registering reddens
    rather than reading as an uninstalled decidiq.

## 2. The surface

- [x] 2.1 `src/components/tabs/ApprovalChainLeafTab.vue`: the wrapper, built
  the way `BesluitvormingLeafTab.vue` is built, resolving the leaf at render
  time and handling the component and the mount render modes.
  - `@spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md`
  - `tests/vitest/approvalChainLeafTab.spec.js`
- [x] 2.2 `src/registry.js` and `src/manifest.json`: the registry key and the
  placement on the document record the file row resolves to, with the
  `informatieobject` register, schema and object id forwarded as context.
  - NO REGISTRY KEY, and that is deliberate. `src/registry.js` is for
    components a MANIFEST names, and `tests/vitest/registryOrphans.spec.js`
    fails on a registered component no page opens. This one is a child of
    `DocumentMetadataDialog`, which the file row already opens, so it is
    imported directly like any other child component.
  - The section renders only when the dialog has resolved a record id. A file
    with no informatieobject behind it would otherwise hand the leaf an empty
    id, and the leaf would answer a timeline of nothing, which reads as
    "nobody has approved anything".
- [x] 2.3 The unavailable notice, asserted with the leaf absent from the
  registry. The test drives the absent case first, because that is the state
  every instance without decidiq is in.
  - Four absent cases, not one: no registry at all, a registry whose `get()`
    answers nothing, a registry object with no `get()` method, and a
    half-registered mount leaf that ships no `mount` function. The last is the
    one that would otherwise render a host that can never mount anything.

## 3. Reading the outcome

- [x] 3.1 The per-row marker on the Files tab, read from decidiq's route
  state on load and never stored on the document.
  - `tests/vitest/documentRowApprovalMarker.spec.js`
  - `GET /api/informatieobjecten/approval-markers?ids=…` answers the whole tab
    in ONE call: the Files tab has one row per document and a per-row question
    is one round trip per row on a case with forty of them. Every id is guarded
    with the same readability check a single read uses, and an id the caller
    may not read is DROPPED rather than refused, because refusing would turn
    one unreadable document into a tab with no markers at all.
  - The column is declared on the files widget beside Sender, Recipients and
    Scan, and is INERT for the same reason those three are: `CnFilesBrowser`
    ignores a column type it cannot read, and `files-browser-columns` has not
    landed. It is declared now so the value has somewhere to go, and the
    endpoint and the formatter are real and tested meanwhile.
  - A test asserts NO dossiq schema carries a copy of the route state. A copy
    is written once and disagrees with the route the first time somebody
    approves from decidiq's own page.
- [x] 3.2 `lib/Service/Zaakdossier/InformatieobjectStatusLifecycle.php`:
  refuse `definitief` while a route is open, naming the route and its step;
  offer the transition once the route completed approved, naming the route as
  its ground.
  - `tests/Unit/Service/Zaakdossier/InformatieobjectApprovalGuardTest.php`
  - 🔴 THE STATUS IS `final`, NOT `definitief`. The spec and the proposal both
    say `concept` / `definitief` / `gearchiveerd`, which is what a ZGW reader
    calls them and NOT what this register stores:
    `InformatieobjectStatusLifecycle::VALID_STATUSES` is `draft`, `final`,
    `archived`. A guard written against the Dutch spelling would never fire,
    because no document's status is ever `definitief`. A test pins the three.
  - The guard reads decidiq through `FleetAppId::getService()`, which asks for
    both identities newest first: a container `get()` on a class that is not
    there THROWS, and `isInstalled('decidesk')` against an instance running
    `decidiq` answers false without erroring, which would make this guard
    silently permit everything.
  - THREE PERMISSIVE ANSWERS ARE KEPT APART: no decidiq, no route, and a route
    that CLEARED. All three end in `final` and only the third is an approval,
    and the marker needs the difference.
  - Mutation checked: removing the guard call reddens the assertion that a
    document in an open route was made final.
- [x] 3.3 `tests/e2e/approval-chain-on-the-document.spec.ts`: the reviewer
  path, the bystander path and the refused lock. Drive the refusal before the
  approval, so a guard that stops working reddens the test rather than
  passing quietly.

## 4. What this change does not touch

- [x] 4.1 The beschikking path (`/api/beschikkingen/{id}/akkoord`,
  `/onderteken`) stays as it is. It is a statutory sign-off with its own
  document and its own signature, not a review route, and folding the two
  would put a reviewer's approve button on a besluit.
  - Untouched: no file under `lib/Service/Beschikking` or its routes is in
    this diff.

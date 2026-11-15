# Tasks: digital-post-consumes-integriq

Tier: V1. Kind: capability. Row 6.6. Consumes integriq#2062
(`DigitalPostSendRequestedEvent`, `DigitalPostDeliveredEvent`,
`digitalPostMessage`).

## 0. What this change inherited, and from where

This change and `digital-post-reaches-integriq` are the same hole, found by
two lanes in two packs on the same night: row 6.6 "Digital post to citizens"
and row 12.9 "Digital post or berichtenbox". They were built as one thing
rather than twice, and the backend half merged first as **dossiq#2996**.

**Inherited from #2996, already shipping, not rebuilt here:**

- `IntegriqAdapter`, which dispatches integriq's typed send command and reads
  its result slot. A tracked message id is a send; a structured refusal, an
  unanswered slot, a handled event with no tracked message, a missing
  integriq and a missing seam are each a refusal carrying its own code.
- `SubstitutableAdapterRegistrar`: the default adapter is the integriq one,
  the mock is selectable by name, and `integriq` and `mock` are shorthands.
- `DigitalPostDeliveredListener` and its registration, covering task 2.1 and
  2.2 of this file, plus `BerichtenboxService::recordDeliveryStatus`.
- `BerichtenboxService::sendMessage` recording a refusal as not sent.
- The measurement for task 2.3, and the `getReadStatus`/`pollReadStatus`
  change that came out of it.

**Where the two changes disagreed, and how it was resolved.** This one asked
for `lib/Service/BerichtenboxAdapter/` to be deleted and the mock removed
outright; the other asked for the seam to be kept and the mock left
selectable for development. The seam is kept, because once the DEFAULT
refuses, a mock that has to be named on purpose is not the failure this row
is about. The requirement text is amended to say so rather than leaving a
spec that reads as though a directory had gone.

## 1. The transport moves to integriq

- [x] 1.1 Send by dispatching `DigitalPostSendRequestedEvent` and reading its
  result slot. **Inherited from dossiq#2996** (`IntegriqAdapter`), seven unit
  arms over a doubled dispatcher.
  - `@spec openspec/changes/digital-post-consumes-integriq/specs/berichtenbox-integration/spec.md`
  - What this change adds on top: `tests/Unit/Service/BerichtenboxServiceRefusalTest.php`,
    which asserts what is STORED rather than what the adapter returned. A
    refusal writes `status: refused`, a null `externalMessageId`, a null
    `sentAt` and the reason in `lastError`, and its timeline entry is INTERNAL
    naming that reason. A tracked id writes `sent` and a PUBLIC entry, which
    is the arm without which a service that refused everything would pass.
- [x] 1.2 The mock is no longer the silent default. **Inherited.** It is not
  deleted; see section 0.
- [x] 1.3 An instance without integriq refuses and names it, probed through
  `FleetAppId` rather than a literal app id. **Inherited**, with a unit arm
  installing integriq under `openconnector` alone and asserting the send
  still goes through.

## 2. Delivery and read status come back

- [x] 2.1 `lib/Listener/DigitalPostDeliveredListener.php`. **Inherited from
  dossiq#2996**, with the failed, simulated, wrong-shape and throwing arms.
- [x] 2.2 Bound by fully qualified name beside `DeliveryConcludedEvent`.
  **Inherited.**
- [x] 2.3 Retire `BerichtenboxReadStatusJob` if the status event covers what
  it polled.
  - **measured, in dossiq#2996:** `read` IS one of integriq's five statuses
    (`DigitalPostResult::STATUSES`) and the event fires on every change, so
    on an integriq instance the job is redundant. It is KEPT, because the
    mock is still selectable and polls for its answer, and retiring the job
    belongs to the change that retires the mock. What changed instead is that
    `IntegriqAdapter::getReadStatus` answers `unknown: true` and
    `pollReadStatus` returns on `unknown` without moving the record.

## 3. The compose surface becomes reachable

- [x] 3.1 `src/registry.js`: register `BerichtenboxComposeDialog` as a modal.
  It had never been registered, which is why nothing could open it: the only
  occurrence of its name in the whole repository was its own `name:` line.
  - **THIS LANE TOOK THAT FILE.** Another lane was deciding whether to rehome
    or delete it. It is rehomed here, onto the CaseDetail header, because the
    page that sends a letter about a case is the case.
- [x] 3.2 `src/manifest.json`, page CaseDetail: a Send digital post header
  action, `type: open-modal` onto that dialog.
  - `visibleWhen` tests `initiatorType eq person`, a materialised field on the
    CASE and therefore readable by a local predicate, exactly as `case-reopen`
    tests `isFinalStatus`. Only a person has a digital post box, and a company
    case would otherwise offer a gesture whose recipient field can only be
    filled with a KvK number.
  - It is NOT gated on integriq being installed, deliberately. An instance
    without integriq refuses the send and names the missing app, which is a
    sentence a handler can act on; a button that is simply absent is a
    capability nobody can ask about.
  - The dialog gained `open` beside its older `show`, because `open` is the
    prop every other registry modal on this page is mounted with and an action
    declaring the other one would have mounted a dialog that renders nothing.
    `caseId` arrives as the unresolved `@objectId` token and the route
    answers, as it does for `BeschikkingComposerDialog`.
  - The recipient is read off the case's `initiatorSourceId`, and only for an
    `initiatorType` of person. A read that fails leaves the field empty rather
    than guessing: a wrong BSN addresses a letter to somebody else.
- [x] 3.3 The dialog shows the refusal in the words integriq sent, and does
  NOT close on one. `sent` is emitted only for a send carrying a tracked
  message; a 400 lands in the catch and shows `response.data.error`, and a
  200 carrying `refused` is treated as a refusal too, which is reachable the
  moment that status code is softened.

## 4. Verification

- [x] 4.1 `tests/e2e/digital-post.spec.ts`: open a case, press Send digital
  post, read the recipient the case filled in, send, and read the refusal
  while the dialog stays open; then assert over the API that no message on
  the case reads `sent` or carries an external id. A fourth arm probes the
  send endpoint anonymously, which is the least privileged principal that
  should be refused. Written and tagged, not run: there is no Playwright run
  on this box, and the live network leg is blocked on the three things
  integriq#2062 names.
- [x] 4.2 Mutation check: forcing `$refused` to false in
  `BerichtenboxService::sendMessage` reddens
  `assertNull($this->saved['sentAt'])` in "a refusal is never recorded as a
  delivery" and the internal-visibility assertion beside it. That is exactly
  "a refusal fell through as a successful send". Restored, green.
  - A second one on the frontend: `tests/vitest/berichtenboxComposeDialog.spec.js`
    asserts `emitted('sent')` is undefined on both refusal shapes, so a
    dialog that closed over a letter nobody received reddens there.
- [x] 4.3 `openspec validate digital-post-consumes-integriq --strict`.

## 5. What the closing pass found, and fixed

The contract was re-read from integriq `development` on 2026-09-20 rather
than trusted: `DigitalPostSendRequestedEvent`'s constructor takes the eight
positional arguments `IntegriqAdapter` passes, in that order, and carries
`isHandled`, `getRefusal` (keys `code` and `reason`) and `getMessageId`.
`DigitalPostDeliveredEvent` carries the six getters the listener reads.
integriq's `<id>` is already `integriq` on `development`, and `FleetAppId`
answers to both ids either way.

- [x] 5.1 **The integrations page reported a mock that was not answering.**
  `lib/Settings/connections.json` listed the empty string under the
  Berichtenbox `simulatedValues`, which was true while the default adapter
  was the mock and false the moment #2996 moved it. An administrator read
  "a mock adapter answers here" on an instance where nothing answered. The
  empty string is gone, the mock class and its `mock` shorthand stay.
- [x] 5.2 **`digital_post_source` existed in one file and nowhere else.**
  integriq's `DigitalPostService::sourceConfig()` returns null on the empty
  string before it looks anything up, so a send over an unset source can
  only come back refused, with integriq's sentence naming no key. The row
  now requires the key and names it in an `unconfiguredMessage`, exactly as
  the BRP row names `integration.brp.mode`, and `IntegriqAdapter` refuses an
  unset source itself rather than dispatching a letter that cannot go.
- [x] 5.3 Mutation checks re-run rather than inherited. Forcing the refusal
  branch of `BerichtenboxJournal::messageRecord` to `false` reddens
  `assertNull($this->saved['sentAt'])` at BerichtenboxServiceRefusalTest.php:231
  with `Failed asserting that '2026-09-20T08:23:27+00:00' is null`. Making
  `IntegriqAdapter::refusal()` answer `status: sent` with an id reddens
  `assertArrayNotHasKey('messageId', $result)` on all three refusal arms.
  Replacing the new source guard with `false` reddens
  `assertSame('digital-post-source-unset', $result['code'])` at
  IntegriqAdapterTest.php:267.

## Inherited debt found and not fixed here

`src/services/berichtenboxApi.js` `pollReadStatus()` POSTs to
`/api/berichtenbox/poll/{messageId}`; `appinfo/routes.php` routes GET
`/api/berichtenbox/messages/{messageId}`. Neither the verb nor the path
matches, so that function has never reached its endpoint. It is on a line
this change did not touch and belongs to the debt sweep.

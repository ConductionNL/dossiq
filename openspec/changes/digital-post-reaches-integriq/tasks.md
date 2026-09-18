# Tasks: digital-post-reaches-integriq

Tier: V1. Kind: code. Row 12.9.

- [x] 1.1 `lib/Service/BerichtenboxAdapter/IntegriqAdapter.php` implementing
  `BerichtenboxAdapterInterface`: dispatch integriq's digital post send event
  and return the reference it answers with.
  - `@spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md`
  - The event contract was read from integriq at `development` on 2026-09-18,
    not from the local checkout: `apps-extra/openconnector` is at #2022 and
    the digital post seam landed in #2062, so the checkout on this box does
    not carry the classes at all. The result slot carries either the tracked
    message id or a structured refusal, never both, and is never left empty
    on a handled event.
- [x] 1.2 Resolve integriq through `OCA\Dossiq\Support\FleetAppId`
  (`isEnabledForUser`, `resolveClass`), never through a literal app id or a
  literal class name. Confirmed by a test that installs integriq under
  `openconnector` alone and asserts the send still goes through, rather than
  answering the integriq-missing refusal.
- [x] 2.1 `SubstitutableAdapterRegistrar`: `berichtenbox_adapter: 'integriq'`
  selects the new adapter, and `'mock'` selects the old one. Both are
  shorthands expanded into the class they name and written back, so the value
  an administrator reads in `occ config:app:get` is the class that is running.
  A value that is neither is left exactly as it is, including a wrong one:
  `ConfiguredAdapter` is what says a named class cannot be used, and it says
  so in the log.
  - **THE DEFAULT MOVED, AND THAT IS THE CHANGE.** It used to be
    `MockAdapter`, whose own class comment says it simulates sending without
    external calls and whose `sendMessage` answers `status: sent` with a
    generated id. So an instance that had simply never set this key reported
    every letter to a citizen as delivered, and the only trace was a warning
    in the boot log. The default is `IntegriqAdapter` now. This is the one
    place this change goes further than its own proposal, which said the mock
    stays the fallback: `digital-post-consumes-integriq` asks for the mock to
    be removed outright, and a default that refuses and names what is missing
    is the union of the two that keeps the mock reachable for development.
- [x] 2.2 The adapter refuses with a named error when integriq is absent or
  its digital post seam reports itself unavailable. It never returns the mock.
  - Four refusal codes, each naming what is wrong: `integriq-missing`,
    `integriq-seam-missing`, `unhandled` and `no-tracked-message`. The last is
    the one integriq's contract says cannot happen, and it is a refusal here
    because treating a handled event with no tracked message as a send is
    exactly how a letter that went nowhere reads as delivered.
  - A refusal carries NO `messageId` key at all. `BerichtenboxService` writes
    `$result['messageId'] ?? null` onto the stored message, so an empty string
    there would have been an external id of its own kind.
- [x] 3.1 `lib/Listener/DigitalPostDeliveredListener.php` for integriq's
  delivered event, updating the stored message's status on the case through
  the new `BerichtenboxService::recordDeliveryStatus` and writing a timeline
  entry. The entry is INTERNAL: a delivery receipt is about our sending, not
  about what the citizen was told, and the portal already shows them the
  message itself.
- [x] 3.2 Confirm which event integriq actually dispatches on delivery, and
  name it in the listener.
  - **measured, from integriq `development` on 2026-09-18:**
    `OCA\Integriq\Event\DigitalPostDeliveredEvent`, whose own docblock says
    "the name says delivered because that is the status anyone waits for, but
    it is dispatched on every change, including `failed`". Its getters are
    `getMessageId`, `getStatus`, `getRequestedBy`, `getPreviousStatus`,
    `isSimulated` and `getLastError`. The status vocabulary is
    `DigitalPostResult::STATUSES`: queued, sent, delivered, read, failed.
  - The listener is bound by FQN string behind `class_exists`, the way the
    other three integriq listeners are, so an instance without integriq boots
    exactly as it does today and this class is never constructed. A test
    asserts the constant equals the class name rather than trusting it: a
    listener bound to an event nobody fires is indistinguishable from one that
    works.
  - **The read-status poller stays, and here is the measurement.** `read` IS
    one of integriq's five statuses and the event fires on it, so on an
    integriq instance `BerichtenboxReadStatusJob` is redundant. It is kept
    because the mock is still selectable and polls for its answer, and
    retiring the job belongs to the change that retires the mock. What changed
    instead is that `IntegriqAdapter::getReadStatus` answers `unknown: true`
    rather than a fabricated flag, and `pollReadStatus` now returns on
    `unknown` without moving the record: falling through would have walked
    every such message into `unread_flagged` after seven days on the strength
    of a question nobody asked.
- [x] 4.1 Unit tests: refusal when integriq is absent (and nothing is
  dispatched), refusal on an unanswered slot, refusal on a handled event with
  no tracked message, a structured refusal carrying integriq's own reason, a
  reference stored on success with the envelope naming dossiq, integriq
  resolving under its old id, and a read status answered as unknown.
  `DigitalPostDeliveredListenerTest` covers the delivered, failed, simulated,
  wrong-shape and throwing arms. Thirteen tests in all, doubles built with
  `onlyMethods`.
  - Two integriq event stubs are added under `tests/Stubs/Integriq/Event/`,
    mirroring the real constructors verbatim and loaded by
    `tests/bootstrap.php` only when the real classes are absent. Without them
    `resolveClass` could only ever answer null, every test would exercise the
    absent branch alone, and the branch that turns a handled event with no
    tracked message into a refusal could never be reached.
  - Mutation check: making `refusal()` return a fabricated
    `['messageId' => …, 'status' => 'sent']` reddens
    `assertArrayNotHasKey('messageId', $result)` on all three refusal arms.
    That is precisely "a refusal fell through as a successful send". Restored,
    green.
  - **The first draft of the adapter test doubled the wrong method and three
    tests passed on the wrong branch.** `FleetAppId` resolves the id with
    `isInstalled` before asking `isEnabledForUser`, so doubling only the
    second answered false at the first step and every arm returned
    `integriq-missing` while claiming to test the slot. It was caught because
    the refusal-code assertions name the code rather than only asserting that
    a refusal happened.

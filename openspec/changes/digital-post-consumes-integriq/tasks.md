# Tasks: digital-post-consumes-integriq

Tier: V1. Kind: capability. Row 6.6. Consumes integriq#2062
(`DigitalPostSendRequestedEvent`, `DigitalPostDeliveredEvent`,
`digitalPostMessage`).

## 1. The transport moves to integriq

- [ ] 1.1 `lib/Service/BerichtenboxService.php`: send by dispatching
  `OCA\Integriq\Event\DigitalPostSendRequestedEvent` and reading its result
  slot. A tracked message id is a send; a structured refusal is a refusal;
  the two never arrive together and the service must not invent a third
  case.
  - `@spec openspec/changes/digital-post-consumes-integriq/specs/berichtenbox-integration/spec.md`
  - unit over a doubled dispatcher: a tracked id records the message as
    sent; a refusal records it as not sent with the reason; an unanswered
    slot is treated as a refusal, not as a send
- [ ] 1.2 Delete `lib/Service/BerichtenboxAdapter/` and drop the
  `berichtenbox_adapter` binding from
  `SubstitutableAdapterRegistrar`. The mock goes with it. A fallback that
  reports a delivery is the failure this row is about, and keeping it
  "for development" keeps it on every instance that forgot to configure.
- [ ] 1.3 An instance without integriq refuses and names it. The probe goes
  through `FleetAppId` rather than a literal app id, for the reason that
  file already records: the fleet renames and a duck-typed lookup against a
  dead name returns false without erroring.
  - unit: with no integriq the send is refused and the reason names the
    missing app

## 2. Delivery and read status come back

- [ ] 2.1 `lib/Listener/DigitalPostDeliveredListener.php`: on integriq's
  delivered event, update the message on the case and write a timeline
  entry, so a sent letter and a delivered letter are told apart.
  - unit: a status change to `failed` is written as failed, not dropped
- [ ] 2.2 `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`: bind it by
  fully qualified name beside `DeliveryConcludedEvent`.
- [ ] 2.3 Retire `lib/BackgroundJob/BerichtenboxReadStatusJob.php` if the
  status event covers what it polled. Measure before deleting: a poller and
  an event that each cover half is worse than either alone.
  - measured: [pending]

## 3. The compose surface becomes reachable

- [ ] 3.1 `src/registry.js`: register `BerichtenboxComposeDialog` as a
  modal. It has never been registered, which is why nothing could open it.
- [ ] 3.2 `src/manifest.json`, page CaseDetail: a Send digital post header
  action, `type: open-modal` onto that dialog, offered on a case with a
  recipient who has a digital post address.
- [ ] 3.3 The dialog shows the refusal it got, in the words integriq sent.
  A handler who is told which credential is missing can ask for it; a
  handler told "sending failed" cannot.

## 4. Verification

- [ ] 4.1 `tests/e2e/digital-post.spec.ts`: open a case, press Send digital
  post, and on an unconfigured instance read the refusal naming what is
  missing. Assert the case does not report the message as sent.
- [ ] 4.2 Mutation check: make a refusal fall through as a successful send
  and assert the "a refusal is not a delivery" test reddens on its own
  assertion.
- [ ] 4.3 `openspec validate digital-post-consumes-integriq --strict`.

# Tasks: an-intake-message-opens-a-case

Tier: MVP. Kind: code. Size S. Found while scanning parity ledger rows 1.5
and 1.9. integriq's `intake-channels-beyond-mail` shipped the receiving half
and three adapters; nothing in dossiq answers the event it dispatches.

## 1. The listener

- [ ] 1.1 `lib/Listener/IntakeMessageRoutedListener.php`: read the event,
  open the case through the intake path, attach the files, answer the result
  slot with the case reference.
  - `@spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md`
  - `tests/Unit/Listener/IntakeMessageRoutedListenerTest.php`
- [ ] 1.2 `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`: register it
  guarded on the event class existing, the way `DeliveryConcludedEvent` and
  `FormSubmittedListener::EVENT` are registered.
- [ ] 1.3 The refusal path: an exception caught, logged with the message
  identifier, and turned into an empty slot rather than a thrown error.

## 2. The case that comes out

- [ ] 2.1 Reuse the intake conventions rather than restating them: the case
  type's initial status, the term start, the intake channel. Name the service
  that already does this for a Forms submission and call it, or say in the
  docblock why a second path is needed.
- [ ] 2.2 The correspondent: attached as requester when they resolve, carried
  as name and address when they do not, and never turned into a new person
  record.
  - `tests/Unit/Listener/IntakeMessageRoutedListenerTest.php`

## 3. Proving it in both directions

- [ ] 3.1 `tests/e2e/intake-from-a-channel.spec.ts`: a message routed at a
  case type, the case opened with its clock running, and integriq's message
  marked routed rather than held.
- [ ] 3.2 The refusal asserted first: a rule naming a case type triage
  refuses, held in integriq's review inbox with the reason. A test that only
  drives the happy path passes on a listener that answers every message yes.
- [ ] 3.3 An instance without integriq: the app boots, nothing registers,
  nothing is logged.

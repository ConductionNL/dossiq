# Tasks: an-intake-message-opens-a-case

Tier: MVP. Kind: code. Size S. Found while scanning parity ledger rows 1.5
and 1.9. integriq's `intake-channels-beyond-mail` shipped the receiving half
and three adapters; nothing in dossiq answered the event it dispatches.

## 1. The listener

- [x] 1.1 `lib/Listener/IntakeMessageRoutedListener.php`: read the event, hand
  it to the intake, answer the result slot with the case. It imports no
  integriq class and type hints nothing from it, so an instance without
  integriq boots unchanged.
  - `@spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md`
  - `tests/Unit/Listener/IntakeMessageRoutedListenerTest.php`
- [x] 1.2 `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`: registered
  guarded on the event class existing, the way the delivery seam is.
  - `tests/Unit/AppInfo/CrossAppListenerRegistrarTest.php`
- [x] 1.3 The refusal path: every failure caught, logged, and turned into an
  empty slot. An exception escaping the listener stops integriq's dispatch
  mid-batch, and the rest of that batch is other people's messages.

## 2. The case that comes out

- [x] 2.1 `lib/Service/Intake/ChannelIntake.php` opens it: the case type the
  rule mapped, the initial status that type declares, the start date through
  `CaseDateNormaliser` (the one date write path), the channel recorded.
  A case type this instance does not have is refused rather than opening a
  case with no type, which would sit outside every status lens with no
  deadline and look like working intake.
- [x] 2.2 The correspondent is written onto `initiatorSourceId` so
  `AcknowledgementService` can reach it, and no person record is created.
- [ ] 2.3 The attachments. NOT DONE HERE, and the spec says so rather than
  implying otherwise: putting a channel's files in the case folder is a seam
  with its own refusals (size, scan verdict, naming). Follow-up
  `channel-attachments-reach-the-case`.
- [ ] 2.4 Resolving a correspondent to a party dossiq already holds.
  Follow-up `channel-correspondents-resolve-to-parties`.

## 3. The three things that decide whether it is safe

- [x] 3.1 A refusal is readable on the intake log, with the channel, the
  message id, the outcome and the reason in a sentence. integriq's held state
  says "No app opened a case for this message", which was true while nothing
  listened; a refusal reaching only `nextcloud.log` would leave that sentence
  on the screen of the person who has to act on it.
- [x] 3.2 A second delivery opens no second case. The identity is the channel
  plus the channel's own id, NOT integriq's `intake_message` uuid, which is
  minted per delivery. Where the log cannot be read, the message is refused
  rather than opened without a duplicate check.
- [x] 3.3 The write runs as the system principal, and the payload is built
  from an allowlist. A mapped `assignee`, `status`, `grants` or `register` is
  dropped: the elevation exists because a webhook has no session, and it is
  not a licence for the message to decide who may read the case.

## 4. Proving it in both directions

- [x] 4.1 `tests/Unit/Listener/IntakeMessageRoutedListenerTest.php`, 11 cases.
  The doubled object service REFUSES an unelevated write, which is the least
  privileged principal this path actually runs as, so a test that never
  reached the elevation reddens rather than passing.
- [x] 4.2 Mutation-checked, four of them, each reddening the assertion itself
  rather than a setup line: bare filter keys to `filter[...]` reddens the
  duplicate assertion; the allowlist to a payload merge reddens
  `assertArrayNotHasKey('assignee')`; dropping the elevation reddens the
  opening assertions; a refusal that only logs reddens the outcome assertion.
- [x] 4.3 `tests/e2e/intake-from-a-channel.spec.ts`: the refusal on the page
  with its reason, the two outcomes told apart, and the stored entry naming
  the channel and the message. What it cannot prove is stated in the file:
  the opening needs integriq installed, and a spec that skipped without it
  would be a test that cannot fail.
- [x] 4.4 An instance without integriq registers nothing and logs nothing.

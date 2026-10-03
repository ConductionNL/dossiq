# Design: inbound-mail-filters

## D-1. One adapter, because none of this is OCP

Nextcloud Mail publishes no `OCP` interface. `AccountService`,
`MailManager`, `DkimService` and the events are all `OCA\Mail`, which
means dossiq is coupled to another app's internals and a Mail release can
move any of them.

So there is exactly one file that names an `OCA\Mail` symbol, a
`NextcloudMailGateway`, and everything else in dossiq talks to that. The
gateway is guarded by `IAppManager` the way the openregister dependency
already is, and when Mail is absent or its shape has moved, intake reports
that it is unavailable rather than throwing on a cron run.

One adapter also makes the coupling countable. A grep for `OCA\Mail`
outside that file is a finding.

## D-2. dossiq stops holding a credential

`InboundEmailJob::fetchUnreadBatch()` reads `email_imap_password` out of
appconfig and hands it to `imap_open()`. That is the thing D12 is about,
and the account moving to Nextcloud Mail means the credential leaves
dossiq rather than moving inside it.

So the upgrade deletes the stored password rather than migrating it. A
credential nobody uses is still a credential in a database backup.

## D-3. The pipeline is a declared list, and every message gets a verdict

Zammad's Postmaster pipeline is 30 filters in one directory, each a small
class, running in a known order. That shape is the reason it can be
reasoned about, and it is the shape to copy.

Each filter is named, ordered, and answers one of `accept`, `reject`,
`quarantine`, `forward` or `pass`. The pipeline records the filter that
decided and the reason. A message that reaches the end with no verdict is
accepted, and that default is written down rather than implied.

## D-4. Bounce and move are the two named actions, and Awb 2:3 is why

Awb 2:3 is the doorzendplicht: a document sent to the wrong administrative
body is forwarded to the right one. OTOBO calls the act Bounce
(`AgentTicketBounce.pm`) and it means send it on, not reject it.

So the two acts are distinct and both are recorded:

- **Bounce** sends the message on to another address, with the original
  intact, and records that we forwarded it and to where. It does not
  create a case, because the message was never ours.
- **Move** files the message in another folder of the same account,
  through `MailManager::moveMessage()`. It is a mailbox act, not a case
  act.

A bounce that is implemented as a reject loses the statutory duty. A
bounce that creates a case first and closes it records a case that never
existed.

## D-5. Nothing is dropped, ever

`InboundEmailJob` used to `continue` on an unmatched message with no log
line. It now files it, and a message that matches no case and no case type
lands in an intake inbox with its verdict on it.

C-intake-19's clause says the cost plainly: "a silently dropped aanvraag
is a missed statutory term". A message in an inbox somebody has to empty
is a visible problem. A message that vanished is not a problem anyone can
see.

## D-6. Four authentication results, and `unavailable` is not `pass`

Nextcloud Mail gives us DKIM (`DkimService`, `DkimValidator`) and a
reply-to mismatch check (`PhishingDetection\ReplyToCheck`). It gives us no
SPF and no DMARC, so dossiq reads the `Authentication-Results` header off
`MailManager::getSource()` and records what the receiving server found.

Four results, each `pass`, `fail`, `none` or `unavailable`:

- **SPF** and **DMARC** from the header, `unavailable` when there is none.
- **DKIM** from `DkimService`.
- **Threading**, which is ours alone: when `In-Reply-To` or `References`
  names a message id, `MailManager::getByMessageId()` says whether this
  account holds it. This is the Frappe failure the build plan measured,
  and no mail server can answer it for us, because only we know what we
  sent.

`unavailable` is a distinct value because a check nobody made must never
read like a check that passed. That is the whole lesson of a green test
that did not run.

## D-7. The policy is per case type, and refusing is a choice

dossiq does not refuse mail centrally. A melding openbare ruimte from an
unauthenticated sender is a normal Tuesday. A bezwaar from one is a
forgery risk with a statutory consequence.

So each case type declares what an authentication failure means to it:
accept, quarantine for a human to release, or refuse with a reply. The
default is quarantine, because a zaaksysteem that silently drops a bezwaar
is worse than one that accepts a forged one, and quarantine is the only
answer that is neither.

## D-8. The intake log holds the original

C-intake-29's clause: "'ik heb wel gemaild' is a weekly dispute and today
the answer is in a server log". So the log is a surface, not a log file:
every message the mailbox processed, its original source, the filter that
decided, the verdict, and the case it became or the reason it did not.

It holds personal data, so it obeys the case type's retention and is
readable only by the role that runs intake.

## D-9. Allow and block are one list read from two ends

C-intake-48's note says so: "An allowlist and a blocklist are the same
control read from two ends." Nextcloud Mail already has
`TrustedSenderService` and `TrustedSender`, so the allow half is that
list, read through the gateway rather than copied.

The block half is dossiq's, because blocking who may open a case is a case
decision and not a mail decision, and because a municipality blocking a
sender in dossiq must not silently stop that sender mailing a colleague.

## D-10. Server-side filtering stays available and stays optional

Some municipalities would rather do this in Sieve on their own mail
server, and Nextcloud Mail has `SieveService` and
`MailFilter\FilterBuilder` for exactly that. dossiq neither requires nor
replaces it. Where a message never reaches the folder, dossiq's pipeline
never sees it, and the intake log says the mailbox delivered nothing
rather than pretending it filtered something.

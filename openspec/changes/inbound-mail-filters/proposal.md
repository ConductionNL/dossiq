---
kind: code
depends_on: []
---

# Proposal: inbound-mail-filters

Round 4 discovery, cluster 25 "Mail intake that can be trusted"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Seven candidates, nine
passers, seven driven, proving system osTicket. Owner dossiq, size M,
wave 1. Decision D12.

## Why

dossiq polls a mailbox with `imap_open()` and a password read out of
appconfig (`lib/BackgroundJob/InboundEmailJob.php:328-355`,
`email_imap_password`), matches a bracketed tag in the subject
(`CASE_NUMBER_PATTERN`), and checks nothing else.

Two consequences, and the second is the one that matters.

A gemeente mailbox gets more auto-replies than messages, and every one of
them walks the same path as a real aanvraag. A bounce, an out-of-office
and our own notification coming back are indistinguishable from a
bezwaar.

And the sender is authenticated nowhere. `build-plan.md` states it as the
second of its three non-row findings: "`InboundEmailJob` matches a
bracketed case tag and checks nothing else, so a bezwaar can be filed on
somebody else's case. Frappe's version of this was measured: a forged
`In-Reply-To` landed one customer's mail on another's case."

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-intake-1 | must | partial | a bounce, an auto-reply and your own notification coming back do not open a case |
| C-intake-11 | must | no | a misdirected message is sent on to the right address, and that is recorded |
| C-intake-19 | must | no | a request whose type maps to no case type lands in an inbox, so nothing is dropped |
| C-intake-29 | must | no | every message the mailbox processed is logged with its original and its verdict |
| C-intake-31 | must | no | inbound mail whose sender cannot be authenticated is refused or quarantined |
| C-intake-26 | should | no | an intake is classified as junk from a rule an administrator can read |
| C-intake-48 | should | no | who may open a case by mail is administered, by allowing senders or blocking them |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-intake-1, `intake.tsv:37`):
"zammad: Postmaster filter pipeline (30 filters in
app/models/channel/filter/, including auto_response_check.rb,
own_notification_loop_detection.rb, bounce_delivery_permanent_failed.rb,
out_of_office_check.rb)". On C-intake-11, `intake.tsv:42`: "otobo: Ticket
menu, Bounce (AgentTicketBounce.pm)". On C-intake-48, `intake.tsv:4`,
three driven passers: "osticket: Admin, Emails and Banlist
(scp/emails.php, scp/banlist.php, include/class.banlist.php,
scp/emailtest.php, scp/emailsettings.php)".

Two of the five `must` candidates carry a documented passer only,
C-intake-29 and C-intake-31. **D6 was answered relevance-led**: every
`must` enters the corpus whatever its passer count, so neither is deferred
for want of a driven column. C-intake-31 is also the security finding
above, which is an obligation rather than a comparison.

**D17 was answered for a broad market.** None of the twenty `not`
candidates is in this cluster.

## The decision this rests on

D12, "The mail transport, and whose it is". The file recommended that
integriq hold the account. **Ruben answered it the other way: Nextcloud
Mail owns the mail account**, because the OAuth 2.0 flow is already
Nextcloud Mail's. integriq opens no mail-account change, and cluster 28
"Mail accounts, OAuth2 and alias domains" is answered by the account
Nextcloud already holds.

What stays with dossiq is what D12 always put here: "dossiq holds the
filter pipeline, because a bounce is not a mail-server concern and both
systems that pass it put the classification on the intake path." The
sender-authentication half stays with dossiq too, because refusing,
quarantining or accepting is a policy decision per case type and not a
transport one.

## Cluster 28, read against this change (wave 4)

The wave 4 lane checked whether cluster 28's two `must` candidates are
already answered here, rather than opening a change D12 declined. They are
not both answered, so this change gains two sibling requirements instead.

| candidate | relevance | answered here | by |
|---|---|---|---|
| C-intake-45 | must | yes | REQ-IMF-01. dossiq reads the account Nextcloud Mail holds, stores no mailbox password, implements no OAuth 2.0 flow, and deletes `email_imap_password` on upgrade |
| C-integrations-42 | must | half | REQ-IMF-01 answers the mailbox. The candidate is "the mailbox **and the outbound mail** authenticate with OAuth2", and nothing here said anything about sending. **REQ-IMF-11** now does |
| C-intake-37 | should | no | several alias domains on one instance. Nextcloud Mail holds the accounts, so a second account with a second domain is an account selection, not a dossiq feature. Recorded, not built |
| C-configuration-63 | should | partly | mail configured per mailbox rather than per instance. REQ-IMF-12 gives a case type its own sending account, which is the half dossiq owns; which accounts exist stays Nextcloud Mail's |
| C-integrations-8 | could | half | "move fetched mail, file sent mail". REQ-IMF-03 already moves a fetched message. REQ-IMF-11 files sent mail in the account's sent folder |

The evidence, verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-integrations-42, `integrations.tsv:20`: "znuny: OAuth2 token
  management (AdminOAuth2TokenManagement.pm, System/OAuth2Token.pm,
  OAuth2TokenConfig.pm, Email/MSGraph.pm, six console commands)". Six
  driven passers, the widest in cluster 28, and a matrix hole. Its clause:
  "M365 basic auth is gone".
- C-intake-45, `intake.tsv:49`: "easy-redmine: IT service management, How
  to Set Up OAuth 2.0 Login for Mailboxes". The lane's note: "The
  mail-on-OAuth2 blocker: a municipality on Exchange Online cannot give a
  password, so a password-only IMAP client cannot be connected at all".

REQ-IMF-12 also carries the dossiq half of **integriq
`outbound-sender-identity-and-deliverability`** (integriq#2012, round 4
cluster 61, candidate C-communication-44, `communication.tsv`: "znuny:
Outbound mail profiles and sendmail config (AdminSendmailConfig.pm, the
Outbound Email Profiles screen)"): the sender identity per team, declared
on the case type.
integriq owns the record of what was sent and from whom, and the
deliverability of it; dossiq declares which selected account a case type
sends from and refuses to name a From address no account holds. The
register's own line for cluster 61 says the same: integriq's
`outbound-communication-log` "adds the sender identity as one more field
on its record when the cluster lands".

## What dossiq consumes from Nextcloud Mail

Read against `nextcloud/mail` on `main`. None of it is `OCP`, so it is
app-to-app coupling on another app's internal API, and D-1 says what that
costs and how this change contains it.

| what dossiq needs | what Nextcloud Mail has |
|---|---|
| the account, with no password in dossiq | `OCA\Mail\Service\AccountService`, `OCA\Mail\Db\MailAccount` and `MailAccountMapper` |
| an authenticated connection to Exchange and Google | `lib/Integration/MicrosoftIntegration.php` and `lib/Integration/GoogleIntegration.php`, with `xoauth2` in `lib/IMAP/IMAPClientFactory.php` and `lib/SMTP/SmtpClientFactory.php` |
| a shared postbus reachable by more than its owner | `OCA\Mail\Db\Delegation` and `OCA\Mail\Service\DelegationService`; `lib/Service/Provisioning/` for a provisioned account |
| new messages, without polling IMAP ourselves | `OCA\Mail\Events\NewMessagesSynchronized`, with `getAccount()`, `getMailbox()` and `getMessages()`; also `NewMessageReceivedEvent` and `MailboxesSynchronizedEvent` |
| the folders, and moving a message between them | `OCA\Mail\Service\MailManager::getMailboxes()`, `createMailbox()` and `moveMessage()` |
| the raw message, for headers we must read ourselves | `MailManager::getSource()` |
| whether a threading header names a message this account really holds | `MailManager::getByMessageId()` |
| a DKIM verdict | `OCA\Mail\Service\DkimService` and `DkimValidator` |
| a reply-to mismatch verdict | `OCA\Mail\Service\PhishingDetection\PhishingDetectionService`, with `ReplyToCheck`, `ContactCheck`, `CustomEmailCheck`, `DateCheck`, `ImapFlagCheck` and `LinkCheck` |
| an administered sender allowlist | `OCA\Mail\Service\TrustedSenderService` and `OCA\Mail\Db\TrustedSender` |
| junk, at the mail layer | `OCA\Mail\Service\AntiSpamService` and `AntiAbuseService` |
| server-side rules, where a municipality prefers them | `OCA\Mail\Service\SieveService` and `lib/Service/MailFilter/FilterBuilder.php` |
| the attachments | `MailManager::getMailAttachments()` |

What Nextcloud Mail does not give us, and dossiq therefore reads itself:
**SPF and DMARC**. The tree carries `DkimService` and a `ReplyToCheck` and
no SPF or DMARC checker, so dossiq reads the `Authentication-Results`
header off the raw source and records what the receiving server found. A
header that is absent is `unavailable`, never `pass`.

## What changes

- `InboundEmailJob` stops opening IMAP. It listens for Nextcloud Mail's
  synchronisation and reads messages from the account an administrator
  picked. `email_imap_host`, `email_imap_username` and
  `email_imap_password` are retired, and the stored password is removed
  on upgrade.
- A filter pipeline runs before anything becomes a case, in a declared
  order, with each filter named and each verdict recorded.
- Two named actions a handler and the pipeline can both take: **bounce**
  and **move**. Awb 2:3 is the doorzendplicht, so a misdirected message is
  sent on to the right address and the forwarding is recorded on nothing,
  because it never became a case.
- Nothing is dropped. A message that matches no case and no case type
  lands in an intake inbox with its verdict.
- A sender-authentication verdict on every message, and a per-case-type
  policy that decides what to do with a failing one.
- An intake log: every message the mailbox processed, its original, and
  why it did or did not become a case.
- An administered allow and block list for who may open a case by mail.

## Ownership

Nextcloud Mail owns the account, the credential, the OAuth 2.0 flow and
the transport. dossiq owns the pipeline, the verdicts, the policy and the
log. dossiq ships no IMAP client, no OAuth flow and no credential store
after this change.

## Capabilities

- Added: `inbound-mail-filters`: what happens to a message between the
  mailbox and a case.

## Impact

`lib/BackgroundJob/InboundEmailJob.php`,
`lib/Service/Email/UnmatchedMailIntake.php`,
`lib/Service/Email/CaseEmailRepository.php`,
`lib/Service/CaseEmailService.php`,
`src/views/settings/EmailSettings.vue` (the password field goes), a new
intake-log surface, a migration that deletes the stored password, Dutch
and English strings.

## Out of scope

- The acknowledgement of receipt. That is dossiq's own wave 1 change
  `ontvangstbevestiging`, cluster 32, Awb 4:3a, and it runs after this
  pipeline decides a message is a case.
- Classifying and promoting an inbound document. Cluster 44, filinq.
- Outbound sender identity and deliverability. Cluster 60, integriq, and
  it now sits on a Nextcloud Mail account rather than an integriq one.
- Intake channels beyond mail. Cluster 45, integriq.

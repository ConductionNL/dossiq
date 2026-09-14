## ADDED Requirements

### Requirement: Intake reads the account Nextcloud Mail holds (REQ-IMF-01)

dossiq SHALL read inbound mail from a Nextcloud Mail account an
administrator selects, through one gateway class. dossiq SHALL NOT open an
IMAP connection, SHALL NOT store a mailbox password, and SHALL NOT
implement an OAuth 2.0 flow. Upgrading SHALL delete any stored
`email_imap_password`. When Nextcloud Mail is absent, intake SHALL report
that it is unavailable and SHALL NOT throw on the scheduled run.

#### Scenario: an administrator picks the account instead of typing a password
@e2e tests/e2e/inbound-mail-filters.spec.ts

- **GIVEN** a Nextcloud Mail account the instance can reach
- **WHEN** an administrator opens the dossiq mail settings
- **THEN** they SHALL pick that account
- **AND** no password field SHALL be offered

#### Scenario: the stored password is deleted on upgrade
@e2e exclude An upgrade step, not a browser act; covered by tests/Unit/Repair/RetireImapCredentialsTest.php.

- **GIVEN** an instance carrying `email_imap_password`
- **WHEN** the upgrade runs
- **THEN** the value SHALL be removed

#### Scenario: only one file names the Mail app
@e2e exclude A property of the tree, not of a running page; covered by tests/Unit/Service/Email/NextcloudMailGatewayTest.php.

- **GIVEN** the dossiq tree
- **WHEN** it is read for `OCA\Mail` symbols
- **THEN** they SHALL appear in the gateway class only

#### Scenario: intake is unavailable rather than broken when Mail is gone
@e2e exclude Needs the Mail app disabled under the suite; covered by tests/Unit/Service/Email/InboundMailIntakeTest.php and NextcloudMailGatewayTest.php.

- **GIVEN** an instance where the Mail app is disabled
- **WHEN** the intake run fires
- **THEN** it SHALL report intake unavailable
- **AND** it SHALL NOT throw

### Requirement: Every message walks a declared filter pipeline (REQ-IMF-02)

Every inbound message SHALL pass a pipeline of named filters in a declared
order before it can become a case. Each filter SHALL answer `accept`,
`reject`, `quarantine`, `forward` or `pass`. dossiq SHALL record the
filter that decided and its reason. A message reaching the end of the
pipeline with no decision SHALL be accepted.

#### Scenario: an auto-reply does not open a case
@e2e exclude Needs a mail server to deliver one; covered by tests/Unit/Service/Email/FilterPipelineTest.php.

- **GIVEN** a message carrying an out-of-office auto-submitted header
- **WHEN** intake runs
- **THEN** no case SHALL be created
- **AND** the intake log SHALL name the filter that decided

#### Scenario: a bounce does not open a case
@e2e exclude Needs a mail server to generate one; covered by tests/Unit/Service/Email/FilterPipelineTest.php.

- **GIVEN** a permanent delivery failure notification
- **WHEN** intake runs
- **THEN** no case SHALL be created

#### Scenario: our own notification coming back does not open a case
@e2e exclude Needs a mail server to deliver it back; covered by tests/Unit/Service/Email/FilterPipelineTest.php.

- **GIVEN** a message dossiq itself sent, delivered back to the intake folder
- **WHEN** intake runs
- **THEN** no case SHALL be created
- **AND** no loop SHALL start

#### Scenario: a message nothing objects to is accepted
@e2e exclude Needs a mail server to deliver one; covered by tests/Unit/Service/Email/FilterPipelineTest.php.

- **GIVEN** a message no filter decides on
- **WHEN** intake runs
- **THEN** it SHALL be accepted and offered to case matching

### Requirement: Bounce and move are named, recorded acts (REQ-IMF-03)

dossiq SHALL offer `bounce`, which sends a message on to another address
with its original intact, and `move`, which files it in another folder of
the same account. A bounce SHALL record the address it went to and the
reason. A bounce SHALL NOT create a case and SHALL NOT reject the message
back to its sender.

#### Scenario: a misdirected aanvraag is sent on, per Awb 2:3
@e2e exclude Sends real mail; covered by tests/Unit/Service/Email/BounceActionTest.php.

- **GIVEN** a message for another administrative body
- **WHEN** a handler bounces it to that body's address
- **THEN** the original SHALL be forwarded intact
- **AND** the forwarding SHALL be recorded with the address and the reason
- **AND** no case SHALL exist for it

#### Scenario: a bounce is not a rejection
@e2e exclude Sends real mail; covered by tests/Unit/Service/Email/BounceActionTest.php.

- **GIVEN** a bounced message
- **WHEN** the sender's mailbox is read
- **THEN** no delivery failure SHALL have been sent to them

#### Scenario: a message is filed in another folder
@e2e exclude Needs a mail account with folders; covered by tests/Unit/Service/Email/BounceActionTest.php.

- **GIVEN** an account with a second folder
- **WHEN** a message is moved to it
- **THEN** it SHALL be in that folder on the mail server

### Requirement: Nothing is dropped (REQ-IMF-04)

A message that matches no case and no case type SHALL land in an intake
inbox carrying its verdict. dossiq SHALL NOT discard an inbound message,
and SHALL NOT leave one unaccounted for.

#### Scenario: an unmappable request is waiting for somebody
@e2e exclude Needs intake to run over a mailbox; covered by tests/Unit/Service/Email/InboundMailIntakeTest.php.

- **GIVEN** a message matching no case and no case type
- **WHEN** intake runs
- **THEN** it SHALL appear in the intake inbox with its verdict

#### Scenario: every processed message is accounted for
@e2e exclude Needs a delivered batch; covered by tests/Unit/Service/Email/InboundMailIntakeTest.php.

- **GIVEN** a batch of inbound messages
- **WHEN** intake has run
- **THEN** every message SHALL be a case, a bounce, an inbox entry or a recorded refusal

### Requirement: Every message carries four authentication results (REQ-IMF-05)

dossiq SHALL record an SPF, a DKIM, a DMARC and a threading result on
every inbound message. Each SHALL be `pass`, `fail`, `none` or
`unavailable`. SPF and DMARC SHALL be read from the message's
authentication-results header and SHALL be `unavailable` when it is
absent. DKIM SHALL come from Nextcloud Mail's DKIM service. dossiq SHALL
NOT report `unavailable` as `pass`.

#### Scenario: an unsigned message is not reported as authenticated
@e2e exclude Reads a raw message source; covered by tests/Unit/Service/Email/AuthenticationVerdictTest.php.

- **GIVEN** a message with no authentication-results header
- **WHEN** intake runs
- **THEN** the SPF and DMARC results SHALL be `unavailable`
- **AND** neither SHALL be `pass`

#### Scenario: a signed message passes
@e2e exclude Reads a raw message source; covered by tests/Unit/Service/Email/AuthenticationVerdictTest.php.

- **GIVEN** a message whose headers carry SPF pass, DKIM pass and DMARC pass
- **WHEN** intake runs
- **THEN** three results SHALL be `pass`

### Requirement: A threading claim is checked against this account (REQ-IMF-06)

When a message carries `In-Reply-To` or `References`, dossiq SHALL check
whether any referenced message id is held by this account. The threading
result SHALL be `pass` on a match, `fail` when a reference is present and
matches nothing, and `none` when no threading header is present. dossiq
SHALL NOT link a message to a case on a subject tag alone when its
threading result is `fail`.

#### Scenario: a forged reference does not reach somebody else's case
@e2e exclude Needs a forged message delivered; covered by tests/Unit/Service/Email/ThreadingCheckTest.php and InboundMailIntakeTest.php.

- **GIVEN** a message whose `In-Reply-To` names a message id this account never held
- **AND** whose subject carries another person's case tag
- **WHEN** intake runs
- **THEN** the threading result SHALL be `fail`
- **AND** the message SHALL NOT be linked to that case

#### Scenario: a genuine reply reaches its case
@e2e exclude Needs a threaded reply delivered; covered by tests/Unit/Service/Email/ThreadingCheckTest.php and InboundMailIntakeTest.php.

- **GIVEN** a reply to a message this account sent about a case
- **WHEN** intake runs
- **THEN** the threading result SHALL be `pass`
- **AND** the message SHALL be linked to that case

#### Scenario: a first message makes no threading claim
@e2e exclude Needs a message delivered; covered by tests/Unit/Service/Email/ThreadingCheckTest.php.

- **GIVEN** a message with no threading header
- **WHEN** intake runs
- **THEN** the threading result SHALL be `none`
- **AND** it SHALL NOT be treated as a failure

### Requirement: Each case type decides what a failed verdict means (REQ-IMF-07)

A case type SHALL declare its intake policy for a failing authentication
verdict: `accept`, `quarantine` or `refuse`. The default SHALL be
`quarantine`. A quarantined message SHALL be releasable by a named role
and SHALL NOT be deleted. A refusal SHALL be recorded and SHALL tell the
sender.

#### Scenario: a bezwaar from an unauthenticated sender waits for a human
@e2e exclude Needs a failing verdict on a delivered message; covered by tests/Unit/Service/Email/IntakePolicyTest.php.

- **GIVEN** a bezwaar case type with the default policy
- **AND** a message whose DMARC result is `fail`
- **WHEN** intake runs
- **THEN** the message SHALL be quarantined
- **AND** no case SHALL be created yet

#### Scenario: a melding from an unauthenticated sender is normal
@e2e exclude Needs a failing verdict on a delivered message; covered by tests/Unit/Service/Email/IntakePolicyTest.php.

- **GIVEN** a melding case type declaring `accept`
- **AND** a message whose DMARC result is `fail`
- **WHEN** intake runs
- **THEN** a case SHALL be created
- **AND** the verdict SHALL be recorded on it

#### Scenario: a released message becomes a case
@e2e tests/e2e/inbound-mail-filters.spec.ts

- **GIVEN** a quarantined message
- **WHEN** the intake role releases it
- **THEN** a case SHALL be created
- **AND** the release SHALL be recorded with the person who made it

### Requirement: The intake log holds the original and the verdict (REQ-IMF-08)

dossiq SHALL keep a readable log of every message the mailbox processed,
holding its original source, its authentication results, the filter that
decided, the verdict, and the case it became or the reason it did not. The
log SHALL be readable only by the role that runs intake and SHALL obey the
case type's retention rule.

#### Scenario: somebody says they mailed us
@e2e tests/e2e/inbound-mail-filters.spec.ts

- **GIVEN** a message processed last week that became no case
- **WHEN** the intake role searches the log by sender
- **THEN** the message SHALL be found with its original and its reason

#### Scenario: the log is not readable by everyone
@e2e exclude Playwright signs in as admin, who passes the role check; covered by tests/Unit/Service/Email/IntakePolicyTest.php.

- **GIVEN** a user without the intake role
- **WHEN** they open the intake log
- **THEN** access SHALL be refused

### Requirement: Who may open a case by mail is administered (REQ-IMF-09)

dossiq SHALL read Nextcloud Mail's trusted-sender list as its allow half,
and SHALL hold its own block list for senders who may not open a case.
Blocking a sender in dossiq SHALL NOT change whether that sender can mail
anyone else on the instance. Every block decision SHALL be recorded in the
intake log.

#### Scenario: a blocked sender opens no case
@e2e exclude Needs a message delivered from the blocked address; covered by tests/Unit/Service/Email/FilterPipelineTest.php and SenderBlocklistTest.php.

- **GIVEN** a blocked sender address
- **WHEN** a message arrives from it
- **THEN** no case SHALL be created
- **AND** the block SHALL be recorded in the log

#### Scenario: blocking in dossiq does not block the mailbox
@e2e exclude A property of the gateway surface; covered by tests/Unit/Service/Email/SenderBlocklistTest.php.

- **GIVEN** a sender blocked in dossiq
- **WHEN** they mail a colleague on the same instance
- **THEN** that message SHALL be unaffected

### Requirement: A junk verdict comes from a rule somebody can read (REQ-IMF-10)

A message classified as junk SHALL carry the rule that classified it, and
an administrator SHALL be able to read that rule. A person SHALL be able
to mark a message as junk or as not junk, and that SHALL be recorded.

#### Scenario: an administrator reads why a message was junked
@e2e tests/e2e/inbound-mail-filters.spec.ts

- **GIVEN** a message classified as junk
- **WHEN** an administrator opens it in the intake log
- **THEN** the rule that classified it SHALL be named

#### Scenario: a person corrects a wrong junk verdict
@e2e tests/e2e/inbound-mail-filters.spec.ts

- **GIVEN** a message wrongly classified as junk
- **WHEN** the intake role marks it as not junk
- **THEN** it SHALL re-enter the pipeline
- **AND** the correction SHALL be recorded

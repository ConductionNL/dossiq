# email-case-matching Specification

**Status:** proposed
**Scope:** dossiq
**Tier:** V1
**Depends on:** OpenRegister email leaf (`OCA\OpenRegister\Service\EmailLinkService`, generic —
already consumed by pipelinq), Nextcloud Mail app tables (`mail_messages`, `mail_mailboxes`),
dossiq `SettingsService` app-config (`register`, case schema resolution).

## Purpose

Automatically attach an inbound Nextcloud Mail message to every case whose case number appears in the
message's subject or body, through the OpenRegister email leaf — idempotently, opt-in, and fail-closed.
The manual Mail-sidebar link (via `case` `configuration.linkedTypes: ["mail"]`) stays untouched; this
capability adds the automatic path only. No case is ever created by this capability.

## ADDED Requirements

### Requirement: REQ-ECM-001 — Configurable case-number recognition grounded in the identifier format

The system SHALL extract case-number candidates from email text using a configurable regular
expression stored in app-config `email_case_matching_pattern`. The default pattern SHALL match the
`case` schema's generated `identifier` format — `YYYY-NNNN` with a yearly sequence padded to at least
4 digits (e.g. `2026-0042`, materialised by `x-openregister-calculations.identifier`) — both bare and
with an optional uppercase prefix and/or surrounding brackets as decoration (so `2026-0042`,
`ZAAK-2026-0042`, and `[ZAAK-2026-000142]` all yield an identifier candidate). Capture group 1 SHALL
be the identifier as resolvable against the `identifier` property. A configured pattern SHALL be
validated before use (compiles, contains at least one capture group); an invalid pattern SHALL cause
the matcher to refuse the run with a logged error — it SHALL NOT fall back to matching everything or
nothing silently. Each candidate SHALL be resolved to a case by exact match on the `identifier`
property of the configured register's `case` schema; a candidate that resolves to no case SHALL be
skipped.

#### Scenario: Default pattern matches what the schema generates

- **GIVEN** the default `email_case_matching_pattern`
- **WHEN** it is applied to the texts `Betreft zaak 2026-0042`, `Re: [ZAAK-2026-000142] aanvulling`, and `ZAAK-2026-0042 stukken`
- **THEN** it SHALL yield the candidates `2026-0042`, `2026-000142`, and `2026-0042` respectively

#### Scenario: Invalid configured pattern fails closed

- **GIVEN** an administrator sets `email_case_matching_pattern` to a non-compiling expression
- **WHEN** the matching job runs
- **THEN** the run SHALL be refused with a logged error and zero messages SHALL be scanned or linked

---

### Requirement: REQ-ECM-002 — Subject is scanned first, body only when the subject yields nothing

For each new message the system SHALL scan the subject (`mail_messages.subject`) first; only when the
subject yields no resolvable case SHALL it scan the message body text available from the Nextcloud
Mail store for that message. All distinct candidates found in the scanned text SHALL be processed.

#### Scenario: Case number in the subject links without a body read

- **GIVEN** an enabled user and an email with subject `Aanvulling zaak 2026-0042`
- **WHEN** the matching job processes the message
- **THEN** the email SHALL be linked to case `2026-0042` via the email leaf
- **AND** the body SHALL NOT need to be read for this message

#### Scenario: Case number only in the body still links

- **GIVEN** an email with subject `Re: onze afspraak` and a body containing `dit betreft zaak 2026-0042`
- **WHEN** the matching job processes the message
- **THEN** the subject scan SHALL yield nothing, the body SHALL be scanned, and the email SHALL be linked to case `2026-0042`

---

### Requirement: REQ-ECM-003 — Linking is idempotent through the email leaf

The system SHALL attach a matched email to a case exclusively via
`EmailLinkService::linkEmail(objectUuid, registerId, schemaId, mailAccountId, messageId, messageUid)`
and SHALL NOT write any dossiq-local link table. Before linking, the system SHALL check the leaf's
existing links for the case (`getLinkedEmails`) and SHALL NOT create a duplicate link for the same
`(case, mailAccountId, messageId)`; reprocessing a message (cursor reset, job re-run) SHALL yield no
new links.

#### Scenario: Already-linked email is not linked twice

- **GIVEN** case `2026-0042` already holds a leaf link for message M
- **WHEN** the matching job processes message M again
- **THEN** no new link SHALL be created and the run's linked-count SHALL not increase for M

---

### Requirement: REQ-ECM-004 — Multiple case numbers link to every matched case

When the scanned text yields multiple distinct resolvable case numbers, the system SHALL link the
email to each of those cases. A failure to link one case SHALL be logged and SHALL NOT prevent
linking the others.

#### Scenario: Email naming two cases is attached to both

- **GIVEN** an email whose subject reads `Samenhang 2026-0042 en 2026-0043` and both cases exist
- **WHEN** the matching job processes the message
- **THEN** the email SHALL be linked to case `2026-0042` and to case `2026-0043`

---

### Requirement: REQ-ECM-005 — No match means nothing happens

An email in which no candidate resolves to an existing case SHALL cause no OpenRegister write of any
kind. The system SHALL NOT auto-create a case from an email (there is deliberately no
`mailObjectTemplate` on the `case` schema), SHALL NOT link to a "fallback" case, and SHALL NOT store
the miss anywhere except the run's scanned counter.

#### Scenario: Email without a case number is left alone

- **GIVEN** an email whose subject and body contain no resolvable case number
- **WHEN** the matching job processes the message
- **THEN** no link SHALL be created and no case SHALL be created

#### Scenario: A candidate that resolves to no case is skipped

- **GIVEN** an email containing `2099-9999` and no such case exists
- **WHEN** the matching job processes the message
- **THEN** the candidate SHALL be skipped with no write

---

### Requirement: REQ-ECM-006 — Matching is opt-in per instance and per user

The system SHALL run matching only when BOTH the instance toggle (app-config
`email_case_matching_enabled`, default `no`, managed through `SettingsService`) AND the user's own
setting (per-user settings blob in `IAppConfig`: `enabled` default `false`, plus the mail account id
to index) allow it. Each user's settings SHALL be independent. Disabling either level SHALL stop all
scanning for the affected scope from the next run.

#### Scenario: Instance toggle off stops everything

- **GIVEN** `email_case_matching_enabled` is `no`
- **WHEN** the matching job runs
- **THEN** no user's mail SHALL be scanned and no links SHALL be created

#### Scenario: User opt-in is individual

- **GIVEN** the instance toggle is on, user A has matching enabled and user B has not
- **WHEN** the matching job runs
- **THEN** only user A's configured account SHALL be scanned

---

### Requirement: REQ-ECM-007 — Fail closed when the register is unconfigured

When app-config `register` is empty, or the `case` schema cannot be resolved on the configured
register, the matcher SHALL refuse to run: zero OpenRegister calls, one logged warning. An empty
register value SHALL NEVER be cast to an integer or passed onward (which would scope writes to
register id 0 — the exact failure pipelinq's `EmailMatchService::registerSlug()` guard exists to
prevent; this guard SHALL mirror it).

#### Scenario: Unconfigured register refuses, not misroutes

- **GIVEN** app-config `register` is `''`
- **WHEN** the matching job runs for an enabled user
- **THEN** the run SHALL be refused before any OpenRegister call and a warning SHALL be logged

---

### Requirement: REQ-ECM-008 — A 5-minute TimedJob with a per-user cursor

Matching SHALL run as a `TimedJob` (`CaseEmailMatchJob`) with a 300-second interval, iterating only
opted-in users. Message discovery SHALL read the Nextcloud Mail tables (`mail_messages` joined to
`mail_mailboxes` on the configured account) above a per-user last-processed message-id cursor, in
ascending id order with a bounded batch size. Per-message failures SHALL be logged and SHALL NOT
abort the run; the cursor SHALL advance only over processed messages. Last-run status (timestamp,
scanned, linked, last error) SHALL be recorded per user for the settings surface.

#### Scenario: New mail is linked within the job interval

- **GIVEN** an enabled user whose account receives an email containing `2026-0042` in the subject
- **WHEN** the next `CaseEmailMatchJob` run completes
- **THEN** the email SHALL be linked to case `2026-0042` and the user's cursor SHALL have advanced past the message

#### Scenario: One poisoned message does not stop the batch

- **GIVEN** a batch where one message's processing throws
- **WHEN** the run completes
- **THEN** the remaining messages SHALL still be processed and the failure SHALL be logged

---

### Requirement: REQ-ECM-009 — An unmatched mail in the shared mailbox becomes a case

The shared-mailbox poller SHALL resolve a subject tag to a case by the bare
identifier the tag contains, not by the tag itself. When the subject carries no
tag, or names a case that does not resolve, and the instance configures a
fallback case type, the poller SHALL create a case of that type from the mail
and archive the mail onto it. When no fallback case type is configured it SHALL
create nothing, which is the behaviour of every instance that does not opt in.
The new case SHALL take its title from the subject, `email` as its intake
channel, and its assignee from the fallback case type's default assignee. Every
message the poller cannot place SHALL be counted in the run's log.

**Feature tier**: V1

#### Scenario: A reply carrying a case tag reaches its case
@e2e exclude Background poller over IMAP; covered by tests/Unit/BackgroundJob/InboundEmailJobTest.php.

- **GIVEN** a mail whose subject reads `Re: [ZAAK-2026-000142] uw aanvraag`
- **WHEN** the poller reads it
- **THEN** the case SHALL be looked up by the identifier `2026-000142`, without the prefix
- **AND** the mail SHALL be archived onto that case

#### Scenario: A first-time mail becomes a case
@e2e exclude Background poller over IMAP; covered by tests/Unit/Service/Email/UnmatchedMailIntakeTest.php.

- **GIVEN** an instance whose fallback case type is Klantvraag
- **WHEN** a mail arrives whose subject carries no case tag
- **THEN** a case of type Klantvraag SHALL be created with the subject as its title
- **AND** its intake channel SHALL be `email` and its assignee the type's default assignee
- **AND** the mail SHALL be archived onto it

#### Scenario: An instance that names no fallback type creates nothing
@e2e exclude Backend guard; covered by tests/Unit/Service/Email/UnmatchedMailIntakeTest.php.

- **GIVEN** an instance with no fallback case type configured
- **WHEN** a mail arrives that matches no case
- **THEN** nothing SHALL be written and the mail SHALL stay in the mailbox
- **AND** the run SHALL log how many messages it could not place

---

### Requirement: REQ-ECM-010 — One rule says who work goes to

Every path that creates a task or a case SHALL resolve its assignee through one
shared rule: render the authored value against the case, fall back to the
declared second choice, and answer nobody rather than the unrendered template.
A task created by a status transition or a status checklist SHALL additionally
fall back to the case's own handler, and SHALL carry the case's team when it has
one. A task that ends up naming nobody SHALL be logged.

**Feature tier**: V1

#### Scenario: A checklist task reaches the case's handler
@e2e exclude Backend resolution; covered by tests/Unit/Service/Transitions/CreateTaskHandlerTest.php.

- **GIVEN** a case whose handler is alice, entering a status whose checklist has one item
- **WHEN** the transition runs
- **THEN** the created task SHALL be assigned to alice
- **AND** its assignee SHALL NOT be the literal `{{ case.assignee }}`

#### Scenario: The team comes along without being mistaken for the handler
@e2e exclude Backend resolution; covered by tests/Unit/Service/Transitions/CreateTaskHandlerTest.php.

- **GIVEN** a case with a team and no personal handler
- **WHEN** a transition creates a task on it
- **THEN** the task SHALL carry the team in its own field
- **AND** the assignee field SHALL stay empty rather than holding the team's id

#### Scenario: A flow step still refuses when nobody resolves
@e2e exclude Backend resolution; covered by tests/Unit/Flow/DossiqAskPersonNodeTest.php.

- **GIVEN** a flow step whose assignee and fallback both name nobody
- **WHEN** the step runs
- **THEN** it SHALL refuse, because an unassigned flow task can be resumed by anyone
- **AND** the refusal SHALL say whether there was no fallback or a fallback that named nobody

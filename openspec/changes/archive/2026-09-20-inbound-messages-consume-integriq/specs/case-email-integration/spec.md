## ADDED Requirements

### Requirement: dossiq answers every message integriq offers it

dossiq SHALL listen for integriq's message-received event and SHALL write
an outcome into its result slot for every message: `linked` when the
message names a case, `created` when the fallback rule applies, and
`declined` with a reason otherwise. dossiq SHALL NOT leave the slot
unanswered, because on integriq's side silence and a decline land in the
same place and only one of them is a decision somebody made.

#### Scenario: A message naming a case is linked to it
@e2e exclude a cross-app event with no browser gesture; covered by MessageReceivedListenerTest over a doubled event

- **GIVEN** an incoming message whose detected reference names case 2026-114
- **WHEN** integriq offers it
- **THEN** dossiq SHALL file it on that case
- **AND** the event result SHALL read `linked`

#### Scenario: A message nobody can place is declined in words
@e2e exclude the same cross-app event; covered by the same test with no fallback case type configured

- **GIVEN** an incoming message with no reference and no fallback case type
- **WHEN** integriq offers it
- **THEN** the event result SHALL read `declined`
- **AND** the reason SHALL be recorded where a handler can read it

The reason goes in dossiq's OWN intake log rather than on the event, and
that is measured rather than chosen: integriq's `setOutcome()` takes an
outcome and an object reference and has no slot for a reason at all. The
intake log entry is written before the slot is answered, and it is the
surface a handler opens anyway.

An internal failure SHALL leave the slot empty rather than declining. That
is the one silence this requirement allows: after a crash no decision was
made, and writing `declined` would be this app claiming a judgement it
never reached.

#### Scenario: The same message offered twice is filed once
@e2e exclude a duplication guard over the intake log; covered by the listener test

- **GIVEN** a message already filed through the mailbox poll
- **WHEN** integriq offers the same message id
- **THEN** it SHALL NOT be filed a second time
- **AND** the event result SHALL still be answered

### Requirement: A handler files a logged message on a case they pick

The mail intake log SHALL offer File on a case on every entry, behind the
intake role. The act SHALL take the case and a reason, SHALL be refused
when the caller may not read that case, and SHALL record who overrode the
automatic match. The automatic match SHALL keep deciding first: this act
records one person's correction and SHALL NOT change the matching rules.

#### Scenario: A wrongly matched message is moved to the right case

- **GIVEN** a logged message filed on case 2026-090 by the matcher
- **WHEN** a handler with the intake role files it on case 2026-114 with a reason
- **THEN** the message SHALL appear on case 2026-114
- **AND** the log entry SHALL name the handler and the reason

#### Scenario: A case the caller may not read is refused
@e2e exclude an authorization branch driven from the API; covered by the MailIntakeController guard test

- **GIVEN** a handler with the intake role and no access to case 2026-200
- **WHEN** they file a message on it
- **THEN** the response SHALL be 403
- **AND** the message SHALL stay where it was

### Requirement: A saved mail file is parsed by integriq and filed as a message

A `.eml` or `.msg` file on a case SHALL be readable as a message. dossiq
SHALL hand the bytes to integriq's import endpoint and file the message it
returns on the case, keeping the original file beside it. dossiq SHALL
carry no mail parser of its own, because two parsers are two answers to one
question. An instance without integriq SHALL keep the file and say the
message could not be read.

dossiq reaches integriq's reader IN PROCESS rather than over the import
endpoint. The endpoint is that same parser behind a session and a multipart
upload, and calling it from PHP would mean forwarding the caller's session
to our own server; the parser class is resolved through the fleet app id
instead, under whichever namespace this instance's integriq has.

#### Scenario: An Outlook message dropped on a case becomes readable

- **GIVEN** a case and a saved `.msg` file
- **WHEN** a handler picks Read as a message on that row
- **THEN** the case SHALL show the message with its subject, sender and date
- **AND** the original `.msg` SHALL still be in the case folder, unmoved

#### Scenario: A file that is not a message is refused rather than filed
@e2e exclude a client-side name check with the server arm driven in the e2e spec; covered by the savedMail unit test

- **GIVEN** a row that is not a saved mail file
- **WHEN** a handler picks Read as a message on it
- **THEN** nothing SHALL be filed on the case
- **AND** the handler SHALL be told there is no message in it to read

#### Scenario: Without integriq the file is kept and the reason is given
@e2e exclude a missing-app branch that needs integriq uninstalled; covered by the SavedMailImport unit test with the probe answering false

- **GIVEN** an instance with no integriq installed
- **WHEN** a handler reads a `.eml` file as a message
- **THEN** the file SHALL remain on the case unchanged
- **AND** the handler SHALL be told the message could not be read and why

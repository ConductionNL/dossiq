## ADDED Requirements

### Requirement: Digital post is sent through integriq and never simulated by dossiq

dossiq SHALL send a citizen's digital post by dispatching integriq's send
event and reading its result. A tracked message id SHALL be recorded as
sent. A structured refusal SHALL be recorded as not sent, with the reason.
A result slot that came back unanswered SHALL be treated as a refusal.
dossiq SHALL ship no digital post transport, no adapter interface of its
own and no mock, because a fallback that reports a delivery is
indistinguishable from a delivery.

#### Scenario: A letter integriq accepted is recorded as sent
@e2e exclude a cross-app dispatch with no reachable transport on this instance; covered by BerichtenboxServiceTest over a doubled dispatcher

- **GIVEN** a case with a recipient who has a digital post address
- **WHEN** a handler sends a letter and integriq answers with a tracked id
- **THEN** the message SHALL be recorded as sent, carrying that id
- **AND** the case timeline SHALL show it

#### Scenario: A refusal is never recorded as a delivery
@e2e exclude the same cross-app dispatch; covered by the same test with a refusing result

- **GIVEN** an instance whose digital post provider is not configured
- **WHEN** a handler sends a letter
- **THEN** the message SHALL be recorded as not sent
- **AND** the recorded reason SHALL be the one integriq gave

#### Scenario: An unanswered result is a refusal, not a send
@e2e exclude a defensive branch over the event contract; covered by the same test with an empty result slot

- **GIVEN** integriq is installed and its listener writes nothing into the result
- **WHEN** a handler sends a letter
- **THEN** the message SHALL be recorded as not sent
- **AND** the reason SHALL say that no provider answered

#### Scenario: Without integriq the send is refused and names the missing app
@e2e exclude a missing-app branch that needs integriq uninstalled; covered by the service test with the fleet probe answering false

- **GIVEN** an instance with no integriq installed
- **WHEN** a handler sends a letter
- **THEN** the send SHALL be refused
- **AND** the refusal SHALL name integriq, resolved through the fleet app
  id rather than a literal name that a rename would silence

### Requirement: A handler can send digital post from the case

A case detail page SHALL offer Send digital post, opening the compose
dialog. The action SHALL be offered on a case whose recipient has a digital
post address, and the dialog SHALL show any refusal in the words the
provider gave, so a handler learns which credential is missing rather than
that sending failed.

#### Scenario: The compose dialog opens from the case

- **GIVEN** a case whose requester has a digital post address
- **WHEN** a handler opens the case
- **THEN** Send digital post SHALL be among the header actions
- **AND** pressing it SHALL open the compose dialog with that recipient

#### Scenario: The refusal reaches the handler in full

- **GIVEN** an instance whose provider refuses because a certificate is missing
- **WHEN** a handler sends a letter from the compose dialog
- **THEN** the dialog SHALL show the provider's own reason
- **AND** it SHALL NOT close as though the letter went out

### Requirement: Delivery and read status come back as events

dossiq SHALL listen for integriq's delivered event and SHALL update the
message and the case timeline on every status change, including a failure.
Sent and delivered SHALL be told apart on the case, because a letter in
flight and a letter received are different answers to what a citizen knows.

#### Scenario: A failed delivery is shown as failed
@e2e exclude a cross-app event with no browser gesture; covered by DigitalPostDeliveredListenerTest

- **GIVEN** a message recorded as sent
- **WHEN** integriq reports its status as failed
- **THEN** the case SHALL show it as failed
- **AND** the status SHALL NOT be dropped for being a status nobody wanted

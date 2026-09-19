# pipelinq-consumption

## ADDED Requirements

### Requirement: One seam names pipelinq, and an absent pipelinq is a different answer from an empty one (REQ-PLQ-01)

Dossiq SHALL reach every pipelinq capability through one gateway that resolves a
pipelinq class through the server container, guarded by `class_exists` and by
the presence of the methods it is about to call. No other dossiq class SHALL
name a pipelinq class.

Every reader SHALL report whether pipelinq answered, separately from what it
answered, so a surface can tell "pipelinq says there is nothing" from "pipelinq
is not installed". A surface SHALL NOT render the second as the first.

When pipelinq is absent every reader SHALL answer its own fallback and every
writer SHALL be a no-op that logs once. A case SHALL stay workable on an
instance that has no pipelinq at all.

#### Scenario: An absent pipelinq is said, not rendered as empty
@e2e exclude The absence of an app cannot be staged in the browser on an instance that has it; PipelinqGatewayTest::testAnAbsentPipelinqIsUnavailable and ::testAPartialServiceIsRefused assert both halves.

- **GIVEN** an instance without pipelinq
- **WHEN** a case's party panel is read
- **THEN** the answer SHALL say pipelinq is unavailable
- **AND** it SHALL NOT report an empty list of indicators as though pipelinq had answered one

#### Scenario: A pipelinq that cannot answer is refused before it is called
@e2e exclude A partial service is a runtime shape, not a screen; PipelinqGatewayTest::testAPartialServiceIsRefused asserts it.

- **GIVEN** a pipelinq whose service lacks a method this seam calls
- **WHEN** the gateway is asked for it
- **THEN** it SHALL answer that pipelinq cannot serve this capability
- **AND** it SHALL NOT return an object that would fatal on the first call

#### Scenario: Only the gateway names pipelinq
@e2e exclude A property of the tree, not of a screen; PipelinqGatewayTest::testOnlyTheGatewayNamesPipelinq greps lib/ for the namespace.

- **WHEN** dossiq's `lib/` is inspected for the string `OCA\Pipelinq`
- **THEN** it SHALL appear only in the gateway and in the constants it resolves

### Requirement: A contact moment logged on a case is appended to pipelinq's record (REQ-PLQ-02)

When dossiq logs a contact moment on a case it SHALL also append it through
pipelinq's contact moments leaf, carrying the direction dossiq already requires,
the case as the host, and the party when one is known.

The append SHALL be best effort. A failure, a refusal or an absent pipelinq
SHALL NOT block, fail or roll back the dossiq write: a handler logging a call
SHALL NOT lose it because pipelinq refused something.

Where pipelinq refuses an OUTBOUND append because an indicator blocks it, that
refusal SHALL be reported to the handler with the indicator named, and the
dossiq record SHALL still be written.

#### Scenario: A logged call reaches both records
@e2e tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts

- **GIVEN** a case and a handler logging an inbound telephone contact moment
- **WHEN** the moment is saved
- **THEN** a dossiq contact moment SHALL exist
- **AND** pipelinq SHALL have been asked to append the same moment with `direction = inbound` and the case as its host

#### Scenario: pipelinq refusing does not lose the handler's work
@e2e exclude A refusal from another app cannot be staged in the browser; ContactMomentBridgeTest::testARefusalDoesNotLoseTheDossiqWrite asserts it.

- **GIVEN** pipelinq refusing the append
- **WHEN** a handler logs the contact moment
- **THEN** the dossiq record SHALL still be written
- **AND** the refusal SHALL be reported with the reason pipelinq gave

### Requirement: A case shows every contact moment it is a member of, and says when one is shared (REQ-PLQ-03)

The case's communication surface SHALL read pipelinq's contact moments by
MEMBERSHIP of the case in `caseReferences`, not by equality on a single
reference, so one call filed on three cases appears on each of the three.

Where a contact moment is on more than one case, the surface SHALL say so. A
case the reader may not see SHALL be reported as a count rather than by title.

Filing a contact moment onto a further case, and taking it off one, SHALL be
performed through pipelinq's two acts. Dossiq SHALL NOT edit the reference set
itself and SHALL NOT create a second contact moment to put on a second case.

#### Scenario: One call on three cases appears on each
@e2e tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts

- **GIVEN** one contact moment filed on cases A, B and C
- **WHEN** the communication surface of case B is read
- **THEN** it SHALL list that contact moment once
- **AND** it SHALL say the moment is also on two other cases

#### Scenario: Dossiq never writes the set itself
@e2e exclude A property of the tree; ContactMomentBridgeTest::testFilingGoesThroughPipelinqsActs asserts every write path resolves to a pipelinq act.

- **WHEN** dossiq's write paths for a contact moment are inspected
- **THEN** no path SHALL write `caseReferences` or `primaryCaseReference` directly
- **AND** filing and unfiling SHALL each resolve to a pipelinq act

### Requirement: A case type declares which party kinds it accepts, and dossiq ships no vocabulary of its own once pipelinq answers (REQ-PLQ-04)

Dossiq SHALL declare to pipelinq, per case type, the ordered set of party kinds
that type accepts, naming the target `dossiq:case:<caseType>`. The order
declared SHALL be the order handlers are offered.

The party kind vocabulary a picker offers SHALL come from pipelinq when pipelinq
answers. Dossiq's own three kinds SHALL remain as the fallback for an instance
without pipelinq, and SHALL NOT be offered beside pipelinq's.

#### Scenario: A subsidy case type declares two kinds
@e2e tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts

- **GIVEN** a case type accepting an aanvrager and a gemachtigde
- **WHEN** its acceptance is declared
- **THEN** pipelinq SHALL hold `dossiq:case:<caseType>` with those two kinds in that order

#### Scenario: Without pipelinq the picker still offers something
@e2e exclude Needs an instance without pipelinq; PartyKindConsumerTest::testTheShippedKindsAreTheFallback asserts it.

- **GIVEN** an instance without pipelinq
- **WHEN** the party kinds are read
- **THEN** dossiq's own three kinds SHALL be offered
- **AND** the answer SHALL say they are dossiq's own

### Requirement: Dossiq asks before it sends and before it publishes, and either refusal stops it (REQ-PLQ-05)

Before sending anything to a party, and before publishing a case, dossiq SHALL
ask pipelinq whether an indicator blocks the act, and SHALL report the refusal
with the indicator named.

The answer SHALL be JOINED with the refusal OpenRegister's own party guard
already gives: an act SHALL be refused when EITHER refuses it. Dossiq SHALL NOT
repoint its existing OpenRegister reader at pipelinq, because a duck-typed
lookup aimed at a name nothing answers to no-ops silently rather than failing.

#### Scenario: A send to a deceased party is refused with the indicator named
@e2e exclude Needs a party carrying a blocking indicator in pipelinq; PartyRefusalReaderTest::testPipelinqRefusesTheSend asserts it.

- **GIVEN** a party carrying a pipelinq indicator that blocks outbound contact
- **WHEN** dossiq asks whether it may send to that party
- **THEN** the answer SHALL be refused
- **AND** it SHALL name the indicator and its label

#### Scenario: Either source refusing is enough
@e2e exclude Two sources cannot be staged in one browser run; PartyRefusalReaderTest::testEitherSourceRefusing asserts both directions and the both-silent case.

- **GIVEN** OpenRegister's guard refusing and pipelinq answering nothing
- **WHEN** dossiq asks
- **THEN** the act SHALL be refused
- **AND** the same SHALL hold with the two sources the other way round

### Requirement: The language to write to a party in comes from the resolver, with its reason (REQ-PLQ-06)

Dossiq SHALL obtain the language to write to a party in from pipelinq's
resolver, and SHALL render the reason the resolver gives beside it. Dossiq SHALL
NOT read `correspondenceLanguage` off a record to answer the question itself.

An unset preference SHALL be rendered as unset, naming what would be used
instead, rather than as though the party had chosen it.

#### Scenario: An unset preference is not dressed up as chosen
@e2e exclude Needs pipelinq's resolver; CorrespondenceLanguageConsumerTest::testAnUnsetPreferenceIsSaid asserts it.

- **GIVEN** a party that has stated no preference
- **WHEN** the case surface reads the language to write in
- **THEN** it SHALL show that no preference is recorded
- **AND** it SHALL name the language that would be used and why

#### Scenario: Nobody reads the property directly
@e2e exclude A property of the tree; CorrespondenceLanguageConsumerTest::testNoCallerReadsThePropertyDirectly greps lib/ for the property name.

- **WHEN** dossiq's `lib/` is inspected for `correspondenceLanguage`
- **THEN** it SHALL appear only in the consumer that calls the resolver

### Requirement: A closing case asks pipelinq for the satisfaction survey, and holds no survey engine (REQ-PLQ-07)

When a case reaches a terminal status, dossiq SHALL tell pipelinq that the
interaction completed, so pipelinq's dispatch rules decide whether a survey is
sent. Dossiq SHALL hold no survey, invitation, token, cooldown or opt-out of its
own, and SHALL NOT decide whether to send.

The hand-off SHALL NOT block the case's own save. A case closing is the
handler's work; the survey is pipelinq's.

#### Scenario: Closing a case hands off, and nothing more
@e2e exclude Needs pipelinq's dispatch rules configured; SatisfactionHandoffTest::testAClosingCaseHandsOff and ::testTheHandoffNeverBlocksTheSave assert both.

- **GIVEN** a case reaching a terminal status
- **WHEN** it is saved
- **THEN** pipelinq SHALL be told the interaction completed, with the case, its status and the party
- **AND** dossiq SHALL make no decision about sending, and SHALL write no invitation

### Requirement: A case hangs under a programme by reference, and the progress figure names its mode (REQ-PLQ-08)

Dossiq SHALL be able to link a case to a pipelinq programme, naming the
reference `dossiq:case` and the case's uuid. Dossiq SHALL declare no programme
object of its own and SHALL add no budget or cost ceiling field to a case.

Where pipelinq refuses the link because the case is already in another
programme, dossiq SHALL report the refusal with the programme named.

A programme's progress figure SHALL be rendered together with the mode that
produced it, and a figure pipelinq says it cannot compute SHALL be rendered as
uncomputable rather than as zero.

#### Scenario: A case already in a programme is refused with the holder named
@e2e tests/e2e/parties-and-contact-moments-consume-pipelinq.spec.ts

- **GIVEN** a case already linked to one programme
- **WHEN** it is linked to a second
- **THEN** the act SHALL be refused
- **AND** the refusal SHALL name the programme that already holds it

#### Scenario: An uncomputable progress is said, not shown as zero
@e2e exclude Needs a programme in fromEffort mode without humaniq; ProgrammeConsumerTest::testAnUncomputableProgressIsSaid asserts it.

- **GIVEN** pipelinq answering that progress cannot be computed
- **WHEN** the figure is rendered
- **THEN** it SHALL say the progress cannot be computed
- **AND** it SHALL NOT render zero per cent

### Requirement: The case declares pipelinq's leaves and none of pipelinq's data (REQ-PLQ-09)

The `case` schema SHALL declare pipelinq's contact moments panel and party panel
among its `linkedTypes`, so both render on a case without dossiq querying
pipelinq's register.

Dossiq SHALL declare no party field, no indicator, no party kind, no contact
moment direction vocabulary of pipelinq's, no survey and no programme in its own
register.

#### Scenario: The leaves are declared and the data is not
@e2e exclude A register inspection; PipelinqLeafDeclarationTest::testTheCaseDeclaresBothLeaves and ::testNoPipelinqOwnedSchemaIsDeclared assert it over the shipped fragments.

- **WHEN** dossiq's register is inspected
- **THEN** `case.configuration.linkedTypes` SHALL contain the two pipelinq leaf ids
- **AND** no schema of pipelinq's shall be declared by dossiq

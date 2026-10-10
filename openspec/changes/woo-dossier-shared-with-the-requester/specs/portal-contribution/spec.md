---
status: proposed
---

# Portal contribution: the Woo dossier shared with the requester

## ADDED Requirements

### Requirement: The requester answers a question from Mijn zaken, and the answer lands on the open request (REQ-WDS-001)

The citizen manifest SHALL declare an endpoint action `beantwoordVraag` on the collection `vragenAanU`, for the audiences `citizen` and `client`, scoped to the row's `portalSubject`. It SHALL take `antwoord` (text, required, at most 4000 characters) and up to five files. dossiq SHALL accept it only for a request in the state `open` on a case of the same portal subject; any other request SHALL be refused with 404 and nothing written. On acceptance dossiq SHALL call `AanvullingsverzoekResolutionService::recordAnswer(caseId, received: [], complete: false, userId: 'portal', when: now)`, store the text on the request as the requester's answer, file every attachment on the case as an incoming document of the requester, and call `ApplicantPortalActs::recordWrite()` with act `answer` so the handler is told. The request SHALL stay `open` and the term SHALL stay paused until the handler records the request complete, as `termijn-pause-extension` already requires.

#### Scenario: The requester answers the clarification question
- **GIVEN** a Woo case 2026-0087 with an open `aanvullingsverzoek` of kind `verduidelijking`, asked on 6 October 2026
- **WHEN** the requester sends the answer "Het gaat om de speeltuinen in Oosthaven, 2023 tot nu" through `beantwoordVraag`
- **THEN** the request carries that answer and stays `open`
- **AND** the handler gets one notification and one timeline entry on the case
- **AND** the term of the case is still paused
- e2e: `tests/e2e/woo-dossier-shared-with-the-requester.spec.ts` "the requester answers the clarification question"

#### Scenario: Somebody else's question is not answerable
<!-- @e2e exclude Fail-closed scope check at the portal seam; proven by PortalWooAnswerTest::testAnotherSubjectsRequestIsRefused, which asserts 404 and zero writes. -->
- **GIVEN** an open request whose `portalSubject` is not the caller's
- **WHEN** `beantwoordVraag` names it
- **THEN** the answer is 404 and nothing on the request or the case changes

#### Scenario: An answered request is not answered twice
<!-- @e2e exclude State guard; proven by PortalWooAnswerTest::testAClosedRequestIsRefused. -->
- **GIVEN** a request the handler closed as complete
- **WHEN** `beantwoordVraag` names it
- **THEN** the answer is 404 and nothing is written

### Requirement: The requester reads the decision date and the state of the term (REQ-WDS-002)

On every save of a Woo case, dossiq SHALL write on the portal case `legalDecisionDate`, the date part of the case `deadline` that `one-term-engine` keeps, and `termNote`, one sentence of at most 200 characters. dossiq SHALL compute no date for either. `termNote` SHALL read:

- while an `aanvullingsverzoek` is open: "De termijn staat stil sinds {requestedAt}. Hij loopt weer zodra wij uw antwoord hebben.";
- after an extension (Woo art. 4.4 lid 2): "Wij hebben de termijn met twee weken verlengd. De reden: {verdagingReden}";
- otherwise: empty.

While the term stands still, `legalDecisionDate` SHALL be empty: the date is not known until the term runs again.

#### Scenario: A paused term says why and gives no date
- **GIVEN** a Woo case with an open `aanvullingsverzoek` asked on 6 October 2026
- **WHEN** the case is saved
- **THEN** `termNote` reads "De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben."
- **AND** `legalDecisionDate` is empty
- e2e: `tests/e2e/woo-dossier-shared-with-the-requester.spec.ts` "a paused term says why"

#### Scenario: An extension is told with its reason
<!-- @e2e exclude Text projection; proven by WooPortalTermNoteTest::testAnExtensionCarriesItsReason, which reads verdagingReden from the case and the deadline from a case saved by one-term-engine's mirror. -->
- **GIVEN** a Woo case extended once with `verdagingReden` "Wij moeten derden om hun mening vragen."
- **WHEN** the case is saved
- **THEN** `termNote` reads "Wij hebben de termijn met twee weken verlengd. De reden: Wij moeten derden om hun mening vragen."
- **AND** `legalDecisionDate` is the date part of the case deadline

### Requirement: The requester sees where the decision became public (REQ-WDS-003)

When `wooPublicationStatus` becomes `published`, dossiq SHALL write `resultLink` `{label: "Bekijk wat openbaar is gemaakt", url: wooPublicationUrl}` on the portal case. When it becomes `withdrawn`, dossiq SHALL clear `resultLink`. dossiq SHALL never write a `resultLink` whose url is not the case's `wooPublicationUrl`.

#### Scenario: The link appears after publishing
- **GIVEN** a Woo case whose decision dossiq has just published into OpenCatalogi
- **WHEN** the requester opens the case in Mijn zaken
- **THEN** the case shows "Bekijk wat openbaar is gemaakt", linking to the publication
- e2e: `tests/e2e/woo-dossier-shared-with-the-requester.spec.ts` "the link appears after publishing"

#### Scenario: The link goes with a withdrawn publication
<!-- @e2e exclude State projection; proven by WooPortalResultLinkTest::testWithdrawingClearsTheLink. -->
- **GIVEN** a published Woo case with a `resultLink`
- **WHEN** the publication is withdrawn
- **THEN** `resultLink` is empty

### Requirement: A clarification asks one question in plain words (REQ-WDS-004)

An `aanvullingsverzoek` SHALL accept the kind `verduidelijking` on a case of the Woo case type only. It SHALL carry one `summary` (the question) and no `missingItems`. `recordAnswer()` on it SHALL accept an empty `received` list with `complete` true once an answer is stored. The question the requester reads SHALL be the `summary` as written. The Woo legal ground the handler names (Woo art. 4.1 lid 5) SHALL stay at the desk.

#### Scenario: A clarification is asked on a Woo case
<!-- @e2e exclude Schema and service contract; proven by WooClarificationTest::testAClarificationHasNoItems and ::testItClosesOnceAnswered. -->
- **GIVEN** a Woo case in the status Ontvangst
- **WHEN** the handler asks "Over welke speeltuinen en welke jaren gaat uw verzoek?" as a `verduidelijking`
- **THEN** the request is open with that question and no items
- **AND** the term of the case stands still

#### Scenario: A clarification is refused on another case type
<!-- @e2e exclude Validation; proven by WooClarificationTest::testOnlyAWooCaseTakesAClarification. -->
- **GIVEN** a case of the type Omgevingsvergunning
- **WHEN** a request of kind `verduidelijking` is created on it
- **THEN** it is refused and nothing is stored

### Requirement: The requester never reads the officer's work on a Woo case (REQ-WDS-005)

No collection, field or document the citizen manifest offers SHALL carry a Woo document assessment, the search plan or corpus of the request, a redaction proposal, the name of a reviewer, or a document whose status is not `final` or `archived`. The rule of `PortalCaseDocuments` SHALL hold for the Woo case type without exception.

#### Scenario: Assessments stay at the desk
<!-- @e2e exclude Negative projection; proven by WooRequesterSeesNoDeskWorkTest, which walks every collection and field the citizen manifest declares and fails on any Woo assessment, corpus or reviewer key. -->
- **GIVEN** a Woo case with 40 assessed documents, 12 of them withheld
- **WHEN** the requester opens the case in Mijn zaken
- **THEN** no assessment, ground, reviewer or unpublished document is in any answer to the portal

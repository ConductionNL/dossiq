---
status: proposed
---

# Portal contribution: the Woo dossier shared with the requester

## ADDED Requirements

### Requirement: The requester answers a question from Mijn zaken, and the answer lands on the open request (REQ-WDS-001)

The citizen manifest SHALL declare an endpoint action `beantwoordVraag` on the collection `vragenAanU`, for the audiences `citizen` and `client`, scoped to the row's `portalSubject`. It SHALL take `antwoord` (text, required, at most 4000 characters). It SHALL declare `files: {field: bijlagen, max: 5, maxBytes: 10485760}`, so the requester can add up to five files of at most 10 MB under "Bestanden toevoegen" (board ZaakWooVerzoek); portaliq forwards them multipart (portaliq row-action-carries-files REQ-RAF-001). dossiq SHALL check the number and size again and refuse with 422 before anything is written, and SHALL store each file in the case's folder, where the document projection files it as an incoming document; a file that cannot be stored SHALL be logged and SHALL NOT undo the answer. dossiq SHALL accept it only for a request in the state `open` on a case of the same portal subject; any other request SHALL be refused with 404 and nothing written. On acceptance dossiq SHALL call `AanvullingsverzoekResolutionService::recordAnswer(caseId, received: [], complete: false, userId: 'portal', when: now)`, store the text on the request as the requester's answer, and call `ApplicantPortalActs::recordWrite()` with act `answer`, naming `documents` among the fields when files were stored, so the handler is told. The request SHALL stay `open` and the term SHALL stay paused until the handler records the request complete, as `termijn-pause-extension` already requires.

#### Scenario: The requester answers the clarification question
- **GIVEN** a Woo case 2026-0087 with an open `aanvullingsverzoek` of kind `verduidelijking`, asked on 6 October 2026
- **WHEN** the requester sends the answer "Het gaat om de speeltuinen in Oosthaven, 2023 tot nu" through `beantwoordVraag`
- **THEN** the request carries that answer and stays `open`
- **AND** the handler gets one notification and one timeline entry on the case
- **AND** the term of the case is still paused
- e2e: `tests/e2e/woo-dossier-shared-with-the-requester.spec.ts` "the requester answers the clarification question"

#### Scenario: The answer brings a file
<!-- @e2e exclude Multipart through portaliq's live forward; proven by PortalWooAnswerTest::testTheAttachmentsAreFiledOnTheCase (file stored on the case) and portaliq's PortalRowActionControllerTest::testDeclaredFilesAreForwardedWithTheBody. Live pass: the e2e above with a file added. -->
- **GIVEN** the same open request
- **WHEN** the requester adds `speeltuinen.pdf` under "Bestanden toevoegen" and sends the answer
- **THEN** `speeltuinen.pdf` is stored in the case's folder and shows among the case's incoming documents
- **AND** the handler's notification names the documents with the answer

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

### Requirement: The applicant reads the decision date and the state of the term (REQ-WDS-002)

The applicant term note is a capability of every case type, not of one procedure (decision 182). On every save of a case, dossiq SHALL write on the case, which is the row the portal reads, `legalDecisionDate`, the date part of the case `deadline` that `one-term-engine` keeps, and `termNote`, one sentence of at most 200 characters. dossiq SHALL compute no date for either, and SHALL write only the fields whose value changed. `termNote` SHALL read:

- while a request for completion (`aanvullingsverzoek`) is open, which the case carries as `waitingOnApplicant` with `waitingOnApplicantSince`: "De termijn staat stil sinds {day}. Hij loopt weer zodra wij uw antwoord hebben.", where `{day}` is the day the request was sent, in words;
- after an extension: "Wij hebben de termijn met {period} verlengd. De reden: {reason}", where `{period}` is the case type's `extensionPeriod` in words (`P14D` reads "twee weken") and `{reason}` is the reason of the last `extend` entry in the case's lifecycle journal. A case type whose period cannot be read as one unit gives "Wij hebben de termijn verlengd." A sentence longer than 200 characters SHALL be cut and end in "…";
- otherwise: empty.

While the term stands still, `legalDecisionDate` SHALL be empty: the date is not known until the term runs again. Both fields SHALL be on the fields of the citizen cases collection.

The Woo case type takes part by its configuration alone: its `extensionPeriod` is `P14D` (Woo art. 4.4 lid 2, two weeks), and its extension reason is the reason the handler gives when extending.

#### Scenario: A paused term says why and gives no date
- **GIVEN** a Woo case with an open `aanvullingsverzoek` asked on 6 October 2026
- **WHEN** the case is saved
- **THEN** `termNote` reads "De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben."
- **AND** `legalDecisionDate` is empty
- e2e: `tests/e2e/woo-dossier-shared-with-the-requester.spec.ts` "a paused term says why"

#### Scenario: An extension is told with its reason
<!-- @e2e exclude Text projection; proven by ApplicantTermNoteListenerTest::testAnExtensionWritesItsReasonAndTheTermsDeadline, which reads the reason from the case's journal, the period from the case type, and the deadline the statutory term's listener wrote into the same save. -->
- **GIVEN** a Woo case extended once with the reason "Wij moeten derden om hun mening vragen."
- **WHEN** the case is saved
- **THEN** `termNote` reads "Wij hebben de termijn met twee weken verlengd. De reden: Wij moeten derden om hun mening vragen."
- **AND** `legalDecisionDate` is the date part of the case deadline

#### Scenario: Another case type tells its own period
<!-- @e2e exclude Text projection; proven by ApplicantTermNoteTest::testThePeriodIsToldInWords, which runs the periods case types ship (P14D, P42D, P1M) and the unreadable ones. -->
- **GIVEN** a case of a type whose `extensionPeriod` is `P42D`, extended once with the reason "Het advies is nog niet binnen."
- **WHEN** the case is saved
- **THEN** `termNote` reads "Wij hebben de termijn met zes weken verlengd. De reden: Het advies is nog niet binnen."

#### Scenario: The request closes and the date is back
<!-- @e2e exclude State projection; proven by ApplicantTermNoteListenerTest::testAClosedRequestClearsTheSentenceAndBringsTheDateBack. -->
- **GIVEN** a case whose `termNote` says the term stands still
- **WHEN** the handler closes the request and the case is saved
- **THEN** `termNote` is empty and `legalDecisionDate` is the date part of the case deadline

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

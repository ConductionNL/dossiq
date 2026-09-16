## ADDED Requirements

### Requirement: A case share mints an OpenRegister access link (REQ-CAL-01)

A public case share SHALL be an OpenRegister access link over the case object.
The handler SHALL declare what the holder may do, out of reading, commenting
and uploading. Reading is always granted. The share SHALL carry an expiry,
because OpenRegister refuses to mint a link without one, and MAY carry a
password. Dossiq SHALL store the link's id, uuid and URL on the `caseShare`
record, and SHALL NOT store the anchor, a password or a field-exclusion list.

Revoking the share SHALL revoke the link. Pausing the share SHALL switch the
link off without revoking it, and switching it back on SHALL restore it.

#### Scenario: A handler shares a case with an outsider
@e2e tests/e2e/case-sharing-mints-access-links.spec.ts

- **GIVEN** a case and a handler assigned to it
- **WHEN** the handler shares it with reading and commenting, expiring in 14 days
- **THEN** the share SHALL carry a link URL the handler can copy
- **AND** the share SHALL name reading and commenting as its capabilities
- **AND** no dossiq-minted token SHALL be written on the share

#### Scenario: Revoking the share revokes the link
@e2e exclude unit over the service; CaseAccessLinkServiceTest::testRevokeShareRevokesTheLink

- **GIVEN** a case share carrying an access link
- **WHEN** the handler revokes the share
- **THEN** OpenRegister SHALL be asked to revoke that link by its id
- **AND** the share SHALL record who revoked it and when

#### Scenario: Only the colleague who minted a link can revoke it
@e2e exclude unit over the service; CaseAccessLinkServiceTest::testRevokeByAnotherUserIsReported

- **GIVEN** a link minted by one handler and a second handler on the same case
- **WHEN** the second handler revokes the share
- **THEN** OpenRegister SHALL refuse, because a link is revoked by its minter
- **AND** dossiq SHALL report the refusal rather than reporting a revoke that did not happen

#### Scenario: A share over a case the caller cannot open is refused
@e2e exclude unit over the controller; CaseSharingControllerAccessLinkTest::testCreateShareRefusesAnUnrelatedCase

- **GIVEN** a user who is not assigned to the case
- **WHEN** they ask for a share on it
- **THEN** the answer SHALL be 403
- **AND** no link SHALL be minted

---

### Requirement: A document named on the share gets its own file link (REQ-CAL-02)

A case share MAY name documents. Each named document SHALL mint its own
OpenRegister link with subject type `file`, addressed as
`<objectUuid>/<fileId>`, carrying the share's expiry and password. A holder of
a file link SHALL receive that file and nothing else of the case. Revoking the
share SHALL revoke every file link it minted.

#### Scenario: One report leaves the dossier, the dossier does not
@e2e exclude unit over the service; CaseAccessLinkServiceTest::testSharedDocumentsMintFileLinks

- **GIVEN** a case carrying four documents
- **WHEN** the handler shares one of them
- **THEN** one file link SHALL be minted, addressed as the object uuid and the file id
- **AND** the share SHALL record that link beside the case link
- **AND** the other three documents SHALL have no link

#### Scenario: Revoking the share takes the file links with it
@e2e exclude unit over the service; CaseAccessLinkServiceTest::testRevokeTakesFileLinksWithIt

- **GIVEN** a share carrying a case link and two file links
- **WHEN** the handler revokes the share
- **THEN** all three links SHALL be revoked

---

### Requirement: An external consultation rides the link's comment capability (REQ-CAL-03)

Asking an advisory body outside the organisation for advice SHALL mint a case
link declaring reading and commenting, expiring on the consultation deadline.
The advisory body SHALL be named on the share as its label. The advice SHALL
arrive as a comment written by the link, and dossiq SHALL record it on the
consultation as the response, naming the body it came from.

The token page, its controller and its two routes SHALL be deleted. Nothing
ever minted the token they read, so the surface could never be entered.

#### Scenario: An advisory body answers without an account
@e2e tests/e2e/case-sharing-mints-access-links.spec.ts

- **GIVEN** a consultation on a case, asked of an external advisory body
- **WHEN** the handler invites the body
- **THEN** a link SHALL be minted over the case declaring reading and commenting
- **AND** the link SHALL expire on the consultation deadline
- **AND** the share SHALL name the advisory body

#### Scenario: The comment becomes the advice
@e2e exclude unit over the service; ExternalConsultationLinkServiceTest::testCommentBecomesTheResponse

- **GIVEN** an invited advisory body that has written one comment through its link
- **WHEN** the handler collects the advice
- **THEN** the comment SHALL be recorded as the consultation response
- **AND** the response SHALL name the advisory body
- **AND** a second collection SHALL not record the same comment twice

#### Scenario: A comment by anybody else is not advice
@e2e exclude unit over the service; ExternalConsultationLinkServiceTest::testOtherActorsAreNotAdvice

- **GIVEN** a case carrying comments from colleagues and one from the invited link
- **WHEN** the handler collects the advice
- **THEN** only the comment written by that link SHALL be recorded

#### Scenario: The token page is gone
@e2e exclude static removal check; grep over lib, src and appinfo, verified by ConsultationRoutesTest

- **GIVEN** the dossiq codebase after this change
- **WHEN** `ConsultationPublicController`, `ExternalConsultationResponsePage.vue` and `/api/public/consultations/{token}` are looked for
- **THEN** none SHALL be present

---

### Requirement: The Sharing tab names each link's state, and a holder never reads case internals (REQ-CAL-04)

The case Sharing tab SHALL list every access link on the case with its
capabilities, its expiry, whether it is live, paused, expired or revoked, who
minted it, and how often it has been used. Each live link SHALL offer a revoke.

Before sending a link the handler SHALL be able to see what its holder reads.
Dossiq SHALL reduce that preview to the `@self` keys OpenRegister publishes and
SHALL drop every other `@self` key and every other top-level key beginning with
`@`, so an internal that OpenRegister starts publishing tomorrow does not reach
a holder through dossiq today.

#### Scenario: The handler sees the state of every link
@e2e tests/e2e/case-sharing-mints-access-links.spec.ts

- **GIVEN** a case with a live link, a paused link and an expired link
- **WHEN** the handler opens the Sharing tab
- **THEN** each link SHALL be listed with its state
- **AND** only the live and paused links SHALL offer a revoke

#### Scenario: The preview carries no case internals
@e2e exclude unit over the projection; CaseAccessLinkServiceTest::testPreviewDropsSelfInternals

- **GIVEN** a link body whose `@self` carries an owner, an organisation, a folder and a group
- **WHEN** dossiq prepares the preview
- **THEN** none of those four SHALL be present
- **AND** the uuid, the name and the published date SHALL still be present

#### Scenario: A preview of a link on another case is refused
@e2e exclude unit over the controller; CaseSharingControllerAccessLinkTest::testPreviewRefusesALinkOnAnotherCase

- **GIVEN** a handler assigned to case A and a share on case B
- **WHEN** the handler asks for the preview of case B's share
- **THEN** the answer SHALL be 403

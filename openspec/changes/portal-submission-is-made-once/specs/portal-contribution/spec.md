## ADDED Requirements

### Requirement: A repeated portal submission returns the first one's answer (REQ-PSM-001)

A portal action endpoint that creates a record SHALL read the `Idempotency-Key` header portaliq relays, and SHALL treat a value that is not 16 to 128 characters of `A-Z`, `a-z`, `0-9`, `_` and `-` as no key. With a key, the endpoint SHALL keep it per action and per resident (the verified `sub`, never the body). A send whose key belongs to a send that made a record SHALL answer that send's status and body and SHALL make nothing; a send whose key belongs to a send that is still being made SHALL answer 409 `{error: submission_processing}` and SHALL make nothing; a send that was refused SHALL let its key go. The same key from another resident or for another action SHALL be a key of its own. Without a key every send SHALL be a first send.

#### Scenario: The resident sends again after a slow answer
<!-- @e2e exclude The resend needs a forward that times out; pinned by PortalWooRequestSubmissionKeyTest::testASecondSendWithTheSameKeyGetsTheFirstCase. Live check: task 3.1. -->

- **GIVEN** a Woo request sent with key K that made case 2026-0115
- **WHEN** the resident sends the same answers again with key K
- **THEN** the answer is 201 with case 2026-0115, and no second case exists

#### Scenario: A resend while the first is still being made
<!-- @e2e exclude A race; pinned by PortalWooRequestSubmissionKeyTest::testASendWhileTheFirstIsBusyIs409. -->

- **GIVEN** a send with key K that is still writing its case
- **WHEN** a second send with key K arrives
- **THEN** it answers 409 `submission_processing` and makes nothing

#### Scenario: A refused send can be corrected
<!-- @e2e exclude Pinned by PortalWooRequestSubmissionKeyTest::testARefusedSendLetsTheKeyGo. -->

- **GIVEN** a send with key K refused for an empty subject
- **WHEN** the resident fills in the subject and sends again with key K
- **THEN** the case is made

## ADDED Requirements

### Requirement: A digest names only what its recipient may read (REQ-QUEUE-03a)

The daily digest job SHALL compose each person's digest with that person as the acting user, so every read is answered the way it is answered for them. The previous acting user SHALL be restored even when composing fails. Only the digest record SHALL be written as the background service account. Without a usable account nothing SHALL be composed or sent.

#### Scenario: two recipients, two digests, no leak
@e2e exclude a cron job with no browser gesture; covered by DailyDigestJobServiceAccountTest::testEachDigestListsOnlyWhatItsRecipientMayRead and the live check in the PR

- **GIVEN** a case assigned to alice that only bob may read
- **WHEN** the digest job runs for alice and bob
- **THEN** alice's digest SHALL NOT name that case
- **AND** each digest SHALL name only cases its recipient may read

#### Scenario: the digest record is written as the account
@e2e exclude a cron job with no browser gesture; covered by DailyDigestJobServiceAccountTest::testTheDigestIsSentAsTheAccount

- **WHEN** a digest is sent
- **THEN** the `workDigest` record SHALL be written by the background service account
- **AND** no user SHALL remain signed in after the run

# admin-settings

## ADDED Requirements

### Requirement: An administrator SHALL set the message and portal texts in the admin settings

The admin settings SHALL offer a texts block where an administrator sets the sender name of
dossiq's messages and the text shown above My cases in the portal. Saving SHALL store both as
app settings, and the next message and the next portal page SHALL use them. The block SHALL say
that the websites allowed to call the portal (CORS) are set in the portal app and in Nextcloud,
and SHALL offer no field for them.

Rows: `11.24` (dossiq matrix).

#### Scenario: An administrator changes the portal text
@e2e exclude The page that shows the text is drawn by portaliq, another app, which dossiq's e2e instance does not run. dossiq's part is that the saved setting reaches the portal contribution: `PortalContributionProviderTest::testTheSavedPortalTextIsShownAboveMyCases` reads `portal_intro_text` into My cases' `intro`, and `PortalCaseDeclarationsTest::testTheCaseListCarriesTheOrganisationsIntro` that an empty one adds nothing.

- **GIVEN** an administrator on the admin settings
- **WHEN** they set "Text above My cases in the portal" to "Hier ziet u uw lopende zaken" and save
- **THEN** a resident opening My cases in the portal reads "Hier ziet u uw lopende zaken" above the list

#### Scenario: An administrator changes the sender of messages
@e2e exclude Sending a message has no screen in dossiq, and the e2e instance has no mailbox to read the sender from. Covered by `TermNoticeDeliveryTest::testADueReminderIsMailedOnceWithTheUnsubscribeLink`, which asserts the sent mail carries the `email_from_name` setting as its sender name.

- **WHEN** an administrator sets "Sender of messages" to "Gemeente Zuiddrecht" and saves
- **THEN** the next message dossiq sends carries "Gemeente Zuiddrecht" as its sender name

#### Scenario: CORS is pointed elsewhere
@e2e tests/e2e/admin-message-and-portal-texts.spec.ts

- **WHEN** an administrator looks for the allowed websites
- **THEN** the block says they are set in the portal app and in Nextcloud, and offers no field

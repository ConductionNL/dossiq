# Tasks: woo-receipt-also-by-email

- [x] 1.1 `emailCopy` on the acknowledgement declaration
  - Files: lib/Service/CaseTypeAcknowledgement.php, lib/Settings/register.d/36-ontvangstbevestiging.json
  - Test: tests/Unit/Service/CaseTypeAcknowledgementTest.php
- [x] 1.2 The acknowledgement asks the sender for the copy
  - Files: lib/Service/AcknowledgementService.php, lib/Service/TermijnNotificationService.php
  - Test: tests/Unit/Service/AcknowledgementDutyTest.php
- [x] 1.3 The sender mails the copy and records it
  - Files: lib/Service/Notification/RequesterNoticeSender.php
  - Test: tests/Unit/Service/Notification/RequesterNoticeSenderTest.php
- [x] 1.4 The Woo request case type declares it
  - Files: lib/Settings/register.d/81-woo-verzoek.json
- [ ] 1.5 Live: file a Woo request on the portal and find the receipt in the inbox and in the mailbox

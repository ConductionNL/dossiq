# Tasks: case-mail-through-the-mail-account-with-rfc-8058

Blocked on the nextcloud/mail headers issue (TODO: issue number). Decision 147.

- [ ] 1.1 Record the upstream issue number here and in the proposal once Ruben posts it.
- [ ] 1.2 `NextcloudMailGateway::sendMessage()` passes `List-Unsubscribe` and `List-Unsubscribe-Post` through the header option Mail ships; a Mail release without it keeps the current split.
  - `tests/Unit/Service/Email/OutboundThroughMailAccountTest.php`
- [ ] 1.3 `TermNoticeSender` sends through `OutboundCaseMail`; term notices are filed in the account's sent folder.
  - `tests/Unit/Service/Termijn/TermNoticeDeliveryTest.php`
- [ ] 1.4 Case mail through the Mail account carries the RFC 8058 headers as well as the body link.
- [ ] 1.5 Live: a term notice and a case mail both land in the sent folder with both headers (live pass, decision 139).

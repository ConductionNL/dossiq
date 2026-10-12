# Tasks: case-mail-through-the-mail-account-with-rfc-8058

Blocked on the nextcloud/mail headers issue (TODO: issue number). Decision 147.

- [ ] 1.1 Record the upstream issue number here and in the proposal once Ruben posts it.
- [ ] 1.2 `NextcloudMailGateway::sendMessage()` passes `List-Unsubscribe` and `List-Unsubscribe-Post` through the header option Mail ships; a Mail release without it keeps the current split.
  - `tests/Unit/Service/Email/OutboundThroughMailAccountTest.php`
- [x] 1.3 `TermNoticeSender` sends through `OutboundCaseMail` with kind `notice`, so `mail_transport_by_kind` decides its transport; configured to `mail-account`, a notice leaves through the account with the link in its body.
  - `lib/Service/Termijn/TermNoticeSender.php`, `tests/Unit/Service/Termijn/TermNoticeDeliveryTest.php` (testANoticeConfiguredToTheMailAccountLeavesThroughIt, testANoticeFollowsItsOwnKindNotCaseMail, testAnUnreachableMailAccountKeepsNoClaim)
- [ ] 1.3b Flip the default for `notice` and `service` to `mail-account` once 1.2 ships, so term notices are filed in the account's sent folder with their headers (a configuration change).
- [ ] 1.4 Case mail through the Mail account carries the RFC 8058 headers as well as the body link.
- [ ] 1.5 Live: a term notice and a case mail both land in the sent folder with both headers (live pass, decision 139).

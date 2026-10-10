# All mail leaves through the Mail account, with its RFC 8058 headers

Stub filed under decision 147 (10 Oct). Do not build until the upstream issue lands.

## Why

Decision 165 splits dossiq's outbound mail by kind. A case mail a handler writes leaves through the Nextcloud Mail account, so it is filed in the sent folder next to the replies. Term notices and other service mail stay on Nextcloud's `IMailer`, because they must carry the RFC 8058 `List-Unsubscribe` and `List-Unsubscribe-Post` headers and Nextcloud Mail takes no custom header (inbound-mail-filters REQ-IMF-11).

That split has two costs. Service mail is not in any sent folder, so a handler cannot see what the citizen was sent. And a case mail through the Mail account carries its unsubscribe link in the body only, which misses one-click unsubscribe in Gmail and Yahoo.

Ruben posts the nextcloud/mail issue asking for a headers option on the send path. The draft is `~/memcap-work/build-all/dossiq/nextcloud-mail-headers-issue.md`. Upstream issue: TODO (number once posted).

## What changes

- Once Nextcloud Mail accepts custom headers (the reserved `$options` on `OCP\Mail\Provider` `sendMessage()`, or a `headers` field on its send API), every dossiq mail leaves through the Mail account: case mail, term notices and other service mail.
- `CaseMailOptOut::dress()` hands its RFC 8058 headers to the Mail send instead of to `IMailer`.
- `TermNoticeSender` sends through `OutboundCaseMail`, from the account the case type names or the default account.
- `IMailer` is no longer used for any case or service mail.

## Impact

- `lib/Service/Email/NextcloudMailGateway.php` (`sendMessage()` passes headers), `lib/Service/Termijn/TermNoticeSender.php`, `lib/Service/Email/CaseMailOptOut.php`, `lib/Service/Email/OutboundCaseMail.php`.
- Depends on: nextcloud/mail issue TODO and the Mail release that ships it.

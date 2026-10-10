## ADDED Requirements

### Requirement: Every mail leaves through the Mail account once it takes headers (REQ-IMF-13)

When Nextcloud Mail accepts custom headers on its send path, every message
dossiq sends (case mail, term notices and other service mail) SHALL leave
through a Nextcloud Mail account, carrying the RFC 8058 `List-Unsubscribe`
and `List-Unsubscribe-Post` headers where the mail is not exempt, and SHALL
be filed in the account's sent folder. dossiq SHALL NOT use Nextcloud's
IMailer for case or service mail from then on.

#### Scenario: a term notice is filed in the sent folder with its headers
@e2e exclude Waits on the upstream Mail headers option; the live pass is task 1.5.

- **GIVEN** a Nextcloud Mail release that accepts custom headers
- **WHEN** dossiq sends a term notice
- **THEN** it SHALL leave through the case's Mail account
- **AND** it SHALL carry the `List-Unsubscribe` and `List-Unsubscribe-Post` headers
- **AND** it SHALL be in the account's sent folder

# public-status-page

## ADDED Requirements

### Requirement: The public status page opens without an account (REQ-PSP-001)

The system SHALL serve the case status page to a caller with no session when
the page is declared public in `src/manifest.json` with `config.mode:
"public"` and a route under `/public/`. The page SHALL read the case through
the anonymous `case-tokens` surface and SHALL NOT introduce a second token
path. Every other app page SHALL keep requiring an account.

#### Scenario: a citizen opens the link they were sent

- **GIVEN** a live case token and a browser with no session
- **WHEN** the citizen opens `/public/status/{token}`
- **THEN** the status page is served

#### Scenario: an internal page is not opened by the same door

- **GIVEN** a browser with no session
- **WHEN** it asks for a case page outside `/public/`
- **THEN** the answer is the login, not the page

### Requirement: A case token is minted and handed out on purpose (REQ-PSP-002)

The system SHALL let somebody with rights on a case mint a case token for it,
SHALL record who minted it and for whom, and SHALL allow revoking it. A token
SHALL NOT be minted as a side effect of opening a case.

#### Scenario: a handler gives a citizen a link

- **GIVEN** a case and a handler with rights on it
- **WHEN** the handler mints a token
- **THEN** the link resolves for somebody with no account, and the mint is recorded

#### Scenario: a revoked link stops

- **GIVEN** a minted token that is revoked
- **WHEN** it is opened
- **THEN** nothing about the case is served

## ADDED Requirements

### Requirement: A calling system is an OpenRegister consumer (REQ-PAA-01)

Dossiq SHALL accept a call on its ZGW APIs (`/api/zgw/{component}/v1/...`) only from a caller that resolves to one OpenRegister consumer, and SHALL bind that consumer's Nextcloud user to the request before the controller runs. Dossiq SHALL NOT keep a consumer, key or certificate store of its own.

#### Scenario: A known consumer is bound to the request

- **GIVEN** an OpenRegister consumer "Zaakportaal" with a shared secret and a linked Nextcloud user
- **WHEN** a request arrives on `/api/zgw/zaken/v1/zaken` with a JWT whose issuer is "Zaakportaal", signed with that secret
- **THEN** the request SHALL run as the consumer's user
- @e2e exclude a machine-to-machine call has no browser surface; asserted on the middleware in PHPUnit and in the ZGW Newman collection

#### Scenario: A call without a credential is refused

- **GIVEN** a request on a ZGW endpoint with no `Authorization`, API key or certificate header
- **WHEN** the middleware runs
- **THEN** the response SHALL be 401 and no controller SHALL run
- @e2e exclude a machine-to-machine call has no browser surface; asserted on the middleware in PHPUnit

### Requirement: The caller may present a JWT, an API key or a client certificate (REQ-PAA-02)

Dossiq SHALL accept three credentials, each resolving to a consumer: a bearer JWT signed with the consumer's shared secret (HS256, HS384 or HS512, with `iat` and an unexpired `exp`), an API key in the `X-Api-Key` header matching the key of a consumer of type `apiKey`, and a client certificate SHA-256 fingerprint forwarded by a trusted reverse proxy in the header the administrator configured. The first credential present SHALL decide: a request whose JWT fails SHALL be refused and SHALL NOT be retried with another credential.

#### Scenario: An API key resolves its consumer

- **GIVEN** a consumer of type `apiKey` with key `k-123`
- **WHEN** a request carries `X-Api-Key: k-123`
- **THEN** the request SHALL run as that consumer's user
- **AND** the key SHALL be compared in constant time
- @e2e exclude a machine-to-machine call has no browser surface; asserted on ApiCredentialResolver in PHPUnit

#### Scenario: A certificate is read only from a trusted proxy

- **GIVEN** the certificate header is configured as `X-SSL-Client-SHA256` and a consumer stores that fingerprint
- **WHEN** a request carries the header but comes from an address that is not a trusted proxy
- **THEN** the header SHALL be ignored and the request SHALL be refused with 401
- @e2e exclude a reverse-proxy header has no browser surface; asserted on ApiCredentialResolver in PHPUnit

#### Scenario: A failing JWT does not fall through

- **GIVEN** a request with an expired JWT and a valid API key
- **WHEN** the middleware runs
- **THEN** the response SHALL be 401 naming the expired token
- @e2e exclude a machine-to-machine call has no browser surface; asserted on ApiCredentialResolver in PHPUnit

### Requirement: Every credential is scoped by the consumer's applicatie (REQ-PAA-03)

Dossiq SHALL decide what a resolved consumer may reach from its ZGW applicatie (`heeftAlleAutorisaties`, or the `autorisaties` entries with component, scopes, case type and maximum confidentiality), whatever credential it used. An API key or certificate SHALL NOT reach more than a JWT for the same consumer.

#### Scenario: An API key call outside the applicatie's scope is refused

- **GIVEN** a consumer whose applicatie grants `zaken.lezen` for case type "Omgevingsvergunning" only
- **WHEN** it reads a case of case type "Melding openbare ruimte" with its API key
- **THEN** the response SHALL be 403
- @e2e exclude a machine-to-machine call has no browser surface; asserted in the ZGW Newman collection

### Requirement: An administrator issues and revokes access in OpenRegister (REQ-PAA-04)

An administrator SHALL issue a consumer, its shared secret, API key or certificate fingerprint on OpenRegister's key screen, and the next request with a disabled or deleted consumer, or a removed key, SHALL answer 401. Dossiq's integrations tab SHALL link to that screen and SHALL NOT offer a second place to issue keys.

#### Scenario: A revoked key stops working at once

- **GIVEN** a consumer that called successfully with its API key
- **WHEN** an administrator removes the key in OpenRegister
- **THEN** the next call with that key SHALL answer 401
- @e2e exclude the key is revoked on OpenRegister's screen; the effect is asserted on the resolver in PHPUnit

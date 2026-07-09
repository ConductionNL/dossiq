# Financial Integration — Callback Signature Enforcement Delta

**Spec refs**: `financial-integration` (REQ-TERM-007), ADR-005 (security — authenticated
webhooks must actually authenticate)

## MODIFIED Requirements

### Requirement: Uitbetaling-signaal aan financieel systeem (REQ-TERM-007)

The system SHALL prepare an ERP-ready payment signal with all required metadata via openconnector
and SHALL process the ERP payment-confirmation callback. The callback endpoint MUST be
configured with a shared secret and MUST reject every request with HTTP 401 when that secret is
not configured — an unconfigured secret MUST NEVER be treated as an implicit pass. The secret
MUST be configurable via the procest admin settings UI.

**Feature tier**: MVP

#### Scenario: Payment confirmation callback updates status and notifies burger

- **GIVEN** the ERP sends a payment-confirmation callback via openconnector
- **WHEN** the signed callback arrives with `{referentie, status: betaald, werkelijkeBetaaldatum, betalingsreferentie}` and the configured `dwangsom_callback_secret` matches the request's HMAC-SHA256 signature
- **THEN** the `DwangsomUitbetaling` SHALL be looked up by `referentie` and its status updated to `betaald`
- **AND** a burger notification SHALL be triggered

#### Scenario: Unknown referentie is rejected

- **GIVEN** a callback arrives with a `referentie` that matches no `DwangsomUitbetaling`
- **WHEN** the callback is processed
- **THEN** the system SHALL respond with HTTP 404

#### Scenario: Missing or incorrect signature is rejected (existing, preserved)

- **GIVEN** the callback endpoint has a `dwangsom_callback_secret` configured
- **WHEN** a request arrives whose `X-Procest-Signature` header does not match the HMAC-SHA256 of
  the raw body under that secret
- **THEN** the system SHALL respond with HTTP 401 and MUST NOT process the payload

#### Scenario: Unconfigured secret fails closed (NEW)

- **GIVEN** the `dwangsom_callback_secret` app config value has never been set (empty string)
- **WHEN** any request — signed or unsigned — arrives at the payment-callback endpoint
- **THEN** the system SHALL respond with HTTP 401 and MUST NOT update any `DwangsomUitbetaling`
- **AND** the system SHALL log a `warning`-level entry (not `info`) so the missing configuration is
  operationally visible

#### Scenario: Admin can configure the secret (NEW)

- **GIVEN** an admin opens the procest admin settings page with the financial-integration
  capability enabled
- **WHEN** they view the dwangsom callback section
- **THEN** they SHALL see a field to set `dwangsom_callback_secret` (masked input) and a
  visible warning if it is currently unset

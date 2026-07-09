# Proposal: enforce-dwangsom-callback-signature

kind: code — security fix. Cites ADR-005 (security: authenticated webhook must actually
authenticate) and the existing `financial-integration` spec (REQ-TERM-007), which already MUST-
requires the callback signature to be validated — the implementation silently permits the
opposite.

## Why

`lib/Controller/DwangsomPaymentCallbackController.php` is a `#[PublicPage]` `#[NoCSRFRequired]`
webhook (`POST /api/dwangsom/payment-callback`, confirmed public in `appinfo/routes.php`) that
accepts payment-confirmation callbacks for dwangsom (penalty payment) uitbetalingen and, on a
valid signed request, marks a `DwangsomUitbetaling` as paid and triggers downstream burger
notifications (`openspec/specs/financial-integration/spec.md` REQ-TERM-007: "The system SHALL
prepare an ERP-ready payment signal ... and SHALL process the ERP payment-confirmation callback"
with signature validation as a stated pre-condition of the "betaald" transition).

The signature check has a fail-open default:

```php
// lib/Controller/DwangsomPaymentCallbackController.php:150-156
private function validateSignature(string $rawBody): bool
{
    $secret = (string) $this->appConfig->getValueString('procest', 'dwangsom_callback_secret', '');
    if ($secret === '') {
        $this->logger->info('Dwangsom callback: no secret configured (dev-mode permissive)');
        return true;
    }
    ...
}
```

When `dwangsom_callback_secret` is empty, **every** request to this public endpoint is treated as
validly signed, regardless of origin. That would already be a fail-open pattern worth closing
(OWASP A07 — identification/authentication failures on a financial mutation endpoint), but the
real severity is that **there is no way to configure the secret in production**:

```
$ grep -rn "dwangsom_callback_secret" lib/ src/
lib/Controller/DwangsomPaymentCallbackController.php:152:  $secret = (string) $this->appConfig->getValueString('procest', 'dwangsom_callback_secret', '');
```

That is the *only* reference to the key in the entire codebase — no admin settings field, no
`occ config:app:set` documentation, no setup-wizard step writes it. The endpoint is therefore
permanently in "dev-mode permissive" state on every real deployment: any unauthenticated caller
who knows (or guesses) a case's `referentie` can POST `{referentie, status: "betaald",
werkelijkeBetaaldatum, betalingsreferentie}` and the system will accept it as a genuine ERP
confirmation, flip the uitbetaling to paid, and fire the burger notification — with zero proof the
caller is the configured financial system. This directly contradicts the spec's own MUST-language
("the callback signature SHALL be validated").

## What Changes

- **BREAKING (operational):** `validateSignature()` fails closed (HTTP 401) when
  `dwangsom_callback_secret` is empty, instead of treating an unconfigured secret as an
  automatic pass. Deployments that have not configured the secret will see the callback start
  rejecting requests — this is the intended fix, not a regression, but it must be called out
  before rollout so ops sets the secret ahead of time.
- Add an admin-settings field (existing procest admin settings page / `SettingsController`) to
  configure `dwangsom_callback_secret`, so there is an actual UI path to set it — closing the gap
  that made the fail-open default unavoidable in practice.
- Surface the missing-secret state on the existing setup/health surface (`SetupController::status()`
  / ADR-042 summary step) as a warning when the dwangsom/financial-integration capability is
  enabled but the secret is blank, so admins discover the gap before go-live rather than after an
  incident.
- Extend `openspec/specs/financial-integration/spec.md` REQ-TERM-007 with an explicit MODIFIED
  scenario: an unconfigured secret is a rejected (401) request, never an implicit pass.

# Public API access with keys and certificates

## Why

Capability row 12.19 ("Public data API with certificates or API keys") is rated built: other systems call dossiq's ZGW APIs with a JWT signed with the shared secret of an OpenRegister consumer (`ZgwAuthMiddleware`, `ZgwJwtValidator`), and `AcController` serves the ZGW Autorisaties API over those consumers. No requirement describes this as one capability: who may call, with which credential, scoped to what, and how an administrator issues and revokes access. `zgw-autorisaties-api` specifies the applicatie endpoints only.

Two credential kinds the row names are not accepted today: a static API key and a client certificate. OpenRegister already stores consumers with an API key and its own key screen (board `openregister/OrKoppelingen`, capability `openregister/api-keys`), so dossiq needs no store of its own: it needs to accept what the consumer carries.

## What changes

- A new capability spec `public-api-access` that states the contract as built (one consumer per calling system, JWT per ZGW, scope per applicatie, revoke by disabling the consumer) and adds two credential kinds: an API key header and a client certificate fingerprint forwarded by the reverse proxy.
- `ZgwAuthMiddleware` resolves the caller through one credential resolver that tries the bearer JWT, then the API key, then the certificate, and binds the consumer's user exactly once.
- No new screen in dossiq. Consumers are issued and revoked on OpenRegister's key screen. Dossiq's integrations tab (`DqInstellingen`) links to it.

## Out of scope

- A dossiq consumer store or key screen (OpenRegister owns both).
- Terminating TLS in PHP: the certificate is checked by the reverse proxy, dossiq reads the forwarded fingerprint only from a trusted proxy.

## Impact

- Code: `lib/Middleware/ZgwAuthMiddleware.php`, `lib/Service/ZgwJwtValidator.php`, new `lib/Service/Zgw/ApiCredentialResolver.php`, `lib/Settings/connections.json` (or the admin settings keys in `lib/Service/Settings/ConfigKeys.php`) for the trusted proxy header.
- Specs: new `public-api-access`; `zgw-autorisaties-api` unchanged.
- Row 12.19 cites `public-api-access`.

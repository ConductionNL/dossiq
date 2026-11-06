# Tasks: public-api-access-with-keys-and-certificates

## 1. Resolver

- [ ] 1.1 Add `lib/Service/Zgw/ApiCredentialResolver.php` with `resolve(IRequest $request): ?object` following design D1 (bearer JWT through `ZgwJwtValidator`, then `X-Api-Key`, then the trusted-proxy certificate header). First credential present decides.
- [ ] 1.2 Move the consumer lookup and `bindConsumerUser` out of `ZgwJwtValidator` into the resolver so the user is bound once, whatever the credential; `ZgwJwtValidator` keeps token decoding, signature and timing only.
- [ ] 1.3 API key match: query OpenRegister's `ConsumerMapper` for consumers with `authorizationType` `apiKey`, compare with `hash_equals`.
- [ ] 1.4 Certificate match: read the header named by the new config key `public_api_client_cert_header` (add to `lib/Service/Settings/ConfigKeys.php`, default empty = off) only when the remote address is in `trusted_proxies`; compare the SHA-256 fingerprint with `authorizationConfiguration.certificateFingerprint`, case-insensitive.

## 2. Middleware

- [ ] 2.1 `lib/Middleware/ZgwAuthMiddleware.php::beforeController` calls the resolver instead of `ZgwJwtValidator::validate`; no credential or no match answers 401, the existing applicatie scope check (REQ-PAA-03) runs unchanged afterwards.
- [ ] 2.2 Error bodies name the failing credential kind (token expired, unknown key, untrusted certificate header) without echoing the credential.

## 3. Admin link

- [ ] 3.1 Dossiq's integrations tab (`src/manifest.json` page `Integrations`, board DqInstellingen) gets a link "API-sleutels beheren" to OpenRegister's key screen.

## 4. Tests

- [ ] 4.1 `tests/Unit/Service/Zgw/ApiCredentialResolverTest.php`: JWT ok, API key ok, certificate ok from a trusted proxy, certificate ignored from an untrusted address, bad JWT does not fall through, revoked consumer refused.
- [ ] 4.2 Update `tests/Unit/Middleware/ZgwAuthMiddlewareTest.php` and `tests/Unit/Service/ZgwJwtValidatorTest.php` for the moved lookup.
- [ ] 4.3 Newman: add an API-key request and an out-of-scope API-key request (403) to `tests/integration/dossiq.postman_collection.json`.

## 5. Matrix

- [x] 5.1 Row 12.19 cites `openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access` in `openspec/parity/capabilities.json`.

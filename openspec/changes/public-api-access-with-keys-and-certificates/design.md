# Design: public API access with keys and certificates

## D1. One resolver, three credentials

`ApiCredentialResolver::resolve(IRequest $request): ?Consumer` returns the OpenRegister consumer behind a request, or null. It tries, in order:

1. `Authorization: Bearer <jwt>`: today's `ZgwJwtValidator` path, unchanged (issuer is the consumer name, HMAC with the consumer's shared secret, `iat` and `exp` checked).
2. `X-Api-Key: <key>` (or `Authorization: ApiKey <key>`): the consumer whose `authorizationType` is `apiKey` and whose stored key matches, compared with `hash_equals`.
3. The client certificate fingerprint in the header named by admin setting `public_api_client_cert_header` (default empty, which switches certificate access off). Read only when `REMOTE_ADDR` is in Nextcloud's `trusted_proxies`; the consumer whose `authorizationConfiguration.certificateFingerprint` (SHA-256, hex, case-insensitive) matches.

The first credential present decides: a request carrying a bad JWT is refused, it does not fall through to the API key. That keeps a failure explainable.

## D2. Scope comes from the applicatie, whatever the credential

Every credential resolves to the same consumer, and the consumer's ZGW applicatie (`heeftAlleAutorisaties` or its `autorisaties` list) decides which component, scope and case type the call may reach. An API key is not a wider pass than a JWT.

## D3. Revoke means the consumer stops resolving

Disabling or deleting the consumer in OpenRegister, or removing its key, makes the next request answer 401. Dossiq caches nothing about a consumer between requests.

## D4. Generic code

The resolver is named by what it does and knows no procedure (decision 182). It lives under `lib/Service/Zgw/` next to the validator because the ZGW middleware is its only caller today.

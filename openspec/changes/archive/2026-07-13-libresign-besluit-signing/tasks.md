## 1. Status mapping (pure, no I/O)

- [x] 1.1 Add `lib/Service/Beschikking/LibresignStatusMapper.php` — `map(string $raw): string`
      returning `PENDING`/`SIGNED`/`DECLINED`/`UNKNOWN` per the table in `design.md` §3
- [x] 1.2 Unit test every mapped value plus an unrecognised/unexpected input (asserts `UNKNOWN`)

## 2. Thin HTTP client

- [x] 2.1 Add `lib/Service/Beschikking/LibresignApiClient.php` (`IClientService`-based,
      `OCS-APIREQUEST` + Basic Auth per `design.md` §2) with `requestSignature()` and
      `getStatus()`
- [x] 2.2 Transport/decode failures throw `RuntimeException('libresign_api_error')` — never
      return a partially-decoded array
- [x] 2.3 Unit tests mocking `IClientService`/`IClient`/`IResponse` — correct payload shape sent,
      correct envelope unwrapping, transport-failure path

## 3. Signing adapter

- [x] 3.1 Add `lib/Service/Beschikking/LibresignSigningAdapter.php` implementing
      `SigningAdapterInterface` exactly (`sign()`, `fetchValidationReport()`)
- [x] 3.2 Feature-gate: re-check `IAppManager::isEnabledForUser('libresign')` at call time;
      throw `RuntimeException('libresign_unavailable')` when false
- [x] 3.3 Signer resolution via `IUserManager` per `design.md` §4, including the
      missing/incomplete-data path (`libresign_signer_unresolvable`)
- [x] 3.4 Request-signature + bounded status poll (`IAppConfig`-configurable attempts/interval,
      injectable sleeper for tests) mapping LibreSign status via `LibresignStatusMapper`
- [x] 3.5 On `SIGNED`: read the signed file via `IRootFolder` by-id lookup, persist via the
      **existing** `ZgwDocumentService::storeRaw()`, return the full `sign()` contract
- [x] 3.6 On `DECLINED`: throw `RuntimeException('libresign_signing_declined')`
- [x] 3.7 On still-`PENDING`/`UNKNOWN` after the poll window: throw
      `RuntimeException('libresign_signing_pending')`
- [x] 3.8 `fetchValidationReport()` degrades to a structured-but-`geldig:false` report on
      transport failure rather than throwing (matches `MockSigningAdapter`'s always-answers shape)
- [x] 3.9 Unit tests: request payload building (file id + signer list), signer resolution
      (including missing-email case), status-mapping-driven branches (signed/declined/pending/
      unknown), unavailable-app fallback, and the signed-file storage call asserting it hits
      `ZgwDocumentService::storeRaw()` with the right uuid/content — all HTTP mocked at
      `LibresignApiClient` only

## 4. DI wiring + fallback

- [x] 4.1 `lib/AppInfo/Application.php`: replace the static `SigningAdapterInterface` alias with
      the `isEnabledForUser('libresign')` factory closure from `design.md` §6 (falls back to the
      existing `MockSigningAdapter`, logs the translated hint)

## 5. Controller + admin-settings hint

- [x] 5.1 `lib/Controller/BeschikkingController.php::mapRuntime()`: add the four new domain codes
      from `design.md` §7
- [x] 5.2 `lib/Controller/SettingsController.php::index()`: add `libresignAvailable` (mirrors
      `openRegisters`) and translated `libresignHint`
- [x] 5.3 `l10n/en.json` / `l10n/nl.json`: add the new hint string(s) (English keys, both
      catalogues populated)

## 6. Spec + traceability

- [x] 6.1 `specs/libresign-besluit-signing/spec.md` — scenarios for: signature-request creation,
      signer resolution from mandaat data (incl. missing/incomplete), status
      polling/mapping (pending/signed/declined/unknown), signed-file storage into the zaakdossier,
      LibreSign-unavailable fallback with admin hint
- [x] 6.2 `openspec validate libresign-besluit-signing --type change --strict` passes
- [x] 6.3 Add `@spec openspec/changes/libresign-besluit-signing/specs/libresign-besluit-signing/spec.md`
      to every new/changed method

## 7. Verification

- [x] 7.1 Full PHPUnit suite green (not just the new tests)
- [x] 7.2 Full vitest suite green
- [x] 7.3 `npm run build` exits 0
- [x] 7.4 Archive the change under `openspec/changes/archive/2026-07-13-libresign-besluit-signing/`

## Follow-ups (out of scope for this change, noted per design.md §1)

- [ ] F.1 Idempotent re-request on retry of a still-`pending` `onderteken()` call (currently each
      retry creates a fresh LibreSign signature request)
- [ ] F.2 A background job to poll long-running LibreSign requests and auto-complete `onderteken()`
      once signed, instead of requiring the medewerker to retry manually

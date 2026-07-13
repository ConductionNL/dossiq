# Proposal: libresign-besluit-signing

kind: code — new capability. Provides the first concrete implementation of the beschikking
pipeline's `SigningAdapterInterface` (`lib/Service/Beschikking/SigningAdapterInterface.php`),
binding it to LibreSign (LibreCode), the Nextcloud-native eIDAS-aligned digital signing app.

## Why

The beschikking (decision/ruling document) lifecycle — compose → akkoord (mandaat) → onderteken
(sign) → verzend → archive — is fully implemented in `BeschikkingService`, but the "onderteken"
leg only has an abstract contract (`SigningAdapterInterface`) and a deterministic stub
(`MockSigningAdapter`). The interface's own docblock says the real implementation was expected to
come from an OpenConnector eIDAS-TSP integration (task T23) that has not been built. Without a
concrete adapter, no beschikking can ever be digitally signed inside the platform — the "sign"
leg is permanently mocked in every deployment.

LibreSign is already the Nextcloud-ecosystem-native answer to qualified/advanced electronic
signing (X.509/RFC 5280 certificates, PKCS#12, RSA-4096/ECC, optional HSM, eIDAS/UETA/ESIGN
alignment) and, unlike a bespoke TSP integration, ships as an installable Nextcloud app — so
procest can integrate with it as a peer app on the same instance rather than standing up new
external infrastructure.

No LibreSign checkout was present in this environment (`apps-extra/`, `apps/`) to confirm exact
route/response shapes; this change implements against the published LibreSign v1 API shape from
public documentation/knowledge (LibreCode/libresign), with every assumption called out explicitly
in `design.md`.

## What Changes

- **NEW**: `LibresignSigningAdapter` (`lib/Service/Beschikking/LibresignSigningAdapter.php`)
  implements the existing `SigningAdapterInterface` exactly (`sign()`,
  `fetchValidationReport()`) — no parallel interface, no new pipeline path.
- **NEW**: `LibresignApiClient` (`lib/Service/Beschikking/LibresignApiClient.php`) — the single
  thin HTTP boundary for every outbound call to LibreSign's local OCS API, built on
  `IClientService` (matching the pattern already used by `KvkApiAdapter` /
  `HaalCentraalBrpAdapter`).
- **NEW**: `LibresignStatusMapper` (`lib/Service/Beschikking/LibresignStatusMapper.php`) — pure
  mapping of LibreSign's status vocabulary onto procest's internal pending/signed/declined/unknown
  vocabulary.
- **MODIFIED**: `lib/AppInfo/Application.php` — `SigningAdapterInterface` is now bound via a
  factory closure that checks `IAppManager::isEnabledForUser('libresign')`: enabled →
  `LibresignSigningAdapter`; disabled/absent → the existing `MockSigningAdapter` (unchanged
  pre-existing behaviour), with a logged, translated admin-facing hint.
- **MODIFIED**: `lib/Controller/BeschikkingController.php` — `mapRuntime()` gains mappings for the
  new domain outcomes (`libresign_unavailable`, `libresign_signer_unresolvable`,
  `libresign_signing_pending`, `libresign_signing_declined`).
- **MODIFIED**: `lib/Controller/SettingsController.php` — `index()` exposes
  `libresignAvailable` (mirrors the existing `openRegisters` flag) and a translated
  `libresignHint` so the admin settings surface can show the same hint the logs carry.
- **MODIFIED**: `l10n/en.json` / `l10n/nl.json` — new translation catalogue entries for the
  admin-facing hint text (English keys, both catalogues populated, per project i18n convention).
- Signed files are persisted through the **existing** `ZgwDocumentService::storeRaw()` binary
  storage path — no new file-storage mechanism is introduced.

## Capabilities

### New Capabilities
- `libresign-besluit-signing`: LibreSign-backed digital signing of beschikking PDFs, with signer
  identity resolved from the case's mandaat-authorised actor, status polling/mapping, signed-file
  storage into the zaakdossier's existing storage path, and a documented unavailable/fallback path.

### Modified Capabilities
- None — `beschikking-generatie`'s `SigningAdapterInterface` contract (task T23) is fulfilled, not
  changed.

## Impact

- **Backend**: `lib/Service/Beschikking/LibresignSigningAdapter.php` (new),
  `lib/Service/Beschikking/LibresignApiClient.php` (new),
  `lib/Service/Beschikking/LibresignStatusMapper.php` (new),
  `lib/AppInfo/Application.php`, `lib/Controller/BeschikkingController.php`,
  `lib/Controller/SettingsController.php`.
- **i18n**: `l10n/en.json`, `l10n/nl.json`.
- **No frontend component changes** beyond the two new `SettingsController::index()` response
  fields (existing consumers are unaffected — new keys are additive).
- **No new composer/npm dependencies** — outbound HTTP uses Nextcloud's `IClientService`; file
  access uses `IRootFolder`/`IUserManager`, both already used elsewhere in this app.
- **Feature-gated**: procest installs, builds, and runs unchanged when LibreSign is absent —
  the factory falls back to the pre-existing `MockSigningAdapter`.

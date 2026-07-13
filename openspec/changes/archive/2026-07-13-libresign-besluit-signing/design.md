# Design: libresign-besluit-signing

## 1. The contract being implemented

**Interface**: `OCA\Procest\Service\Beschikking\SigningAdapterInterface`
(`lib/Service/Beschikking/SigningAdapterInterface.php`)

```php
interface SigningAdapterInterface
{
    /**
     * @return array<string, string> keyed: signedBestandId, validatieRapportId,
     *         certificaatSerienummer, tspProviderEidasId, ondertekeningTijdstip
     */
    public function sign(string $bestandId, string $ondertekenaar, string $tspProvider): array;

    /** @return array<string, mixed> the validatierapport contents */
    public function fetchValidationReport(string $validatieRapportId): array;
}
```

Today the only implementation is `MockSigningAdapter` (deterministic stub). This change adds
`LibresignSigningAdapter`, a second, real implementation of the **same** interface — no new
interface method, no new pipeline branch in `BeschikkingService`. `BeschikkingService::onderteken()`
and `::exportAuditPacket()` are unchanged; they keep calling `$this->signingAdapter->sign(...)`
and `->fetchValidationReport(...)` exactly as before.

### Why the interface stays synchronous

Real LibreSign signing is asynchronous in general (a human signer completes the act out of band,
potentially much later). `SigningAdapterInterface::sign()` is synchronous and
`BeschikkingService::onderteken()` transitions the beschikking straight to `ondertekend` on
return. Redesigning that state machine is out of scope (the task is to wire a concrete adapter
into the *existing* contract, not to redesign the pipeline). `LibresignSigningAdapter::sign()`
therefore:

1. Creates the LibreSign signature request.
2. Performs a short, bounded status poll (attempts × interval, both configurable via
   `IAppConfig`, defaults `3` × `2s` — generous enough for an embedded/instant-sign flow, short
   enough not to hang an HTTP request for long).
3. If the request reaches `signed` within the poll window: downloads the signed PDF and returns
   the full `sign()` contract (unchanged happy path for `BeschikkingService`).
4. If the request is `declined` at any point: throws `RuntimeException('libresign_signing_declined')`.
5. If still `pending`/`unknown` after the poll window: throws
   `RuntimeException('libresign_signing_pending')` — the caller (medewerker) is expected to retry
   `onderteken()` later (e.g. after the external signer has completed LibreSign's own UI); the
   LibreSign request itself is **not** re-created on retry in the current build (idempotent
   re-request is out of scope for this change and is called out in `tasks.md` as a documented
   follow-up).

This is a deliberate, documented trade-off: it fits LibreSign into the existing synchronous
contract without inventing a parallel pipeline, at the cost of the caller possibly needing to
retry `onderteken()` for signers who do not complete the act within the poll window.

## 2. LibreSign API binding

**No LibreSign checkout was present** in `apps-extra/` or `apps/` in this environment to confirm
exact routes/payloads. The binding below follows LibreSign's published v1 OCS API shape
(LibreCode/libresign) from general knowledge; every field name is an assumption isolated entirely
inside `LibresignApiClient` so a future correction is a one-file change.

All outbound HTTP calls live in **one** class: `lib/Service/Beschikking/LibresignApiClient.php`,
built on Nextcloud's `OCP\Http\Client\IClientService` (the same pattern `KvkApiAdapter` and
`HaalCentraalBrpAdapter` already use for outbound calls — no cURL, no new HTTP library).

### Base URL & auth

- Base URL: `IURLGenerator::getBaseUrl()` (the instance's own absolute URL) — LibreSign is called
  as a **local peer app on the same Nextcloud instance**, not a remote service.
- Auth: HTTP Basic, using a configurable service-account UID + Nextcloud app password
  (`IAppConfig` keys `procest.libresign_service_uid` / `procest.libresign_service_app_password`,
  set by an admin the same way any NC app-password is generated), plus the `OCS-APIREQUEST: true`
  header Nextcloud's OCS routes require for non-browser clients. This mirrors the standard
  Nextcloud app-to-app OCS calling convention. **Assumption**: LibreSign does not expose a
  dedicated service-to-service token in the version this was designed against; if/when it does,
  only `LibresignApiClient`'s auth-header construction changes.

### Routes used

| Purpose | Method | Path | Assumption source |
|---|---|---|---|
| Create signature request | POST | `/ocs/v2.php/apps/libresign/api/v1/request-signature` | LibreSign "request-signature" endpoint |
| Poll status | GET | `/ocs/v2.php/apps/libresign/api/v1/file/validate/uuid/{uuid}` | LibreSign "validate" endpoint |

Both requests carry `OCS-APIREQUEST: true` and `Accept: application/json`; all bodies are JSON.

### Request/response shapes

`requestSignature(int $fileId, string $documentName, array $signers)`:

```json
POST /ocs/v2.php/apps/libresign/api/v1/request-signature
{
  "file": { "fileId": 12345 },
  "name": "beschikking-12345",
  "status": 1,
  "users": [
    { "identify": { "email": "medewerker@example.nl" }, "displayName": "J. Jansen" }
  ]
}
```

Response envelope (OCS-wrapped): `{"ocs":{"data":{"uuid":"<request-uuid>","status":1,...}}}`.
`LibresignApiClient::requestSignature()` returns the decoded `ocs.data` array.

`getStatus(string $uuid)`:

```
GET /ocs/v2.php/apps/libresign/api/v1/file/validate/uuid/{uuid}
```

Response `ocs.data` assumed shape:

```json
{
  "uuid": "<request-uuid>",
  "status": 3,
  "statusText": "signed",
  "file": { "signedFileId": 67890 },
  "signers": [ { "email": "medewerker@example.nl", "status": "signed" } ]
}
```

`LibresignApiClient::getStatus()` returns the decoded `ocs.data` array unchanged; all
interpretation (status mapping, signed-file extraction) happens in
`LibresignSigningAdapter`/`LibresignStatusMapper`, not the client.

Any transport failure (non-2xx, connection error, malformed JSON) makes `LibresignApiClient`
throw `RuntimeException('libresign_api_error')`; it never returns a partially-decoded array.

## 3. Status mapping

`LibresignStatusMapper::map(string $raw): string` — pure function, no I/O, independently unit
tested. Maps LibreSign's `statusText` (preferred) or numeric `status` (fallback) onto one of four
internal constants:

| Internal | LibreSign `statusText` | LibreSign numeric `status` |
|---|---|---|
| `PENDING` | `draft`, `able_to_sign`, `partial_signed`, `pending` | `0`, `1`, `2` |
| `SIGNED` | `signed` | `3` |
| `DECLINED` | `deleted`, `declined`, `rejected`, `cancelled` | `4` |
| `UNKNOWN` | anything else | anything else |

An `UNKNOWN` result is treated as `PENDING` by the caller (never optimistically treated as
signed) and logged at `warning` so an unexpected LibreSign status vocabulary change is visible
operationally rather than silently mis-signing a beschikking.

## 4. Signer resolution from mandaat data

`SigningAdapterInterface::sign()` only receives `$bestandId`, `$ondertekenaar` (a Nextcloud UID),
and `$tspProvider` — it does not receive the beschikking or its `mandaatGegeven` block directly.
`BeschikkingService::onderteken()` is only reachable after `akkoord()` has already verified the
mandaat (`verifyMandaat()`/`resolveNiveauForUser()`) and recorded `mandaatGegeven.akkoordDoor`;
by the time `sign()` runs, `$ondertekenaar` **is** the mandaat-authorised actor's UID.

`LibresignSigningAdapter` resolves that UID into a concrete LibreSign signer identity via
`IUserManager::get($ondertekenaar)`:

- UID resolves to an `IUser` with a non-empty `getEMailAddress()`: signer =
  `{"identify": {"email": <email>}, "displayName": <getDisplayName()>}`.
- UID does not resolve, **or** resolves but has no configured email (incomplete mandaat/user
  data): `sign()` throws `RuntimeException('libresign_signer_unresolvable')` **before** any
  LibreSign API call is made. Decided behaviour: procest requires a resolvable NC account with an
  email for LibreSign signing — there is no silent fallback to a placeholder address, because
  that would send (or fail to send) a legally meaningful signature request to nobody. The
  controller maps this to HTTP 422 with a message pointing at completing the user's profile.

## 5. Signed-file storage — the existing path

On `SIGNED`, `LibresignSigningAdapter`:

1. Reads the signed file's raw bytes via `IRootFolder::getUserFolder($ondertekenaar)->getById($signedFileId)`
   (the same by-id file lookup pattern `ParafeerActieService::applyPdfSignature()` already uses —
   no new file-lookup mechanism).
2. Persists those bytes through the **existing** `ZgwDocumentService::storeRaw($bestandId, $fileName, $content)`
   binary storage service (the same service `ZaakdossierService` already uses for every other
   zaakdossier document) — this is the "EXISTING document storage path" the beschikking pipeline
   already relies on; no new storage path is introduced.
3. Returns the new Nextcloud file id from `storeRaw()` as `signedBestandId`, so
   `BeschikkingService::onderteken()`'s existing `samengesteldeInhoud.bestandId` overwrite keeps
   working unmodified.

## 6. Fallback semantics when LibreSign is unavailable

**Feature gate**: `IAppManager::isEnabledForUser('libresign')` (queried with no user context —
i.e. "is LibreSign enabled at all", matching how `MockSigningAdapter` was a blanket fallback
regardless of which user is signing).

The gate is evaluated **once, in the DI wiring** (`lib/AppInfo/Application.php`), not per-call
inside the adapter:

```php
$context->registerService(
    SigningAdapterInterface::class,
    static function (ContainerInterface $c): SigningAdapterInterface {
        $appManager = $c->get(IAppManager::class);
        if ($appManager->isEnabledForUser('libresign') === true) {
            return $c->get(LibresignSigningAdapter::class);
        }

        $c->get(LoggerInterface::class)->warning(
            $c->get(IL10N::class)->t(
                'LibreSign is not installed or enabled. Digital signing falls back to the '
                .'built-in stub adapter — install and enable the LibreSign app to sign '
                .'beschikkingen with a real eIDAS-aligned signature.'
            ),
            ['app' => Application::APP_ID]
        );

        return $c->get(MockSigningAdapter::class);
    }
);
```

Consequences:

- procest **never** hard-depends on LibreSign: composer.json/package.json are untouched, and a
  clean install/build/run with LibreSign absent resolves `SigningAdapterInterface` to the
  pre-existing `MockSigningAdapter` exactly as before this change.
- The pipeline's behaviour when LibreSign is absent is byte-for-byte the pre-existing behaviour
  (same class, same deterministic stub output) — "falls back to its current signing behaviour" is
  literal, not simulated.
- `LibresignSigningAdapter` itself also defends against a race (LibreSign disabled between DI
  resolution and the call, e.g. mid-request app toggle) by re-checking `isEnabledForUser` at the
  top of `sign()`/`fetchValidationReport()` and throwing `RuntimeException('libresign_unavailable')`
  if it fires — `BeschikkingController::mapRuntime()` maps this to HTTP 503.
- **Admin-settings hint**: surfaced two ways — (a) the translated warning above, visible via
  Nextcloud's own log viewer (Settings → Logging), and (b) `SettingsController::index()` now
  returns `libresignAvailable: bool` and `libresignHint: string|null` (mirroring the existing
  `openRegisters` flag), so the procest admin settings screen has the same data available today's
  `openRegisters` banner uses, without inventing a new settings-surface pattern.

## 7. New RuntimeException domain codes (BeschikkingController::mapRuntime())

Following the existing convention (`not_found`, `invalid_transition`, `mandaat_insufficient`,
`immutable`, `zaakId_required` are all plain `RuntimeException` messages used as domain codes):

| Code | HTTP status | Meaning |
|---|---|---|
| `libresign_unavailable` | 503 | LibreSign toggled off between DI resolution and call (rare race) |
| `libresign_signer_unresolvable` | 422 | `$ondertekenaar` has no resolvable NC account/email |
| `libresign_signing_pending` | 202 | Request created; signer has not completed signing within the poll window |
| `libresign_signing_declined` | 409 | Signer declined/request was cancelled in LibreSign |

## 8. Testing boundary

Per project policy, all HTTP interaction is mocked at the `LibresignApiClient` boundary only
(`IClientService`/`IClient`/`IResponse` mocks live in `LibresignApiClientTest`; every other test —
`LibresignSigningAdapterTest`, `LibresignStatusMapperTest` — mocks `LibresignApiClient` itself, so
no test performs real HTTP).

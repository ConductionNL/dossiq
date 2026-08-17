# Proposal: woo-publication-in-process-object-writes

## Summary

Move procest's WOO publication writes off a **self-addressed HTTP call to
OpenRegister's Objects API** and onto OpenRegister's **published in-process
contract** (`ObjectServiceInterface`, ADR-084). `OpenCatalogiApiClient` keeps
its public method surface and its error contract; only the transport under it
changes. The service-account credentials the HTTP hop needed
(`opencatalogi_service_uid` / `opencatalogi_service_app_password`) stop being
consulted for publication writes.

This is the change ADR-080 D2/D3 asks for and hydra gate-62 (`store-plane`)
names: `lib/Service/WooPublication/OpenCatalogiApiClient.php` builds and
fetches an OpenRegister objects-API URL with `IClientService`.

## Motivation

`WooPublicationService` publishes a WOO besluit to OpenCatalogi's publication
register. OpenCatalogi exposes no write endpoint of its own, so both
OpenCatalogi and procest write through OpenRegister's generic Objects API.
procest did that over HTTP, to its own instance:

```
IURLGenerator::getBaseUrl() + /index.php/apps/openregister/api/objects/…
```

That is an app on a Nextcloud instance making an authenticated HTTP request to
the same Nextcloud instance. It costs a full request cycle per object, needs a
stored service account with a real app password, and re-implements — in
procest — routing, envelope handling and error mapping that OpenRegister
already publishes as a PHP contract.

**Why not `GenericStoreService`** (the class gate-62's message points at):
measured against `openregister@development`, its public surface is exactly
`isConfigured()`, `search()`, `resolve()` — no create, no patch, no file
attach, which is all three of the operations this client performs — and
`SecurityService::assertSafeFetchUrl()` ends in
`filter_var(..., FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)` and
**throws** when that fails. The store plane is built for REMOTE stores; a
same-instance URL fails closed on the first call. The correct target is the
ADR-084 contract, which is what this change adopts.

## Affected Projects

- [x] Project: procest

## What changes

1. **Publication create / update / document-create go through
   `ObjectServiceInterface`.** `createPublication()` and `attachDocument()`
   call `saveObject(object:, register:, schema:)`. `updatePublication()` does
   a **read-merge-write**: `findSilent()` the stored object, shallow-merge the
   partial payload over `getObject()`, then `saveObject(..., uuid: $id)`.

2. **`attachFile()` goes through OpenRegister's `FileService::addFile()`,
   resolved from the DI container** the same way `SettingsService` already
   resolves `ObjectService` and `ApprovalService`. See the contract gap below —
   this one operation has no published-contract equivalent.

3. **`resolveCatalog()` stays on `IClientService`.** It reads
   `/index.php/apps/opencatalogi/api/catalogi`, which is OpenCatalogi's own
   app API, not OpenRegister's Objects API. ADR-080 D2/D3 and gate-62 are about
   the latter. Nothing about that call changes.

4. **The OpenRegister objects-API path constants are deleted** from the client,
   along with the POST/PATCH transport branches that only served them.

## The defect writing this spec exposed

⚠️ **`ObjectServiceInterface::updateObject()`'s docblock says "Apply a partial
update to an existing object." The implementation is a full replace.** Read at
`openregister@origin/development`:

```php
public function updateObject(string $objectId, array $data, …): ObjectEntity {
    $data['id'] = $objectId;
    return $this->saveObject(object: $data);
}
```

There is no merge. `saveObject()` is PUT-semantic — OpenRegister's own
`patchObject()` docblock states it outright: *"a property absent from the
payload is written as null — so every partial-write caller either merges first
or silently nulls live data."* The one method that really does merge,
`patchObject()`, is **not on `ObjectServiceInterface`** (the contract publishes
25 methods and that is not one of them).

This matters here and not in the abstract: `withdraw()` sends the single-key
payload `['depublicatiedatum' => now]`. Routed through `updateObject()`,
withdrawing a publication would have **erased its title, summary, description,
publication date, category, status and case reference** — while reporting
success. The HTTP `objects#patch` route this client used does
`findSilent()` → `array_merge($existing, $patch)` → `saveObject(uuid:)`, so the
migration has to reproduce that merge in procest, and it does.

**Reported, not fixed here:** the interface docblock and the implementation
disagree, and the fleet's only merging write is unpublished. That belongs in
`ConductionNL/openregister`.

## Contract gap: no file operation is published

`ObjectServiceInterface` publishes no file API. OpenRegister's HTTP
`files#create` route runs `FileService::addFile()`, and `FileService` is not a
published contract. The three options were: drop the file attach (a behaviour
loss — the redacted PDFs are the point of a WOO publication), keep the HTTP
call (gate-62 stays red, and the ADR is not obeyed), or call `FileService`
in-process. This change takes the third and records the gap.

Also measured while doing so: **`files#create` never used the `mimeType` the
client sent.** `FileService::addFile(objectEntity, fileName, content, share,
tags)` has no MIME parameter, and `FilesController::create()` reads only
`name`/`filename`, `content`, `share` and `tags` out of the request body. The
parameter is kept on `attachFile()` — removing it would change the call shape
at the one caller — and is documented as accepted-but-unused rather than
quietly dropped.

## Behaviour that changes on purpose

- **Acting identity.** The HTTP hop authenticated as a configured service
  account. In-process calls act as the session user, with `_rbac` and
  `_multitenancy` at their contract defaults (`true`). A publish therefore now
  requires the acting user to be permitted to write in the publication
  register, instead of borrowing the service account's rights. This is a
  tightening, and it is the point of ADR-084's default-on flags.
- **The internal read is stricter.** OpenRegister's own `objects#patch` reads
  the object with `_rbac: false, _multitenancy: false` before merging. This
  change reads it with both at their defaults, so the merge cannot pull in an
  object the caller may not see.
- **The service-account app config keys stop being read for publication
  writes.** They still gate `resolveCatalog()`. They are not removed — an
  installed deployment may still carry them and removal is a config migration.

## Out of scope

- Fixing `updateObject()` / publishing `patchObject()` in OpenRegister.
- Publishing a file contract in OpenRegister.
- Removing the service-account config keys.

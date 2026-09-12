# ZGW API Test Observations

Inconsistencies and observations found while implementing ZGW API compliance.
These should be raised with the VNG ZGW API test suite developers.

## Measured test results

Measured 2026-09-12 on a clean Nextcloud 34.0.3 with postgres 16, openregister
and dossiq at `development`, provisioned the way the shared workflow's Newman
job provisions: `occ app:enable` for both apps, then
`tests/zgw/seed-consumers.sh` as the `newman-seed-command`. Newman 6.2.2. Both
collections run whole, counted over every execution rather than a slice.

**Before the fixes in #2569**

| collection | requests | requests failed | assertions | assertions failed |
|---|---|---|---|---|
| ZGW OAS tests | 191 | 90 | 177 | 127 |
| ZGW business rules | 646 | 185 | 354 | 289 |

416 of 531 assertions failed, so 78%. `code-quality.yml` recorded "95%+ failure
rate" as the reason for `enable-newman: false`; that number was never measured.

275 requests were never sent at all, because an earlier create never returned
the URL they interpolate. Most of the red was one cascade, not 275 defects.

**The earlier figure in this file was wrong.** It said "165/177 assertions pass
(93.2%)" and "remaining 12 failures: all caused by VNG test collection bugs".
Four dossiq defects were in front of it the whole time: two routes pointing at
mapping keys nothing writes, a catalogue schema that did not exist, a throttle
of 30 writes a minute against a suite that peaks at 180, and an array property
written as a JSON string. See #2569.

**What the collection itself still gets wrong** is documented below and is real.
It only became observable once the setUp stopped failing: a section whose
prerequisite 400s reports its own bugs as ENOTFOUND on an unset variable.

## Status Code Inconsistencies

### Publish endpoints (POST `/{type}/{uuid}/publish`)
- **ZTC test section** ("zaaktypen test Mat"): Expects **200** for publish zaaktype
- **BRC setup section**: Expects **201** for publish besluittype (as a setUp prerequisite)
- **DRC setup section**: Expects **201** for publish informatieobjecttype (as a setUp prerequisite)
- **ZRC setup section**: Expects **201** for publish zaaktype (as a setUp prerequisite)
- **Observation**: Same POST `/publish` endpoint is expected to return different status codes depending on which test section calls it. The ZGW OpenAPI spec says POST returns 200 for publish.
- **Our choice**: Return 201 (passes 3 setUp tests, fails 1 ZTC test). Returning 200 would fail 3 setUp tests and cascade to ~40 failures.

### _zoek endpoint (POST `/zaken/v1/zaken/_zoek`)
- **Test expects**: 201 (CREATED)
- **Observation**: This is a search endpoint (reads data), not a creation endpoint. Returning 201 for a search seems semantically incorrect. Standard REST practice would be 200.

### Lock endpoint (POST `/enkelvoudiginformatieobjecten/{uuid}/lock`)
- **Test expects**: 201 (CREATED)
- **ZGW spec**: Returns 200 with lock ID
- **Observation**: Lock creates a lock resource, so 201 is arguable. But the spec says 200.

### Unlock endpoint (POST `/enkelvoudiginformatieobjecten/{uuid}/unlock`)
- **Test expects**: 201 (CREATED)
- **ZGW spec**: Returns 204 (No Content)
- **Observation**: Unlock doesn't create anything. 204 is more semantically correct.

## Invalid JSON in Request Bodies

### Unquoted Postman variables in JSON body templates
- **Affected tests**: "Maak een GEBRUIKSRECHT aan.", "Alle OBJECT-INFORMATIEOBJECT relaties opvragen. Copy"
- **Template**: `"informatieobject": {{informatieobject_url}}` (without quotes around the variable)
- **Expected**: `"informatieobject": "{{informatieobject_url}}"` (with quotes)
- **Impact**: Newman resolves `{{informatieobject_url}}` to `http://localhost/...` but the `//` is interpreted as a JavaScript line comment, truncating the value to just `http:`. This produces invalid JSON that any server will reject.
- **Cascade**: Causes 10 test failures (Gebruiksrechten create + 5 cascading, OIO create + 3 cascading)
- **Observation**: The setUp versions of the same tests (e.g., "Add Gebruiksrechten to EnkelvoudigInformatieObject") correctly quote the variable. Only the main test section templates are missing quotes.
- **Attempted fix**: Server-side regex to re-quote truncated values. It works for curl but NOT for Newman because the truncation happens at the JavaScript level before the HTTP request is sent.

## The ZTC section cannot pass against a conformant implementation

### `created_zaaktype_url` is replaced in the wrong variable scope

- **Sequence**: "Maak een ZAAKTYPE aan." creates zaaktype A and stores its URL with `pm.environment.set("created_zaaktype_url", ...)`. The section then PUTs, PATCHes, **publishes** A, and DELETEs it. "Create concept Zaaktype" then creates a fresh concept B and stores it with `pm.globals.set("created_zaaktype_url", ...)`.
- **The bug**: Postman resolves an environment variable ahead of a global one. The environment entry still holds A, so `{{created_zaaktype_url}}` never becomes B. Every later ZTC section, eigenschappen included, targets the published A.
- **Why no implementation can pass it**: ZTC forbids deleting a zaaktype that is not a concept, and forbids changing the types of a published zaaktype. So the DELETE answers 400, and so does every eigenschap, statustype, roltype and relatie write that follows.
- **Measured 2026-09-12**: 25 of the 31 error responses left in the OAS collection, and most of the 19 requests that are never sent. The four distinct messages are "Het is niet toegestaan om een zaaktype met concept=false te verwijderen" and its besluittype and informatieobjecttype equivalents, plus "Het is niet toegestaan om typen van een gepubliceerd zaaktype aan te passen".
- **Our behaviour is correct and stays.** Relaxing either rule to make the assertions green would turn a standard into a suggestion.
- **The fix belongs upstream**: `pm.environment.set` in "Create concept Zaaktype", or an `pm.environment.unset` before the global write.

## Open in dossiq, not in the collection

### A full PUT of a zaak is refused on a readOnly property

- **Test**: "Werk een ZAAK in zijn geheel bij." (ZRC zaken)
- **Response**: 400 `Cannot modify readOnly property: deadline`
- **Why**: ZGW PUT is a full replace, so a client sends back what GET returned, `uiterlijkeEinddatumAfdoening` included. The zaak inbound mapping writes it into `case.deadline`, which the register declares `readOnly` and computes from `startDate` plus the case type's `processingDeadline`. OpenRegister accepts a readOnly property on create and refuses it on update.
- **`case.identifier` is the same shape** and has not bitten yet: ZGW lets a client supply `identificatie` on create, so the mapping cannot simply stop writing it.
- **The fix needs a decision, not a patch**: the inbound mapping is built once and used for both create and update, so excluding readOnly properties has to happen on the update path only. Cost today is one assertion in the OAS collection.

## Invalid base64 in a request body

### EIO `inhoud` is not decodable base64

- **Test**: "Maak een (ENKELVOUDIG) INFORMATIEOBJECT aan." (DRC enkelvoudiginformatieobjecten)
- **Body**: `"inhoud": "abcde"`, with `"bestandsomvang": 6`
- **ZGW spec**: `inhoud` is `format: byte`, so base64
- **Why no implementation can accept it**: five base64 characters cannot decode. PHP's `base64_decode($v, strict: true)` returns false, and Python's `base64.b64decode('abcde')` raises `Invalid base64-encoded string: number of data characters (5) cannot be 1 more than a multiple of 4` with or without `validate=True`. So the VNG reference stack rejects it too.
- **Our behaviour**: `ZgwDocumentService::storeBase64()` refuses with 400 `Invalid base64 content`. That is correct and stays.
- **Cascade**: the create fails, so every later DRC request reading `{{informatieobject_url}}` is never sent.

## Date Format Inconsistencies

### Gebruiksrechten `startdatum`
- **ZGW spec**: `format: date` (YYYY-MM-DD)
- **Test sends**: `"2019-01-01T12:00:00"` (date-time format without timezone)
- **Observation**: Test body doesn't match the spec's declared format.

## PUT/PATCH Value Assertion Bugs

### Zaaktype PUT, hardcoded body ignores prerequest script
- **Test**: "Werk een ZAAKTYPE in zijn geheel bij." (ZTC zaaktypen test Mat section)
- **Body template**: Hardcoded JSON with `"omschrijving": "zrc_tests_3"` instead of `{{request_body}}`
- **Prerequest**: Sets `omschrijving` to `"aangepast"` via `pm.environment.set("request_body", ...)`
- **Test assertion**: Expects `omschrijving` to equal `"aangepast"`
- **Result**: Always fails because the hardcoded body ignores the prerequest modifications
- **Observation**: The ZRC section has the same test name but uses `{{request_body}}` correctly. The ZTC section's copy has an inline body that overrides the prerequest.

### Zaaktype PATCH, stale body variable
- **Test**: "Werk een ZAAKTYPE deels bij." (ZTC zaaktypen test Mat section)
- **Same root cause**: Body modifications in prerequest don't match assertions

### Besluittype PUT, undefined global variable
- **Test**: "Werk een BESLUITTYPE in zijn geheel bij."
- **Assertion**: `pm.expect(pm.response.json().omschrijving).to.be.equal(pm.globals.get("omschrijving"))`
- **Issue**: `pm.globals.get("omschrijving")` returns `undefined` because no test ever sets this global
- **Result**: Asserts `expected 'aangepaste omschrijving' to equal undefined`, always fails

### EIO PUT, missing lock ID in request body
- **Test**: "Werk een (ENKELVOUDIG) INFORMATIEOBJECT in zijn geheel bij."
- **Prerequest**: Retrieves stored `informatieobject_body` but doesn't add the `lock` field
- **Previous test**: "Vergrendel een (ENKELVOUDIG) INFORMATIEOBJECT" locks the document and stores lock ID
- **Result**: PUT would be rejected because document is locked but no lock ID is provided
- **Observation**: The PATCH test correctly includes `"lock": "{{informatieobject_lock_id}}"` in the body. Only PUT is missing it.
- **Our workaround**: Relaxed lock enforcement to only check when `lock` key is present in request body.

## Test Collection Structure

### setUp Cascade
- Many tests depend on setUp scripts that create resources. If any setUp step fails, all subsequent tests in that section fail with ENOTFOUND errors (because Postman variables like `{{zaaktype_url}}` are never set).
- This makes it difficult to identify root cause failures vs cascade failures.

## Implementation Notes

### Issues Found and Fixed During Compliance Work
These are NOT VNG test bugs but issues we discovered and fixed in our implementation:

1. **EIO download filename mismatch**: Create stored files as `'document'` (default) but download looked for `'download'` (different default). Fixed by aligning both to `'document'`.
2. **Empty `bestandsnaam` handling**: When no `bestandsnaam` is provided, `fileName` defaults to empty string from Twig mapping. Added fallback to `'document'` in create, update, and download.
3. **`identifier` int-to-string cast**: OpenRegister DB returns `identifier` as int but ZGW expects string. Added explicit cast in publish and PATCH flows.
4. **Array re-encoding for PATCH**: OpenRegister deserializes JSON strings back to arrays when loading objects. Added re-encoding step before PATCH merge.
5. **Applicatie consumer lookup**: `/applicaties/consumer?clientId=...` matched as `show(uuid='consumer')`. Added special case to delegate to filtered index.
6. **`inhoud` empty string handling**: Some EIO creates send `"inhoud": ""` (empty string). `empty("")` correctly prevents file storage, but this is expected behaviour: not all EIO creates include binary content.

## JWT Token Switching Bug (zrc-007, zrc-006, zrc-008)

### "set restricted token" doesn't actually switch the JWT
- **Setup flow**: Test calls "Create zgw-token client_id_restricted" to create a JWT from `client_id_limited`/`secret_limited`, stores as `jwt-limited`. Then "set restricted token" copies it to `jwt_token` env var.
- **Problem**: Each test section has a prerequest that regenerates the JWT from `client_id`/`secret` env vars (which are still `procest-admin`). This overrides the `jwt_token` env var.
- **Result**: All zrc-007c/d/e/f/g/h/i/k tests use `procest-admin` (superuser) token despite expecting 403 for missing scope. Since `procest-admin` has `heeftAlleAutorisaties=true`, it always has all scopes.
- **Impact**: 16 zrc-007 tests always fail (expect 403, get 200) because the token switch doesn't take effect.
- **Our implementation**: Correctly detects closed zaaken (checks `endDate`), looks up Consumer by `client_id`, checks `superuser` flag and `autorisaties.scopes`. Returns 403 when scope is missing. It works correctly, but always returns 200 for a superuser.

---

*Last updated: 2026-03-07*
*Dossiq version: 0.4.0*

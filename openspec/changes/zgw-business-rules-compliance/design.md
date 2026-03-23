## Context

Procest exposes ZGW-compliant APIs on top of OpenRegister. The VNG Newman test suite (353 assertions) currently reports ~56 failures. Business rules are implemented in `ZgwZrcRulesService`, `ZgwZtcRulesService`, `ZgwDrcRulesService`, `ZgwBrcRulesService`, and coordinated by `ZgwBusinessRulesService` and `ZgwRulesBase`. The `ZgwService` handles request routing and enrichment. Endpoint response times are 2–5 s due to manual N+1 cross-register lookups instead of using OpenRegister's optimised property inversion and search.

## Goals / Non-Goals

**Goals:**
- Fix all ~56 failing VNG Newman assertions to reach 353/353 passing
- Reduce average ZGW endpoint response time from 2–5 s to < 200 ms
- Cover every numbered rule: zrc-001 through zrc-023, brc-001 through brc-006, drc-001 through drc-003, ztc-001 through ztc-010

**Non-Goals:**
- Changes to NRC (Notificaties API) endpoints
- Changes to ZGW mapping configuration (Twig templates, MappingService)
- Frontend UI changes
- New ZGW API versions beyond current (ZRC v1.5.1, ZTC v1.3.1, DRC v1.4.3, BRC v1.1.0)

## Decisions

### Decision 1: Fix eindstatus detection via volgnummer fallback (zrc-007a)

**Problem:** When `isEindstatus` is not explicitly set on a statustype, the zaak `einddatum` is never set.

**Decision:** In `ZgwZrcRulesService`, when processing status creation, query all statustypes for the zaaktype sorted by `volgnummer` DESC. If the created statustype has the highest `volgnummer`, treat it as eindstatus and set `zaak.einddatum = now()`.

**Alternative considered:** Require `isEindstatus` to always be set explicitly. Rejected — VNG test data does not always set this flag and the standard requires fallback behaviour.

### Decision 2: Cascade indicatieGebruiksrecht on zaak close (zrc-007b/q)

**Problem:** When a zaak closes (eindstatus set), linked informatieobjecten must have `indicatieGebruiksrecht` set. The rule is bidirectional: set it on close (007b) and validate before allowing close (007q).

**Decision:** In `ZgwZrcRulesService::handleEindstatus()`:
1. (007q) Before creating eindstatus, check all linked ZaakInformatieObjecten — if any has `indicatieGebruiksrecht === null`, return 400 with validation error.
2. (007b) After eindstatus is confirmed, update all linked informatieobjecten to set `indicatieGebruiksrecht = true` via OpenRegister ObjectService.

**Alternative considered:** Lazy enforcement only at archive time. Rejected — VNG test explicitly validates at eindstatus creation.

### Decision 3: Use OpenRegister property inversion for cross-register lookups (performance)

**Problem:** Current enrichment loops make individual ObjectService calls per related object (zaaktype, statustype, resultaattype, etc.) — O(N) calls per request.

**Decision:** Replace manual lookup loops with OpenRegister's optimised `ObjectService::getObjects()` with `_filters` using property inversion (stored reverse-index). Batch all related-object lookups into a single query per relationship type. Use `_limit=1000` where the full related set is needed.

**Alternative considered:** APCu-level caching of zaaktype/statustype objects. Rejected — cache invalidation complexity outweighs the gain; property inversion is always consistent.

### Decision 4: Error code normalisation (zrc-010, zrc-013a)

**Problem:** Several validation errors return incorrect VNG error codes:
- zrc-010: `communicatiekanaal` invalid URL returns `bad-url` instead of `invalid-resource`
- zrc-013a: `hoofdzaak` not found returns `no_match` instead of `does-not-exist`

**Decision:** Update the specific error-building calls in `ZgwZrcRulesService` to use the correct VNG error code constants. No architectural change needed — localised string fixes.

### Decision 5: Cascade-delete ObjectInformatieObject (zrc-005b/023h)

**Problem:** When a ZaakInformatieObject or a zaak is deleted, the corresponding ObjectInformatieObject (OIO) in the DRC register is not removed.

**Decision:** In `ZgwZrcRulesService::handleZioDelete()` and `ZgwZrcRulesService::handleZaakDelete()`, after deleting the ZIO/zaak, query the DRC register for OIOs linked to the deleted object's `informatieobject` UUID and delete them via `ObjectService::deleteObject()`.

### Decision 6: vertrouwelijkheidaanduiding derivation without template leakage (zrc-009)

**Problem:** The mapping template may leak a hardcoded `vertrouwelijkheidaanduiding` value even when the zaaktype's `vertrouwelijkheidaanduiding` should override it.

**Decision:** In `ZgwZrcRulesService::hydrateVertrouwelijkheid()`, always fetch the zaaktype and override the incoming value with the zaaktype's `vertrouwelijkheidaanduiding`. Only use the incoming value as a fallback when the zaaktype field is absent. Strip template-level defaults before saving.

### Decision 7: Authorization filter for zaken listing (zrc-006)

**Problem:** `GET /zaken` returns all zaken regardless of consumer's authorised zaaktypen and `maxVertrouwelijkheidaanduiding`.

**Decision:** In `ZgwZrcRulesService::filterZakenForConsumer()`, read the consumer's `authorizations` from the `ZgwAuthMiddleware` context, extract `zaaktype` and `maxVertrouwelijkheidaanduiding` per entry, and inject these as additional `_filters` on the ObjectService query before returning results.

## Risks / Trade-offs

- [Risk] Batched property-inversion queries return large result sets → Mitigation: cap with `_limit=1000`; ZGW registers rarely exceed hundreds of related objects in practice.
- [Risk] `indicatieGebruiksrecht` cascade update (007b) could fail mid-loop if one document is locked → Mitigation: wrap in try/catch; log failures but do not roll back the eindstatus creation (VNG standard does not require atomicity here).
- [Risk] Error code fixes (010, 013a) may unblock previously-masked test failures that expose other bugs → Mitigation: run full Newman suite after each fix batch; address newly surfaced failures incrementally.
- [Risk] Authorization filter (zrc-006) may break existing integrations that expect unfiltered results → Mitigation: only apply filter when `ZgwAuthMiddleware` context contains non-empty `authorizations`; fall back to unfiltered when context is absent.

## Migration Plan

1. Deploy updated `ZgwZrcRulesService`, `ZgwBrcRulesService`, `ZgwDrcRulesService`, `ZgwZtcRulesService` as a single release — no database migrations required.
2. Run VNG Newman test suite in CI to verify 353/353 passing before merge.
3. No rollback script needed — changes are purely PHP logic; reverting via git revert is sufficient.

## Open Questions

- Does OpenRegister's `AuthorizationService` already expose a method to retrieve consumer authorizations for ZGW scope checking, or must `ZgwAuthMiddleware` maintain its own context bag? (Investigate `AuthorizationService` before implementing zrc-006.)
- Are there Newman test fixtures that explicitly test the `maxVertrouwelijkheidaanduiding` ordering (confidential > restricted > public)? If so, a comparison table must be defined in `ZgwRulesBase`.

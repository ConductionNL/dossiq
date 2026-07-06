# Tasks: avg-verwerkingenlogging (thin consumer)

> UNBLOCKED 2026-07-05: the OR side is shipped on OR `origin/development` — verwerkingenlogging
> routes (`/api/avg/verwerkingen`, `/api/avg/verwerkingen/betrokkene`, routes.php:261-262),
> verwerkingsactiviteiten CRUD + verantwoording (routes.php:224-229), and the
> `x-openregister-processing` (`logReads`) / `x-openregister-processing-activity` dialects
> (lib/Db/ProcessingLogEntry.php, lib/Db/Schema.php:1935). All tasks can run; DC01 still pins the
> minimum deployed OR version first.

## Deduplication / Dependency Check

- [ ] **DC01**: Confirm the OR change `processing-activity-register` is merged and the `x-openregister-processing` dialect + `logReads` are available in the deployed OR version; record the minimum OR version in `appinfo/info.xml` dependencies.
- [ ] **DC02**: Verify ZGW bearer-client identity reaches OR's processing-log context (`performedBy`/channel) on ZRC reads; if not, file the gap on the OR change rather than instrumenting procest controllers.

## Catalogue & Annotations

- [ ] **T01**: Author procest's verwerkingsactiviteiten catalogue as `x-openregister-processing` annotations in `lib/Settings/procest_register.json` — per case-type activities with naam, doel, AVG-rechtsgrond (art. 6 enum) + statutory reference, betrokkene categories, ontvangers, bewaartermijn; seed-as-draft semantics.
- [ ] **T02**: Enable `logReads` on person-bearing schemas (case, betrokkene/rol, klantcontact) and set per-operation attribution overrides where a case type maps to a specific activity.

## FG Surfacing

- [ ] **T03**: Add the FG/admin view (`src/views/admin/VerwerkingenOverview.vue`) — catalogue review status, unclassified-processing counter (OR-PA-4 fallback count scoped to procest registers), per-betrokkene inzageverzoek export entry point delegating to OR-PA-7. NcSelect with inputLabel; modals in `src/modals/` per ADR-004.
- [ ] **T04**: Document the VNG Logging Verwerkingen API consumption (OR-PA-9 endpoint + procest register scope) in `docs/` for external audit tooling; no procest API endpoints.
- [ ] **T05**: Dutch + English i18n for new UI strings (English source keys).

## Verification Tasks

- [ ] **V01**: After catalogue import, activities appear in OR as drafts; FG activation makes them attributable; case-type attribution resolves on case reads.
- [ ] **V02**: Reading a BSN-bearing case produces an OR processing-log entry (read) attributed to the case type's activity; procest adds no blocking latency (emission is OR-side).
- [ ] **V03**: A case type without attribution lands in OR's flagged fallback and the procest FG view shows the unclassified count.
- [ ] **V04**: The inzageverzoek entry point produces OR's per-betrokkene export scoped to procest registers; non-FG users are denied (OR-PA-8).

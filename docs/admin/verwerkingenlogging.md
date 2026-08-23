---
id: verwerkingenlogging
title: AVG verwerkingenlogging (via OpenRegister)
sidebar_position: 3
description: How dossiq satisfies AVG art. 30 and the VNG Logging Verwerkingen standard as a thin consumer of OpenRegister's processing-activity register. Where the FG works, and where external audit tooling connects.
---

# AVG verwerkingenlogging

Dossiq is accountable case handling: under the AVG (art. 5 lid 2, art. 30) every processing of
personal data — **including pure reads (raadplegen)** — must be provable. Dossiq satisfies this
as a **thin consumer** of OpenRegister's platform verwerkingenlogging (the 2026-06-11 abstraction
decision): all storage, append-only logging, retention, per-subject export, and API mechanics are
OpenRegister's. Dossiq contributes only the zaakgericht-werken domain knowledge.

## What dossiq contributes

1. **Activity catalogue** — `lib/Settings/verwerkingsactiviteiten.json` declares dossiq's
   verwerkingsactiviteiten (behandelen omgevingsvergunning / bezwaarschrift / Woo-verzoek /
   klacht, zaakafhandeling, klantcontact-registratie, zaak-archivering) with doel, AVG art. 6
   rechtsgrond, betrokkene categories, ontvangers, and bewaartermijn. The
   `SeedVerwerkingsactiviteiten` repair step seeds them into OpenRegister's verwerkingsregister
   as **drafts** (status `concept`), upsert-by-code; the FG reviews and publishes them in
   OpenRegister. FG lifecycle decisions survive dossiq upgrades — the seed never touches status.
2. **Read-logging opt-in** — the person-bearing schemas `case`, `role`, `customerContact`
   (`lib/Settings/dossiq_register.json`) and `contactmoment`
   (`lib/Settings/register.d/40-kcc-werkplek.json`) carry the `x-openregister-processing`
   annotation with `logReads: true` and a default activity attribution
   (`zaakafhandeling` / `klantcontact-registratie`). Schemas without person data deliberately
   stay out of the high-volume read log.
3. **FG surfacing** — the **Processing activities (AVG)** page (settings section) is a scoped
   window on OpenRegister's register: catalogue review status, the unclassified-processing
   counter (OR's flagged fallback `niet-geclassificeerde-verwerking`), and the per-betrokkene
   inzageverzoek export entry point. OpenRegister denies non-FG/non-admin callers fail-closed.

## What dossiq does NOT do

Dossiq ships **no** processing-log endpoints, storage, retention jobs, export engines, or
steward views. `appinfo/routes.php` contains no verwerkingen route on purpose.

## External audit tooling (VNG Logging Verwerkingen)

Point audit tooling at **OpenRegister's** API, scoped to dossiq's register:

| Endpoint | Purpose |
|----------|---------|
| `GET /apps/openregister/api/avg/verwerkingen?register={procest-register-id}` | Filtered processing-log inquiry (also: `schema`, `activity`, `actor`, `action`, `from`, `to`) |
| `GET /apps/openregister/api/avg/verwerkingen/betrokkene?subjectIdType=BSN&subjectIdValue=…` | Per-subject inzage extract (art. 15) |
| `GET /apps/openregister/api/avg/verwerkingsactiviteiten` | The activity catalogue (art. 30 register) |
| `GET /apps/openregister/api/avg/verantwoording` | Verantwoordingsdocument |

Access requires Nextcloud admin or membership of OpenRegister's delegated FG group; FG-only
callers are tenant-scoped server-side. Requires OpenRegister >= 0.2.16.

## Known limitations (recorded honestly)

- **Per-case-type attribution**: OR's `x-openregister-processing` dialect resolves attribution
  per schema/per operation (`read`/`export`/`default`), not per case type. All case reads
  currently attribute to `zaakafhandeling`; the specific case-type activities are catalogued and
  FG-reviewable, and become attributable per case type once OR's dialect supports value-based
  attribution.
- **ZGW machine-client identity**: OR derives the log actor from the Nextcloud user session;
  ZGW bearer-client identity does not reach the OR log context yet. This is an OR-side gap on
  the `processing-activity-register` change, not something dossiq re-implements.

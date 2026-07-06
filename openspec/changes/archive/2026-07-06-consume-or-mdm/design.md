# Design: consume-or-mdm

## Where the annotations live

In `lib/Settings/procest_register.json`, on the existing schema definitions — NOT in a new
`register.d/` fragment. Rationale: register.d fragments union-merge into existing schemas and the
union-merge path has corrupted `required` arrays before (documented workspace gotcha). pipelinq
could use a fragment because its MDM schemas (`masterEntity`, `sourceRecord`, …) were *new*
schemas defined wholly inside `90-master-data-management.json`; procest is annotating
*pre-existing* schemas, so in-place editing is the safe path.

## Annotation shapes (mirroring pipelinq's canonical instance)

Shapes copied from `../pipelinq/lib/Settings/register.d/90-master-data-management.json`
(`masterEntity`), adapted per schema. Weights/thresholds start at pipelinq's proven defaults
(`thresholds: {good: 0.8, fair: 0.5}`, dedup `threshold: 0.7`) and are steward-tunable in OR
afterwards.

### case

```json
"x-openregister-quality": {
  "field": "qualityScore",
  "statusField": "qualityStatus",
  "rules": [
    {"type": "required", "field": "title", "weight": 1},
    {"type": "required", "field": "caseType", "weight": 1},
    {"type": "required", "field": "identifier", "weight": 1},
    {"type": "freshness", "field": "startDate", "halfLifeDays": 365, "weight": 1}
  ],
  "thresholds": {"good": 0.8, "fair": 0.5}
},
"x-openregister-dedup": {
  "blockingKeys": ["caseType"],
  "matchRules": [
    {"field": "identifier", "method": "exact", "weight": 0.4},
    {"field": "vergunningaanvraagRef", "method": "exact", "weight": 0.3},
    {"field": "title", "method": "normalized", "weight": 0.2},
    {"field": "title", "method": "levenshtein", "weight": 0.1}
  ],
  "threshold": 0.7
}
```

`vergunningaanvraagRef` is the DSO double-intake key: `DsoIntakeService` creates permit cases from
DSO vergunningaanvraag messages, and a re-delivered message must surface as a duplicate candidate,
not a second zaak. `blockingKeys: ["caseType"]` keeps the candidate space per zaaktype.

### supplier

```json
"x-openregister-quality": {
  "field": "qualityScore",
  "statusField": "qualityStatus",
  "rules": [
    {"type": "required", "field": "legalName", "weight": 1},
    {"type": "required", "field": "kvkNumber", "weight": 1},
    {"type": "format", "field": "kvkNumber", "pattern": "^[0-9]{8}$", "weight": 1}
  ],
  "thresholds": {"good": 0.8, "fair": 0.5}
},
"x-openregister-dedup": {
  "blockingKeys": [],
  "matchRules": [
    {"field": "kvkNumber", "method": "exact", "weight": 0.4},
    {"field": "iban", "method": "exact", "weight": 0.3},
    {"field": "legalName", "method": "normalized", "weight": 0.2},
    {"field": "legalName", "method": "levenshtein", "weight": 0.1}
  ],
  "threshold": 0.7
}
```

### partnerOrganization

```json
"x-openregister-quality": {
  "field": "qualityScore",
  "statusField": "qualityStatus",
  "rules": [
    {"type": "required", "field": "name", "weight": 1},
    {"type": "required", "field": "oin", "weight": 1},
    {"type": "format", "field": "contactEmail", "format": "email", "weight": 1}
  ],
  "thresholds": {"good": 0.8, "fair": 0.5}
},
"x-openregister-dedup": {
  "blockingKeys": [],
  "matchRules": [
    {"field": "oin", "method": "exact", "weight": 0.5},
    {"field": "name", "method": "normalized", "weight": 0.3},
    {"field": "name", "method": "levenshtein", "weight": 0.2}
  ],
  "threshold": 0.7
}
```

## Why no survivorship anywhere

`x-openregister-survivorship` resolves a golden record from trust-tiered *source records* linked to
the master (pipelinq: `sourceRecord.currentMasterEntity` reverseFk). Procest holds no schema pair
with that shape — every annotated entity is single-source. Declaring survivorship without source
records would make OR's materialiser a no-op at best. Revisit when live BRP/KvK adapters
(`external-integrations-test-environments`) feed initiator data alongside manual entry.

## Steward workflow

Stewards open OpenRegister's governance views (Data Quality / Duplicate Candidates /
Master-entities, per ADR-045), scoped to the `procest` register. Procest links nothing and renders
nothing; merges performed in OR flow back transparently because procest reads objects through OR's
API (relations are UUID-stable across OR's reversible merge).

## Import path

The annotations travel with the normal register import (`ConfigurationService::importFromApp()` via
the repair step). No new import mechanics. Quality scores materialise on the next save of each
object (OR's on-save materialisation); a steward can trigger recalculation from OR's views —
procest ships no backfill job.

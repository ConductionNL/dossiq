# Proposal: social-domain-procedures-are-case-types

Status: proposal only, not built (coordinator, 23 Oct 2026). It follows the same approach as
`re-integration-is-a-case-type` (DQ-REINT branches r25/record-import-case-fields,
r25/re-integration-case-type, r25/re-integration-example and r25/re-integration-page-retires).
Build it only after those four have merged.

## Why

Ruben's standing rule: procedures are case types, flows and configuration, and code is generic and
named for what it does. dossiq keeps three social-domain procedures as schemas of their own beside
the `case` schema:

- `wmoZaak`
- `jeugdwetZaak`
- `participatiewetZaak`

Each one carries its own status enum, its own handler fields and its own case number. None of them
gets what a case has: flows, the case page, terms, tasks, documents, the timeline, the Cases list,
saved views and access by handling team. `jeugdwet` already exists as a case type package (Melding
Jeugdwet) alongside its schema, so a Jeugdwet procedure lives in two places today.

## Inventory (measured 23 Oct 2026 on development 01c88f105 and on :8080, read-only)

### Schemas (`lib/Settings/register.d/50-sociaal-domein.json`, each at 1.2.1)

| Schema | Fields | Shipped seed rows | Rows on :8080 |
|---|---|---|---|
| `wmoZaak` | caseType, caseNumber, bsn, name, requestKind, requestDate, reportChannel, supportRequest, householdsComposition, districtTeam, handlerId, secondHandlerId, status, leadTimeStatutory, investigationTermExceededSinds, needsAssessmentId, gdprClassification | 3 | 4 |
| `jeugdwetZaak` | caseType, caseNumber, familyId, jeugdigeBsn, juvenileAge, requestChannel, requestDate, referrer, supportRequest, districtTeam, handlerId, secondHandlerId, status, gezinsplanId, mdoConsultationIds, supervisionOrderActive, extensionHistory, gdprClassification | 3 | 4 |
| `participatiewetZaak` | caseType, caseNumber, bsn, requestKind, requestDate, effectiveDateDesired, ageGroup, householdsSituation, equityAssessment, incomeAssessment, reIntegrationTrajectoryId, handlerId, districtTeam, status, afwijzingsvoorstel, gdprClassification | 3 | 4 |

- Each schema has its own `status` enum, which ends in `closed`. Each also has a required
  `gdprClassification` block with article 9 categories, legal basis, retention, access restriction
  and export restriction.
- Mock copies of all three are in `lib/Settings/dossiq_mock_register.json`, with "Voorbeeld" rows.
- Other records point at them: `indicatiestelling` (via `needsAssessmentId`), `gezinsplan`,
  `mdoOverleg` (via `mdoConsultationIds`), `toestemming.caseId`, `sociaalDomeinAuditLog`, and the
  re-integration trajectory (`caseId`, now the case property `bijstandszaak`).

### Code

- `lib/Service/SociaalDomein/SociaalDomeinStore.php`: `DOMAINS` maps Wmo, Jeugdwet and
  Participatiewet to their schemas and BSN keys. Jeugdwet uses `jeugdigeBsn`, the other two use
  `bsn`. It also defines `CLOSED_STATUS = 'closed'`.
- `lib/Service/SociaalDomein/CrossDomainExistence.php`: the logged cross-domain lookup ("does this
  person have a case in another domain?"). It reads the three schemas through `DOMAINS`.
- `lib/Service/SociaalDomein/HouseholdCases.php`: `DOMAIN_CATEGORIES` maps the three domains to case
  type categories. This means the household view already reads real cases by category, beside the
  three schemas.
- No manifest page or menu entry exists for the three schemas. They are reached only through the
  social-domain cards and the cross-domain lookup.

### Tests

- PHPUnit: `tests/Unit/Service/SociaalDomein/*` (CrossDomainExistence, HouseholdCases,
  SociaalDomeinAuditLog, FakeSociaalDomeinObjects), `tests/Unit/Settings/SociaalDomeinFragmentTest.php`,
  `SensitiveFieldsDeclaredTest.php`.

### OpenSpec

- Specs: `openspec/specs/dossiq-sociaal-domein-participatiewet`, `-wmo`, `-jeugdwet` and `-avg-consent`.
  They describe each procedure as a zaaktype with an `x-openregister-lifecycle` status enum.

## Approach (same pattern as re-integratie)

1. **Generic first.** Nothing new should be needed beyond what the re-integratie branches add:
   - the record import with dot paths, typed values, `property` targets, `statuses` maps,
     `carriesTerm` and `runOnUpgrade`;
   - the `caseAnswer` column;
   - `movedRecordRoutes`.

   Two generic gaps to check first:
   - The status enum of each schema maps onto case statuses through `statuses`, which is already
     supported.
   - `gdprClassification` belongs on the case. It is a case-level block, not a case-type field, so
     check whether `case` should declare it (generic: special-data categories per case) or whether
     the case type's `specialDataCategories` / `personalDataCategories` plus `confidentiality` cover it.
2. **Configuration per procedure.** These are packages with `install: auto`, handled by
   `dossiq-sociaal-domein`, confidential:
   - **Wmo-melding:** statuses from `wmoZaak.status`; statutory term from `leadTimeStatutory`
     (Wmo 2.3.2: 6 weeks for the investigation, 2 for the decision). The statutory term carries over,
     so this one has `carriesTerm: true`.
   - **Jeugdwet:** extend the existing `jeugdwet` package (Melding Jeugdwet). Do not ship a second
     case type; add a `recordImports` entry over `jeugdwetZaak`.
   - **Bijstandsaanvraag (Participatiewet):** statuses from `participatiewetZaak.status`.
     `equityAssessment` and `incomeAssessment` become case-type fields, as JSON or as split
     answers. The re-integration case is related through `followUpCases` (vervolg), and `bijstandszaak`
     becomes a real case reference. A small follow-up repair rewrites the `bijstandszaak` answer from
     the old `caseNumber` to the new case id and keeps the old value as a former reference.

   Each stored record becomes a case, with its uuid in `externalReference` and `caseNumber` kept in
   `formerReferences`. The record is stamped with `migratedTo` and `migratedAt`, which needs a version
   bump of each schema. Nothing is deleted.
3. **Generic code over the cases.**
   - `SociaalDomeinStore::DOMAINS` and `CrossDomainExistence` read cases by case type category
     (the `HouseholdCases::DOMAIN_CATEGORIES` vocabulary) instead of three schemas. The BSN then comes
     from the case's requester role, not from per-schema keys. This removes the `jeugdigeBsn` trap
     the store documents.
   - The "closed" literal becomes the case's `isFinalStatus`.
   - These classes are already generic in name. Their bodies stop naming the procedures.
4. **Example data.** One example in flight per package, as `examples/`. The mock register drops its
   placeholder rows.
5. **Retirement.**
   - The three schemas stay declared, so stored rows stay readable.
   - No page or menu entry needs retiring, because none exists.
   - The specs move from "zaaktype backed by its own entity" to "case type plus flow", in one
     openspec delta per spec.

## Dependencies and risks

- The cross-domain lookup is privacy-critical: it is logged and needs a ground. Re-pointing it at
  cases must keep the "an unreadable store refuses" rule. Its tests must run red then green against
  case-backed fakes.
- The `indicatiestelling`, `gezinsplan`, `mdoOverleg` and `toestemming` references keep pointing at
  the old record ids. Either the readers follow `migratedTo`, or a repair rewrites the references.
  This is a design decision for the build.
- Editing existing case-type answers depends on the DQ-EDIT prefill fix. Filtering on case-type
  fields depends on DQ-L6 (the filter bar over `properties[]`). A list of records (such as the two
  assessment blocks) needs nc-vue `fix/r25-dynamic-field-items`.
- caseType schema numbers: claim one in the version register if the case type needs a new key. The
  plan above needs none beyond 1.35.0.

## Decisions (coordinator, 23 Oct 2026)

- Sensitive data keeps today's access exactly: `case.subjectBsn` and `case.sensitiveProperties`, both
  readable and writable only by `dossiq-sensitive`; `propertyDefinition.sensitive` routes an answer
  to `sensitiveProperties` (nextcloud-vue `values.arrayKeyBy`, generic).
- The cross-domain lookup also answers from open cases, read as the system, returning only
  exists/domain/contact and logging the act as before. Flagged to Ruben.
- Seeds get fixed ids so a re-seed cannot duplicate them; the import is idempotent by case number
  (`dedupeBy`), and a duplicate is reported.
- Households stay closed to admins; no access is widened.
- One stacked set per procedure, Participatiewet first.

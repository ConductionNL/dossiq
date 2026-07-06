# Archief en e-Depot — beheerdersgids

Sinds de migratie `migrate-archival-to-or` (ADR-022) **voert OpenRegister de
archivering, vernietiging en e-Depot-overdracht uit**. Procest levert alleen nog
de zaakgerichte domeinkennis *declaratief* aan en vertaalt Awb-gebeurtenissen
(bezwaar/beroep) naar OpenRegister *legal holds*. Procest draait geen eigen
archief-pipeline meer.

> **Spec:** `openspec/changes/migrate-archival-to-or/specs/archief-edepot-handover/spec.md`
> **Eigenaar van de pipeline:** OpenRegister — `RetentionService`,
> `Archival/*` (RetentionEvaluator, LegalHoldService, DestructionService),
> `Edepot/*` (EdepotTransferService, SipPackageBuilder, MdtoXmlGenerator,
> Transport/*), `TmloService`.

## Wat procest nog doet (en wat niet meer)

| Onderdeel | Voorheen (app-lokaal, verwijderd) | Nu |
|-----------|-----------------------------------|-----|
| Bewaartermijnregels | `BewaarTermijnRegel`-objecten + `ArchivalTriggerService` | Declaratief: `x-openregister-archival` op het `case`-schema |
| Detectie afgeronde zaken | `ArchivalTriggerScanJob` daemon | OpenRegister `RetentionEvaluator` + `DestructionCheckJob` |
| Bezwaar/beroep-opschorting | `OverdrachtTrigger` status `opgeschort-juridische-procedure` | OpenRegister legal hold (`LegalHoldService`), geplaatst door procest |
| SIP-bundeling / BagIt / MDTO | `BagItBundlerService`, `MetadataBundlerService` | OpenRegister `SipPackageBuilder` + `MdtoXmlGenerator` |
| Verzenden + retry naar e-Depot | `ArchivalBatchService`, `ArchivalSubmissionRetryService` | OpenRegister `EdepotTransferService` + durable retry |
| Bewijs van overdracht | `ArchiefBewijs`-objecten, `ProofOfTransferService` | OpenRegister transfer-/proof-records |
| TMLO/MDTO-metadata mapping | `TmloMetadataBuilderAdapter` | Schema-config `configuration.tmloDefaults` + `Register.configuration.tmloEnabled`, uitgevoerd door OR `TmloService` |

## 1. Bewaartermijnen — declaratief op het zaakschema

De bewaartermijnen staan in het `case`-schema onder `x-openregister-archival`
(VNG-selectielijst 2020 als default set). Aanpassen doe je in **Beheer →
OpenRegister → Registers → Procest → schema `case`**, of in
`lib/Settings/procest_register.json`:

```json
"x-openregister-archival": {
  "retention": {
    "default": "P10Y",
    "rules": [
      { "condition": "caseType == \"omgevingsvergunning-regulier\"", "retention": "P5Y",  "reason": "VNG 4.3.1" },
      { "condition": "caseType == \"wmo-melding\"",                    "retention": "P10Y", "reason": "VNG 5.2.1" },
      { "condition": "caseType == \"subsidie-verlening\"",             "retention": "P20Y", "reason": "VNG 7.1.1 — blijvend te bewaren, overbrenging na 20 jaar" }
    ]
  }
}
```

- `default` en elke `retention` is een **ISO-8601-duur** (`P5Y`, `P10Y`, …).
- `condition` gebruikt de grammatica `<veld> <op> <waarde>` van OpenRegister's
  `RetentionConditionEvaluator` (velden op het zaak-object, bv. `caseType`).
- Municipality-edits die vóór de migratie als `BewaarTermijnRegel`-objecten
  bestonden, worden door de repair-stap bewaard; pas ze na verificatie hier aan.

OpenRegister berekent hieruit de `archiefactiedatum` en nomineert de zaak in
zijn archivist-workflow (V-lijst / overbrenging). Zaaktypen zónder regel
verschijnen in OpenRegister's archivist-view als *unconfigured* — procest houdt
geen eigen `geblokkeerd-geen-regel`-administratie meer bij.

## 2. TMLO/MDTO-metadata

TMLO-auto-populatie staat aan via `Register.configuration.tmloEnabled = true`
(gezet door de repair-stap `MigrateArchivalToOpenRegister`) en de defaults in
`case.configuration.tmloDefaults`. OpenRegister's `TmloService` vult hiermee de
`tmlo`-metadata en exporteert MDTO-XML tijdens overbrenging. Extra TMLO-defaults
voeg je toe onder `tmloDefaults` op het schema.

## 3. Bezwaar/beroep → legal hold

Zolang een Awb-procedure loopt mag een zaak niet worden overgebracht of
vernietigd. Procest regelt dit via `BezwaarLegalHoldListener`:

- **Bezwaar geregistreerd** (`objection` aangemaakt) → procest plaatst een
  OpenRegister *legal hold* op de zaak. OR's retention-evaluator en
  vernietigingsjobs slaan de zaak over zolang de hold staat.
- **Eindbeslissing** (`bezwaarDecision` of `appealDecision` aangemaakt) →
  procest heft de hold op; de zaak komt weer in OR's archief-evaluatie zonder
  handmatige her-nominatie.

Holds zijn zichtbaar en beheerbaar in OpenRegister
(**`/api/archival/legal-holds`**, archivist-view).

## 4. e-Depot-connectie configureren (OpenRegister)

De e-Depot-verbinding (endpoint, transport, credentials, bestemming) staat sinds
de migratie in **OpenRegister's e-Depot-instellingen**:

1. Open **Beheer → OpenRegister → Instellingen → e-Depot**
   (`/api/settings/edepot`).
2. Kies het transport (`Sftp`, `RestApi` of `OpenConnector`) en vul endpoint +
   credentials in. In dev/test staat standaard een log/mock-transport.
3. Test de verbinding met **Verbinding testen** (`/api/settings/edepot/test`).
4. Overbrengingen en hun status/audittrail bekijk je via **`/api/transfers`**.

> Het koppelen van een *echt* e-Depot-testendpoint valt buiten deze migratie en
> hoort bij `external-integrations-test-environments`. De transport-seam blijft
> pluggable bij OpenRegister.

## 5. Vernietiging (destruction)

Vernietiging/overbrenging draait volledig in OpenRegister: `DestructionCheckJob`
stelt vernietigingslijsten (V-lijsten) samen voor archivist-review,
`DestructionExecutionJob` voert goedgekeurde vernietiging uit. Procest heeft geen
eigen vernietigings-UI meer; gebruik OpenRegister's archivist-surface.

## 6. Migratie-repair (eenmalig)

Bij de upgrade draait `MigrateArchivalToOpenRegister` (post-migration,
idempotent, fail-closed):

1. Zet `tmloEnabled` op de procest-register.
2. Plaatst een legal hold op elke zaak waarvan de `OverdrachtTrigger` op
   `opgeschort-juridische-procedure` stond.
3. Exporteert elk afgerond `ArchiefBewijs` als onveranderlijk zaakdossier-
   document, zodat geen bewijs van overbrenging verloren gaat.

De stap draait maar één keer (markering in app-config
`procest/archival_migration_completed`) en doet niets wanneer OpenRegister's
archief-abstracties ontbreken.

## 7. Troubleshooting

| Symptoom | Oorzaak | Oplossing |
|----------|---------|-----------|
| Zaak wordt niet genomineerd na afsluiten | Geen retention-regel voor het `caseType` | Voeg een rule toe onder `x-openregister-archival`; ongeconfigureerde types staan in OR's archivist-view. |
| Zaak met bezwaar wordt tóch genomineerd | Legal hold niet geplaatst | Controleer dat de `objection` een geldige `case`-verwijzing heeft; zie OR `/api/archival/legal-holds`. |
| Overbrenging blijft hangen | e-Depot-transport/endpoint | Check **OpenRegister → e-Depot** + `/api/transfers`; OR's durable retry pakt tijdelijke fouten op. |
| TMLO-metadata leeg | `tmloEnabled` staat uit | Herstart de repair-stap of zet `Register.configuration.tmloEnabled = true`. |

## Zie ook

- `openspec/changes/migrate-archival-to-or/` — proposal, design, spec.
- OpenRegister archief-stack: `RetentionService`, `Archival/*`, `Edepot/*`,
  `TmloService`, `Controller/{Archival,Retention,Tmlo,Transfer}Controller`.

# Archief & e-Depot — ontwikkelaarsgids

Architectuur, uitbreidingspunten en referentie voor ontwikkelaars die werken aan de archief-pijplijn van Procest. Doelgroep: backend-ontwikkelaars en integrators.

> **Specs:** `openspec/changes/archief-edepot-handover-01-schema-config` t/m `archief-edepot-handover-08-admin-ui-docs`

## 1. Architectuuroverzicht

De pipeline bestaat uit zeven afzonderlijke services die elk één verantwoordelijkheid hebben en losgekoppeld zijn via OpenRegister-objecten. Geen directe service-naar-service calls; de status van elk `OverdrachtTrigger` / `OverdrachtTransactie`-object stuurt het volgende stadium.

```
+---------------+   trigger    +-------------+   bundle     +------------+
| Case status   +------------->+ TriggerSvc  +------------->+ BundlerSvc |
| change event  |              | (daemon)    |              |            |
+---------------+              +------+------+              +-----+------+
                                      |                           |
                                      | OverdrachtTrigger         | SipBundel
                                      v                           v
                              +-------+--------+         +--------+--------+
                              | RetentionRules |         | DocExportSvc    |
                              | (BewaarRegel)  |         | (BagIt builder) |
                              +----------------+         +--------+--------+
                                                                  |
                                                                  v
                                                         +--------+--------+
                                                         | SubmitSvc       |
                                                         | (openconnector) |
                                                         +--------+--------+
                                                                  |
                                                                  v
                                                         +--------+--------+
                                                         | ProofRecorder + |
                                                         | RollbackMgr     |
                                                         +-----------------+
```

### Service-verantwoordelijkheden

| Service | Member | Verantwoordelijkheid |
|---------|--------|----------------------|
| `RetentionRuleService` | 01 | CRUD op `BewaarTermijnRegel`. |
| `OverdrachtTriggerDaemon` | 02 | Detecteert zaken die voldoen aan een regel; maakt `OverdrachtTrigger`-objecten aan. |
| `MetadataBundlerService` | 03 | Genereert MDTO XML uit `case`-velden. |
| `DocumentExportService` | 04 | Exporteert documenten naar tijdelijke export-tree; lost referenties op. |
| `SipBundleBuilderService` | 04/05 | Pakt MDTO + documenten in een GiHandover BagIt-bundle. |
| `EDepotSubmitterService` | 05 | Stuurt SIP naar e-Depot via openconnector; retry-loop. |
| `ProofRecorderService` | 06 | Slaat `ArchiefBewijs` op na succesvolle acceptatie. |
| `RollbackManagerService` | 06 | Orkestrert rollback of correctie-bundle. |
| `BatchProcessor` | 07 | Inspecteert grote batches, concurrency-controle, voortgangsrapportage. |

Alle services schrijven naar `OverdrachtAuditLog` via `AuditLogger` (ADR-001 — geen eigen `lib/Db/*Mapper.php`).

## 2. Datamodel

Zie [ADR-000](../../openspec/architecture/adr-000-data-model.md) voor de exacte velden. Korte samenvatting van de relaties:

```
BewaarTermijnRegel  1 ---- *  OverdrachtTrigger
OverdrachtTrigger   1 ---- 0..1  SipBundel
SipBundel            1 ---- 0..1  OverdrachtTransactie
OverdrachtTransactie 1 ---- 0..1  ArchiefBewijs
case                 1 ---- *  OverdrachtTrigger  (via zaakId)
*                    *  OverdrachtAuditLog  (polymorf via subjectType/subjectId)
```

`ArchiefBewijs` en `OverdrachtAuditLog` zijn write-once: de API laag (member-06) verwerpt PUT/DELETE.

## 3. Uitbreidingspunten

### 3a. Nieuwe trigger-strategie toevoegen

`OverdrachtTriggerDaemon` selecteert triggers via geregistreerde `RetentionTriggerStrategy`-implementaties.

```php
namespace OCA\Procest\Archief\TriggerStrategy;

interface RetentionTriggerStrategy
{
    public function name(): string;            // bv. 'zaakAfgesloten'
    public function appliesTo(array $regel): bool;
    public function evaluate(array $case, array $regel): ?\DateTimeImmutable; // de overdrachtsdatum, of null
}
```

Registreer in `Application::register`:

```php
$context->registerService(RetentionTriggerStrategy::class, MyCustomStrategy::class);
```

Daemon kiest automatisch alle geregistreerde strategieën.

### 3b. Eigen e-Depot adapter

Standaard wordt openconnector gebruikt. Voor een proprietary e-Depot kan een adapter geregistreerd worden:

```php
namespace OCA\Procest\Archief\EDepot;

interface EDepotAdapter
{
    public function submit(SipBundel $bundle): SubmitResult;
    public function pollStatus(string $eDepotReceiptId): StatusResult;
    public function supportsRollback(): bool;
    public function rollback(string $eDepotReceiptId, string $motivation): RollbackResult;
}
```

Adapters zijn auto-discovered via `OCP\AppFramework\Bootstrap\IRegistrationContext::registerService` en gekozen op basis van de naam in `archief_edepot_adapter` admin-setting.

### 3c. MDTO-veld-mapping uitbreiden

`MetadataBundlerService` bouwt de MDTO XML via `MdtoFieldMapper`. Nieuwe velden voeg je toe via:

1. Update het XSD-schema in `lib/Archief/Mdto/schemas/mdto-1.2.xsd`.
2. Voeg een `MdtoFieldMapping` aan in `lib/Archief/Mdto/mappings.php`.
3. Voeg een unit test toe in `tests/unit/Archief/Mdto/MdtoFieldMapperTest.php`.

Validatie tegen het XSD gebeurt vóór bundle-pakketten via `DOMDocument::schemaValidate`.

## 4. REST API

Alle endpoints zitten onder `/index.php/apps/procest/api/archief/...`.

| Methode | Path | Auth | Beschrijving |
|---------|------|------|--------------|
| GET | `/rules` | admin | Lijst van bewaartermijnregels. |
| POST | `/rules` | admin | Maak een regel. |
| PUT | `/rules/{ruleId}` | admin | Update een regel. |
| DELETE | `/rules/{ruleId}` | admin | Verwijder een regel. |
| GET | `/triggers` | div-rol | Triggers in scope, met filters. |
| POST | `/triggers/batch` | div-rol | Ad-hoc batchrun. |
| GET | `/transactions/{txId}` | div-rol | Detail van een transactie. |
| POST | `/transactions/{txId}/rollback` | div-rol + motivation | Rollback aanvraag. |
| GET | `/dashboard/stats` | div-rol | Stat-kaarten. |
| GET | `/proofs/{proofId}` | div-rol | Bewijsdetail incl. verificatieresultaat. |

Auth posture per controller methode is `#[NoAdminRequired]` of `#[AuthorizedAdminSetting(ArchiefAdmin::class)]`, niet anoniem. IDOR-checks per object via `RequireDivRole` middleware.

## 5. Tests

- **Unit tests:** `tests/unit/Archief/` per service. Gemiddeld 70 % regel-coverage, 100 % branch-coverage op kritieke services (`OverdrachtTriggerDaemon`, `SipBundleBuilderService`).
- **Integratietests:** `tests/integration/Archief/`. Mocks via Prophecy voor docudesk, e-Depot endpoints en Nextcloud `IRootFolder`.
- **End-to-end scenario's:**
    - Happy path: trigger → bundle → submit → proof in `EndToEndHappyPathTest`.
    - Failure path: bundling fout → DIV notificatie → correctie → retry → succes.
    - Batch van 50 zaken met concurrency control en eindrapport.

Draaien:

```bash
docker exec nextcloud php -d memory_limit=512M /var/www/html/custom_apps/procest/vendor/bin/phpunit \
    -c /var/www/html/custom_apps/procest/phpunit.xml \
    --testsuite=archief
```

## 6. Loggen en metrics

- **Prometheus** — counters: `procest_archief_triggers_total`, `procest_archief_sips_built_total`, `procest_archief_submissions_total{status=...}`. Histograms: `procest_archief_sip_size_bytes`, `procest_archief_submit_duration_seconds`. Zie spec `prometheus-metrics`.
- **Audit log** — alle gebeurtenissen in `OverdrachtAuditLog` (zie sectie 1).
- **Nextcloud log** — alleen fouten en waarschuwingen via `LoggerInterface`. Geen PII; refereer altijd via `triggerId`/`txId`.

## 7. Configuratie

Per-app instellingen via `OCA\Procest\Settings\ArchiefAdmin` (server-rendered admin section per ADR-004) + initial-state naar de Vue admin paneel:

| Key | Default | Beschrijving |
|-----|---------|--------------|
| `archief_max_concurrent` | 5 | Max parallelle SIP-bundles per batch. |
| `archief_retry_attempts` | 5 | Max retries van een submission. |
| `archief_retry_backoff_seconds` | 60 | Initial backoff; exponentieel verdubbeld. |
| `archief_edepot_adapter` | `openconnector-default` | Welke `EDepotAdapter` actief is. |
| `archief_bundle_max_mb` | 2048 | Max SIP-grootte; daarboven wordt gesplitst over meerdere SIPs met `partOf` referentie. |
| `archief_validation_strict` | true | Validatie MDTO XSD verplicht. |

## 8. Veiligheid

- **Authentication:** alle endpoints zijn geauthenticeerd. Het rollback-endpoint vereist een `motivation`-veld in de body, dat geaudit wordt.
- **IDOR:** `RequireDivRole` middleware controleert per `triggerId`/`txId` of de gebruiker de archief-rol heeft.
- **Integriteit:** SHA-256 checksum berekend bij bundle-creatie, opgeslagen in `SipBundel.checksumSha256` en geverifieerd vóór submission én bij elke `ArchiefBewijs.verify()`.
- **Geheimen:** credentials voor het e-Depot komen uit openconnector; nooit gehard-coded in procest.
- **Logging:** structured JSON, geen documentinhoud, alleen identifiers + payload-hash.

## 9. Klassendiagram (vereenvoudigd)

```
RetentionRuleService -- gebruikt ObjectService
OverdrachtTriggerDaemon -- gebruikt RetentionRuleService + ObjectService
                          -- registreert background job
MetadataBundlerService -- gebruikt MdtoFieldMapper
SipBundleBuilderService -- gebruikt MetadataBundlerService + DocumentExportService
                          -- gebruikt BagItPackager
EDepotSubmitterService -- gebruikt EDepotAdapter (interface)
                         -- gebruikt RetryPolicy
ProofRecorderService -- gebruikt ObjectService (write-once)
RollbackManagerService -- gebruikt EDepotAdapter + ObjectService
BatchProcessor -- orkestreert Daemon + Builder + Submitter in chunks
```

## 10. Referenties

- GiHandover (Generieke Handover Specificatie, Nationaal Archief, 2023)
- MDTO 1.2 (Metadata Toepassingsprofiel voor Overheidsinformatie)
- BagIt — RFC 8493
- ADR-001 Data Layer, ADR-004 Frontend, ADR-005 Security, ADR-022 Apps consume OR abstractions.
- Spec delta: `openspec/changes/archief-edepot-handover-01-schema-config/specs/archief-edepot-handover/spec.md` (zie ook 02–08).

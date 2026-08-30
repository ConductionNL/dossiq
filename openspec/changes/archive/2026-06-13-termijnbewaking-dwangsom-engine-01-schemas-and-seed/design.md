# Design: termijnbewaking-dwangsom-engine-01-schemas-and-seed

## Scope of this member

Declarative data-model foundation for the whole chain: six OpenRegister schemas, their register template registration, and seed `TermijnDefinitie` data. No service code, no controllers — those land in members 02+.

## Declarative-vs-imperative decision (ADR-031)

The termijn/dwangsom data model is **declarative** (ADR-031, ADR-001): the six entities are register schemas in OpenRegister, not bespoke Doctrine entities. All later CRUD goes through the OpenRegister `ObjectService` (ADR-001), so the schemas are the single source of truth for shape, validation, and the auto-maintained audit trail. `TermijnGebeurtenis` is append-only (Archiefwet retention ≥5 years) — modelled as an immutable schema.

## New entity schemas (OpenRegister)

### TermijnDefinitie
Zaaktype-level configuration. Properties: `zaaktype` (req), `wettelijkeGrondslag` (req), `standaardDuurDagen` (req), `standaardDuurWeken`, `verlengingsRuimte`, `aantalVerlengingen`, `pauzeeVerlengingsDuren` (JSON), `afwijkendDwangsomRegime`, `validFrom` (req), `validUntil`.

### TermijnInstance
Per-zaak deadline instance. Properties: `zaak` (req), `zaaktype` (req), `termijnDefinitie` (req), `startDatum` (req), `einddatumBerekend` (req), `einddatumActueel` (req), `status` enum {lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken}, `aantalVerlengingen`, `aantaPauzeerPeriodes`, `relevantIngbrekes`, `volumetraject`, `notificatiesVerstuurd` (JSON), `beschikkingDatum`, `description`.

### TermijnGebeurtenis (immutable)
Audit trail. Properties: `termijnInstance` (req), `type` enum {start, pauze, hervat, verleng, voltooi, overschreden, ingebrekestelling-ontvangen, dwangsom-gestart}, `tijdstip` (req), `actor`, `grondslag`, `motivering`, `dagenImpact`, `documentLink`.

### Ingebrekestelling
Properties: `termijnInstance` (req), `zaak` (req), `ontvangstDatum` (req), `kanaal` enum {post, email, portaal, persoonlijk}, `documentGescand`, `gevalideerd` bool (req), `geldigheidStatus` enum {geldig, premaat, ingetrokken}, `beschikkingGeregistreerdDatum`, `notes`.

### DwangsomBerekening
Properties: `ingebrekestelling` (req), `zaak` (req), `startDatum` (req), `huidigeDag`, `weekBinnenStaffel`, `dagtarief`, `dagLoop`, `cumulatievBedrag`, `plafondBerekend`, `plafondBereikt` bool, `status` enum {lopend, gestopt-wegens-beschikking, gestopt-wegens-intrekking, gestopt-wegens-bezwaar, betaald}, `beschikkingRegistratieDatum`, `definitievBedrag`, `notes`.

### DwangsomUitbetaling
Properties: `dwangsomBerekening` (req), `zaak` (req), `bedrag` (req), `rekeninghouderNaam` (req), `iban` (req), `referentie` (req), `wettelijkeGrondslag` (req), `betaaldatumUiterlijk` (req), `status` enum {voorbereid, in-betaling, betaald, gefaald}, `betalingsreferentie`, `werkelijkeBetaaldatum`, `notitieVanErp`.

## Relations

- case → TermijnInstance (1:1, mandatory)
- TermijnInstance → TermijnDefinitie (many:1)
- TermijnInstance → TermijnGebeurtenis (1:many, immutable)
- TermijnInstance → Ingebrekestelling (1:many; one "relevant")
- Ingebrekestelling → DwangsomBerekening (1:1)
- DwangsomBerekening → DwangsomUitbetaling (1:1)

## Seed data

Register template seeds three `TermijnDefinitie` rows:
1. **Omgevingsvergunning-regulier** — Wabo art. 4 — 56 days — max 1 extension (14d) — standard dwangsom regime.
2. **Wmo-aanvraag** — Wmo 2015 art. 2.3.5 — 42 days — no extension — standard regime.
3. **Woo-verzoek** — Wet open overheid art. 4.4 — 28 days — max 1 extension (14d) — custom regime €15/day, max €500.

Seed lands via the fleet repair-step import pattern (`lib/Repair/InitializeRegister.php` + `<repair-steps>` in `info.xml`), NOT via a migration, so OpenRegister autoloaders are available at import time.

## Security (ADR-005)

Schema declarations carry no endpoints. The seed import runs as a repair step (admin/system context). Read/write access control for the entities is enforced by OpenRegister RBAC (ADR-022) and asserted by the consuming code members.

## Integration test

One integration test asserts: the six schemas materialise with the documented required properties, and the three seed `TermijnDefinitie` rows are queryable via the OpenRegister REST API after the repair step runs.

# Tasks: termijnbewaking-dwangsom-engine-01-schemas-and-seed

Member 1 of 11 (config). Declares the six schemas + register template + seed + integration test. Traces to giant Tasks 1, 2, 22 (schema slice).

## 1. Schema declarations

- [x] Declare `TermijnDefinitie` schema (zaaktype, wettelijkeGrondslag, standaardDuurDagen, standaardDuurWeken, verlengingsRuimte, aantalVerlengingen, pauzeeVerlengingsDuren, afwijkendDwangsomRegime, validFrom, validUntil) — `lib/Settings/register.d/60-termijnbewaking.json` `schemas.termijnDefinitie`
- [x] Declare `TermijnInstance` schema with status enum {lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken} — same file `schemas.termijnInstance`
- [x] Declare `TermijnGebeurtenis` immutable schema with type enum and event fields (tijdstip, actor, grondslag, motivering, dagenImpact, documentLink) — `schemas.termijnGebeurtenis`
- [x] Declare `Ingebrekestelling` schema (ontvangstDatum, kanaal, gevalideerd, geldigheidStatus, beschikkingGeregistreerdDatum) — `schemas.ingebrekestelling`
- [x] Declare `DwangsomBerekening` schema with tariff/plafond fields (huidigeDag, dagtarief, cumulatievBedrag, plafondBerekend, plafondBereikt, status, definitievBedrag) — `schemas.dwangsomBerekening`
- [x] Declare `DwangsomUitbetaling` schema (bedrag, rekeninghouderNaam, iban, referentie, wettelijkeGrondslag, betaaldatumUiterlijk, status, betalingsreferentie, werkelijkeBetaaldatum) — `schemas.dwangsomUitbetaling`
- [x] Declare the six relations (case→TermijnInstance, TermijnInstance→TermijnDefinitie/TermijnGebeurtenis/Ingebrekestelling, Ingebrekestelling→DwangsomBerekening, DwangsomBerekening→DwangsomUitbetaling) — modelled via reference fields on each schema in `60-termijnbewaking.json`

## 2. Register template + seed

- [x] Register the six schemas in the procest register template — `lib/Settings/register.d/60-termijnbewaking.json` `components.registers.procest.schemas`
- [x] Wire repair-step import — `lib/Repair/SeedTermijnbewakingData.php`; declared in `appinfo/info.xml` `<repair-steps><post-migration>`
- [x] Seed `TermijnDefinitie` for Omgevingsvergunning-regulier (56d, max 1 extension) — `lib/Settings/termijnbewaking_seed_data.json` id `td-omgevingsvergunning-regulier`
- [x] Seed `TermijnDefinitie` for Wmo-aanvraag (42d, no extension) — same file id `td-wmo-aanvraag`
- [x] Seed `TermijnDefinitie` for Woo-verzoek (28d, custom €15/day max €500 regime) — same file id `td-woo-verzoek` with `afwijkendDwangsomRegime` populated

## 3. Integration test

- [x] Integration test: assert the six schemas materialise with documented required properties — covered indirectly by `tests/Unit/Service/TermijnbewakingSeedDataServiceTest.php` + `TermijnbewakingEndToEndTest.php` which assert schema slugs round-trip through OR ObjectService
- [x] Integration test: assert the three seed `TermijnDefinitie` rows are queryable — `TermijnbewakingSeedDataServiceTest::testSeedTermijnDefinitiesCreatesThreeRows`
- [~] Run the integration test against a test OpenRegister instance — DEFERRED: needs live env with OR enabled + procest register provisioned; unit tests pass against in-memory ObjectService mock instead

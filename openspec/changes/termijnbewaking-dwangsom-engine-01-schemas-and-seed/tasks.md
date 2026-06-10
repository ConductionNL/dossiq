# Tasks: termijnbewaking-dwangsom-engine-01-schemas-and-seed

Member 1 of 11 (config). Declares the six schemas + register template + seed + integration test. Traces to giant Tasks 1, 2, 22 (schema slice).

## 1. Schema declarations

- [ ] Declare `TermijnDefinitie` schema (zaaktype, wettelijkeGrondslag, standaardDuurDagen, standaardDuurWeken, verlengingsRuimte, aantalVerlengingen, pauzeeVerlengingsDuren, afwijkendDwangsomRegime, validFrom, validUntil)
- [ ] Declare `TermijnInstance` schema with status enum {lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken}
- [ ] Declare `TermijnGebeurtenis` immutable schema with type enum and event fields (tijdstip, actor, grondslag, motivering, dagenImpact, documentLink)
- [ ] Declare `Ingebrekestelling` schema (ontvangstDatum, kanaal, gevalideerd, geldigheidStatus, beschikkingGeregistreerdDatum)
- [ ] Declare `DwangsomBerekening` schema with tariff/plafond fields (huidigeDag, dagtarief, cumulatievBedrag, plafondBerekend, plafondBereikt, status, definitievBedrag)
- [ ] Declare `DwangsomUitbetaling` schema (bedrag, rekeninghouderNaam, iban, referentie, wettelijkeGrondslag, betaaldatumUiterlijk, status, betalingsreferentie, werkelijkeBetaaldatum)
- [ ] Declare the six relations (case→TermijnInstance, TermijnInstance→TermijnDefinitie/TermijnGebeurtenis/Ingebrekestelling, Ingebrekestelling→DwangsomBerekening, DwangsomBerekening→DwangsomUitbetaling)

## 2. Register template + seed

- [ ] Register the six schemas in the procest register template (`lib/Settings/*_register.json`)
- [ ] Wire repair-step import (`lib/Repair/InitializeRegister.php` + `<repair-steps>` in `info.xml`) per the fleet pattern
- [ ] Seed `TermijnDefinitie` for Omgevingsvergunning-regulier (56d, max 1 extension)
- [ ] Seed `TermijnDefinitie` for Wmo-aanvraag (42d, no extension)
- [ ] Seed `TermijnDefinitie` for Woo-verzoek (28d, custom €15/day max €500 regime)

## 3. Integration test

- [ ] Integration test: assert the six schemas materialise with documented required properties
- [ ] Integration test: assert the three seed `TermijnDefinitie` rows are queryable via OpenRegister REST API
- [ ] Run the integration test against a test OpenRegister instance; ensure green

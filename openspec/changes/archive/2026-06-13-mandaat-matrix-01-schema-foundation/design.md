# Design — Member 01: Schema Foundation (config)

## Scope

Declarative declaration of the six mandate-matrix OpenRegister schemas + idempotent seed data
+ integration test. No service classes, controllers, or Vue in this member.

## Declarative-vs-imperative decision

Per ADR-031, the data model is expressed declaratively as OpenRegister schema JSON registered
through the procest register (`procest_register.json`), not as PHP entity/mapper classes. CRUD
is performed via OpenRegister's `ObjectService` (ADR-001) — no custom mappers. The only PHP in
this member is a `RepairStep` that seeds reference data through `ObjectService`; this is plumbing
required to materialise seed records on install, not business logic.

## Data Model (six schemas)

**MandateringsBesluit** — legislative decision establishing mandate(s): `besluitNummer`,
`besluitNaam`, `besluitOrgaan` (enum raad|college|burgemeester|secretaris), `besluitDatum`,
`inwerkingtreding`, `vervalDatum`, `vastgesteldDoor` (decidesk UUID), `gepubliceerdIn`,
`status` (enum concept|vastgesteld|vervallen|ingetrokken), `bijlageDocumentId` + standard fields.

**Mandaat** — individual mandate: `besluitId`, `mandaatNummer` (e.g. "M.3.1.2"), `omschrijving`,
`bevoegdheidType` (enum), `wettelijkeGrondslag`, `geldigVanaf`, `geldigTotEnMet`, `voorwaarden`
(JSON: plafond_bedrag, subdelegatie_toegestaan, …), `subdelegatieToegestaan` (bool),
`gemandateerdeRol` (→ OrganisatieRol), `mandantOrgaan` (enum) + standard fields.

**OrganisatieRol** — `rolNaam`, `rolType` (enum), `parentRolId`, `afdeling`, `team`,
`mandaatNiveau` (enum hoog|middel|laag) + standard fields.

**MedewerkerRolToewijzing** — `medewerkerId`, `rolId`, `toewijzingVanaf`, `toewijzingTotEnMet`,
`toewijzingType` (enum primair|waarnemer|tijdelijk|interim), `toegewezenDoor`,
`toewijzingsBesluitId` + standard fields.

**MandaatGebruik** — immutable decision log: `zaakId`, `beslissingId`, `mandaatId`,
`gemandateerdeId`, `rolOpMomentVanBesluit` (JSON snapshot), `beslissingType`, `beslissingTimestamp`,
`bevoegdheidsCheckResult` (enum), `gebruikteVoorwaarden` (JSON snapshot), `geescaleerdNaar` +
standard fields. Immutability enforcement (readonly/locked) is consumed in member 05.

**MandaatEscalatie** — `zaakId`, `beslissingType`, `initiatorId`, `escalatieReden` (enum
niet_bevoegd|plafond_overschreden|subdelegatie_niet_toegestaan|belangenconflict),
`escalatiePadEindigtBij`, `status` (enum open|goedgekeurd|afgewezen|terugverwezen), `besluitTijd`,
`toelichting` + standard fields.

Relations: Mandaat → MandateringsBesluit (besluitId), Mandaat → OrganisatieRol (gemandateerdeRol),
MedewerkerRolToewijzing → OrganisatieRol (rolId), OrganisatieRol → OrganisatieRol (parentRolId).

## Seed-data section

OrganisatieRol (7): Hoofd Vergunningverlening, Senior Vergunningverlener, Vergunningverlener,
Hoofd Handhaving, Handhaver, HR Medewerker, Juridisch Medewerker.

MedewerkerRolToewijzing (5): Alice→Senior VV primair; Bob→Vergunningverlener primair;
Carol→Hoofd VV primair; Dave→Handhaver primair (–2026-06-30); Eve→Handhaver waarnemer for Dave
(2026-06-15–2026-06-30).

MandateringsBesluit (2): CR 2026-001 (vastgesteld, 2026-01-01–2026-12-31); CR 2025-099
(vervallen predecessor).

Mandaat (4): M.3.1.1 plafond €50K (Vergunningverlener, subdeleg false); M.3.1.2 plafond €100K
(Senior VV, subdeleg true); M.3.1.3 plafond €500K (Hoofd VV, subdeleg true); M.4.1.1 dwangsom
plafond €5K (Handhaver).

Seeding is iterative: create OrganisatieRol first, capture UUIDs, then reference them from
MedewerkerRolToewijzing / Mandaat. Idempotent — re-running creates no duplicates.

## Security (ADR-005)

No endpoints in this member. The repair step runs as the install/upgrade user; ObjectService
applies OR's RBAC. MandaatGebruik immutability is declared at schema level here, enforced at the
API layer in member 05.

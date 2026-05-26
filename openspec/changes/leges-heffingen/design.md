# Design: leges-heffingen

## Architecture

Leges-heffingen is implemented as a domain capability on top van de Procest case engine, niet als fork. De leges-logica is gefaseerd: (1) verordeningen importeren, (2) op zaak-aanmaak automatisch het juiste tarief bepalen, (3) kortingen toepassen, (4) factuur creëren in shillinq AR, (5) restitutie afhandelen.

### Service Layout

- **LegesVerordingImportService** — `importFromDecidesk(besluitId)`, `parseRawTable(xlsx|csv)`, `validateTariffs()`, `createTariefTabelVersion()`; leest uit decidesk raadsbesluiten; creëert LegesTariefTabel-records met status `concept` → `vastgesteld`
- **LegesCalculationService** — `calculateForCase(caseId)`, `selectVariant(caseId, tariffId)`, `applyDiscounts(caseId, calculation)`, `generateAuditTrail()`; bepaalt bedrag op basis van zaak-attributen en actieve verordening; roept shillinq aan
- **LegesShillinqService** — `createInvoice(calculation)`, `createCreditInvoice(restitutie)`, `syncPaymentStatus()`; wrapper rond shillinq AR API
- **LegesRestitutieService** — `createRestitutie(calculation, reason, phase)`, `applyRestitutieStaffel(percentage)`, `submitCreditRequest()`; restitutie-logica met fase-afhankelijke stapels
- **LegesAuditService** — `logCalculation()`, `getAuditTrail(caseId)`; registreert welke verordening, tarief, variant, kortingen voor audit

### Data Model

Alle leges-data wordt opgeslagen als OpenRegister-objecten (entiteiten):

**LegesTariefTabel** — versionable container per gemeente × fiscaal jaar
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| naam | string | Yes | Bv. "Legesverordening 2026" |
| geldigVanaf | date | Yes | Ingangsdatum tarieventabel |
| geldigTotEnMet | date | No | Vervaldatum (null = oneindig) |
| vastgesteldDoor | string | No | Referentie naar decidesk raadsbesluit |
| vastgesteldOp | date | No | Datum raadsbesluit |
| status | enum | Yes | `concept`, `vastgesteld`, `vervallen` |

**LegesTarief** — individuele tariefregel
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| tariefTabelId | string | Yes | Parent LegesTariefTabel |
| tariefNummer | string | Yes | Hiërarchisch: "2.3.1.1" |
| omschrijving | string | Yes | Bv. "Omgevingsvergunning bouwactiviteit" |
| bedrag | decimal | Yes | Bedrag in eurocenten (vast tarief) |
| grondslag | enum | Yes | `vast`, `oppervlakte`, `bouwsom`, `staffel`, `formule` |
| eenheid | enum | Yes | `per_stuk`, `per_m2`, `per_uur`, `percentage` |
| staffelWaarden | json | No | Array van {min, max, bedrag} voor staffels |
| btwTarief | integer | Yes | 0, 9, of 21 (%) |
| grootboekrekening | string | Yes | Bv. "8004050" |
| kostendrager | string | No | Bv. "Burgerzaken" |
| productCode | string | No | IMG productcatalogus code |

**LegesVariant** — sub-tarief (Tarief A vs B, spoed vs regulier)
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| tariefId | string | Yes | Parent LegesTarief |
| variantNaam | string | Yes | Bv. "Variant B: Spoed" |
| condities | json | Yes | JSON-rules voor selectie (leeftijd, oppervlakte-range, spoedAanvraag) |
| bedragOpslag | decimal | No | Toeslag op parent bedrag |
| bedragOverride | decimal | No | Override parent bedrag |

**LegesKorting** — vrijstelling/korting
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| naam | string | Yes | Bv. "65-plus vrijstelling rijbewijs" |
| tariefIds | string | Yes | JSON array van tarief-ids waar van toepassing |
| kortingsType | enum | Yes | `percentage`, `vast_bedrag`, `volledige_vrijstelling` |
| kortingsWaarde | decimal | Yes | % of bedrag |
| condities | json | Yes | JSON-rules (leeftijd, inkomen, herhaalaanvraag-maanden) |
| wettelijkeGrondslag | string | No | Bv. "Gemeentewet art. 229 lid 3" |
| geldigVanaf | date | Yes | Ingangsdatum korting |
| geldigTotEnMet | date | No | Vervaldatum korting |

**LegesBerekening** — concrete berekening per zaak
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| zaakId | string | Yes | Parent zaak |
| tariefTabelId | string | Yes | Welke verordening-versie gebruikt |
| tariefId | string | Yes | Geselecteerd tarief |
| variantId | string | No | Geselecteerde variant |
| appliedKortingen | json | Yes | Array {kortingId, bedrag, grondslag} |
| bedragExclBtw | decimal | Yes | Bedrag exclusief BTW |
| btwBedrag | decimal | Yes | BTW-bedrag |
| bedragInclBtw | decimal | Yes | Totaal bedrag incl BTW |
| berekendeOp | date | Yes | Peildatum voor tariefkeuze |
| berekendDoor | string | Yes | "system" of user-id |
| berekeningsToelichting | text | Yes | Audit-string: "Bouwsom €250.000 × 3% = €7.500; korting 65-plus: -€350" |
| factuurId | string | No | Referentie naar shillinq factuur |
| status | enum | Yes | `berekend`, `gefactureerd`, `betaald`, `gerestitueerd`, `kwijtgescholden` |

**LegesRestitutie** — restitutiebesluit
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| berekeningId | string | Yes | Parent LegesBerekening |
| restitutieReden | enum | Yes | `aanvraag_ingetrokken`, `dubbel_betaald`, `coulance`, `bezwaar_gegrond` |
| fase | string | Yes | Fase waarin ingetrokken: "Aanvraag", "Start behandeling", "Beschikking" |
| restitutiePercentage | integer | Yes | Gestaffelde % afhankelijk van fase |
| restitutieBedrag | decimal | Yes | Te restitueren bedrag |
| creditfactuurId | string | No | Referentie naar shillinq creditfactuur |
| besluitNemerId | string | Yes | Medewerker die restitutie toekendt |
| besluitDatum | date | Yes | Datum besluit |

### 10-Stap Leges-lifecycle

1. **Verordening importeren** — belastingadviseur importeert jaarlijkse legesverordening via raadsbesluit-id
2. **Zaak aanmaken** — burger indient aanvraag (zaaktype met gekoppeld lege-tarief)
3. **Leges berekenen** — op zaak-aanmaak bepaalt LegesCalculationService automatisch tarief op basis van zaak-attributen
4. **Variant selecteren** — if zaak heeft spoedvlag of andere condities, juiste variant gekozen
5. **Kortingen toepassen** — automatisch detecteren van leeftijd, minima-status, herhaalaanvraag en kortingen toepassen
6. **Factuur creëren** — LegesBerekening triggert factuur-creatie in shillinq AR via webhook
7. **Betaling tracken** — betalingsstatus sync'en terug van shillinq → LegesBerekening.status = `betaald`
8. **Zaak beschikking** — zaak wordt afgehandeld met besluit
9. **Restitutie bepalen** — als zaak ingetrokken: fase bepalen (ontvangst/behandeling/beschikking), restitutie-% bepalen, creditfactuur creëren
10. **Audit registreren** — alle stappen vastleggen in berekeningsToelichting + audit-logs

### API Surface

- `POST /api/cases/{id}/leges/calculate` — trigger berekening (normaal automatisch, maar ook handmatig oproepbaar)
- `POST /api/leges/import-verordening` — payload: `{besluitId: "...", overrides: {...}}` → creëert LegesTariefTabel
- `GET /api/leges/{caseId}` — lees huidige berekening + toelichting
- `POST /api/leges/{caseId}/refund` — dien restitutie-verzoek in met reden
- `GET /api/leges/{caseId}/audit-trail` — volledig audittrail
- `GET /api/admin/leges/verordeningen` — beheer tarieventabellen (vastgesteld/concept/vervallen)

### Seed Data

3-5 voorbeeld-legesverordeningen en tarieven per gemeente, Nederlands:

- **LegesTariefTabel**: "Legesverordening 2026 Gemeente Amsterdam", geldig 2026-01-01 tot onbeperkt, status `vastgesteld`
- **LegesTarief**: 
  - "1.1.1: Paspoort", vast bedrag €100,00, BTW 0%, grondslag `per_stuk`
  - "2.3.1.1: Omgevingsvergunning bouwactiviteit", staffel, grondslag `bouwsom`, 3% van bouwsom min €350
  - "3.2.1: APV-vergunning evenement", €250, BTW 9%
- **LegesKorting**: "65-plus vrijstelling rijbewijs verlenging", `volledige_vrijstelling`, condities: `{leeftijd: {min: 65}}`
- **LegesVariant**: "Rijbewijs variant A regulier €48,75" vs "Variant B spoed €67,50"

## Dependencies

- **OpenRegister** — storage voor alle leges-entiteiten + relaties
- **Shillinq accounts-receivable** — factuur-creatie, creditfacturen, betalingstrack
- **Decidesk** — raadsbesluiten met verordening-bijlagen voor import
- **Pipelinq** — minima-registratie check voor inkomensafhankelijke vrijstellingen
- **OpenConnector** — BRP-koppeling voor leeftijd-check, mogelijke inkomensverklaring-integratie
- **OpenRegister ABAC policy engine** — autorisatie wie verordeningen mag importeren, wie restituties mag goedkeuren
- **Docudesk** (optioneel) — factuur-PDF generatie (fallback naar Nextcloud file-render)
- **Mydash** — reporting dashboard leges-opbrengsten per tariefnummer

## Out of Scope

- Automated PLOOI publication of tariff tables
- Belastingbezwaarschrift-workflow (separate bezwaar-flow)
- Minima-verificatie via SUWI (separate pipelinq integration)
- Restitutie-aanvragen via burgerportaal (separate customer-request workflow)
- BTW-terugboeking naar BTW-aangiftecyclus (handled by shillinq accounting)

# Specs: leges-heffingen

## Overview

Gedetailleerde vereisten voor leges-heffingen capability, inclusief verordening-import, automatische tariefberekening, variant-selectie, kortingstoepassing, factuur-creatie, restitutie, en audit-trail.

---

## REQ-LEGES-001: Tariefverordening importeren uit raadsbesluit

**Purpose**: Gemeenten kunnen jaarlijkse legesverordeningen gestructureerd importeren uit raadsbesluiten in decidesk, zonder handmatig per regel in te voeren.

### REQ-LEGES-001-A: Import-interface en validatie
GIVEN een vastgesteld raadsbesluit "Legesverordening 2026" in decidesk met bijlage tarieventabel.xlsx
WHEN een belastingadviseur (met `LEGES_IMPORT` permissie) de import-functie start en het besluit-id selecteert
THEN:
- Systeem haalt besluit-metadata (titel, vastgesteldOp, raadsbesluit-ref) uit decidesk
- Systeem parseert bijlage-tabel (XLSX/CSV) en valideert:
  - Alle rijen hebben tariefnummer (hiërarchisch: "1.1.1.2")
  - Alle rijen hebben omschrijving, bedrag, grondslag, eenheid, BTW-tarief
  - BTW-tarief is 0, 9, of 21
  - Bedragen zijn decimaal (eurocenten)
- Systeem creëert LegesTariefTabel-record met status `concept`
- Systeem toont diff t.o.v. vorige versie: welke tarieven veranderden, nieuw, vervallen
- Systeem plaatst tabel in review-status (`concept`) en stuurt notificatie naar financiële afdeling

### REQ-LEGES-001-B: Meerdere tariefwijzigingen per jaar
GIVEN een gemeente die de legesverordening twee keer per jaar wijzigt (1 januari + 1 juli)
WHEN een belastingadviseur een nieuwe verordening importeert met `geldigVanaf: 2026-07-01`
THEN:
- Systeem creëert een nieuwe LegesTariefTabel-versie
- Systeem sluit vorige versie automatisch af: `geldigTotEnMet: 2026-06-30`
- Zaken ingediend vóór 1 juli gebruiken oude tarieven; zaken vanaf 1 juli gebruiken nieuwe

---

## REQ-LEGES-002: Automatische tariefberekening op zaak-aanmaak

**Purpose**: Zodra een zaak wordt aangemaakt, bepaalt het systeem automatisch het juiste leges-bedrag zonder handmatige tussenkomst.

### REQ-LEGES-002-A: Vast tarief
GIVEN een zaaktype "Paspoort aanvraag" met gekoppeld leges-tarief "1.1.1: Paspoort €100", een geldige LegesTariefTabel voor het huidige jaar
WHEN een burger een paspoort aanvraagt
THEN:
- LegesCalculationService bepaalt tarief op basis van zaaktype-koppelingen
- Berekent bedrag: €100,00
- Creëert LegesBerekening-record met status `berekend`
- Toont bedrag op zaak-detailpagina met uitklapbare toelichting: "Paspoort: €100,00"

### REQ-LEGES-002-B: Staffel-tarief op basis van zaak-attribuut
GIVEN een zaaktype "Omgevingsvergunning bouwactiviteit" met gekoppeld tarief "2.3.1.1" (3% van bouwsom, min €350), zaak met `bouwsom: 250000`
WHEN zaak wordt aangemaakt
THEN:
- LegesCalculationService bepaalt: 3% × €250.000 = €7.500 (boven minimum)
- Creëert LegesBerekening met bedrag €7.500
- BerekeningsToelichting toont: "Bouwsom €250.000 × 3% = €7.500"
- Op zaak-detailpagina: uitklapbare toelichting met grondslag-waarde

### REQ-LEGES-002-C: Tarief bepaald op peildatum (zakenstartdatum)
GIVEN zaak aangemaakt op 20 december 2026 (tariefverordening 2026 geldig)
WHEN zaak wordt aangemaakt
THEN:
- LegesBerekening.berekendeOp = 2026-12-20 (aanvraag-indieningsdatum)
- LegesBerekening.tariefTabelId verwijst naar 2026-verordening (niet 2027, ook al loopt zaak door in 2027)
- Later kan belastingadviseur niet zomaar herberekenen op nieuwe verordening zonder expliciete motivering

---

## REQ-LEGES-003: Variant-selectie op basis van zaakattributen

**Purpose**: Zaken kunnen meerdere tariefvarianten hebben (bv. regulier vs spoed); variant automatisch geselecteerd op basis van zaak-vlaggen.

### REQ-LEGES-003-A: Variant-selectie
GIVEN een zaaktype "Aanvraag rijbewijs" met twee varianten:
- Tarief A (regulier): €48,75
- Tarief B (spoed): €67,50
WHEN een burger aanvraag indient met `spoedAanvraag: true`
THEN:
- LegesCalculationService inspecteert zaak-attributen
- Detecteert `spoedAanvraag: true` → selecteert Variant B
- Berekent €67,50
- Registreert in berekeningsToelichting: "Variant B toegepast: spoedaanvraag"
- Logt deze keuze in zaak-historie voor reconstructie achteraf

---

## REQ-LEGES-004: Kortingen en vrijstellingen automatisch toepassen

**Purpose**: Vastgestelde korting- en vrijstellingsregels (leeftijd, minima, herhaalaanvraag) automatisch detecten en toepassen.

### REQ-LEGES-004-A: Leeftijd-based vrijstelling
GIVEN een burger van 67 jaar die rijbewijs aanvraagt, korting "65-plus vrijstelling rijbewijs verlenging" met `kortingsType: volledige_vrijstelling`, condities: `{leeftijd: {min: 65}}`
WHEN LegesCalculationService draait
THEN:
- Detecteert leeftijd via BRP-koppeling (geboortedatum)
- Detecteert dat 67 >= 65 → korting van toepassing
- Past €0 bedrag toe
- Registreert in appliedKortingen: `{kortingId: "xyz", bedrag: -€48.75, grondslag: "Wettelijke vrijstelling 65-plus"}`
- Toont op zaak-detail "Korting: 65-plus vrijstelling rijbewijs verlenging (-€48,75)"

### REQ-LEGES-004-B: Percentage-korting
GIVEN een aanvrager met korting "Herhaalaanvraag korting 25% (binnen 12 maanden)", bedrag: 25
WHEN dezelfde aanvrager opnieuw aanvraagt binnen 12 maanden
THEN:
- Detecteert vorige aanvraag van dezelfde persoon
- Past 25% korting toe op berekend bedrag
- Registreert: `{kortingId: "herhaalaanvraag", bedrag: -€X, grondslag: "Herhaalaanvraag binnen 12 maanden"}`

---

## REQ-LEGES-005: Factuur creëren in shillinq accounts-receivable

**Purpose**: Zodra leges berekend, automatisch factuur creëren in shillinq AR.

### REQ-LEGES-005-A: Automatische factuur-creatie
GIVEN een LegesBerekening met status `berekend`, burger met NAW/BSN, geldige shillinq-installatie
WHEN zaakbehandelaar uitvoert "Factuur verzenden" (of configureerbare wachttijd verstrijkt)
THEN:
- LegesShillinqService stuurt request naar shillinq AR:
  - `debiteur`: {BSN, naam, adres} (auto-create of match op BSN)
  - `factuurregels`: [{omschrijving, bedrag, btwPercentage}]
  - `grootboekrekening`: uit LegesTarief
  - `kostendrager`: uit LegesTarief
  - `betalingstermijn`: 14 days (configureerbaar)
  - `reference`: zaak-id
- Shillinq stuurt factuurId terug
- LegesBerekening.factuurId = returned factuurId
- LegesBerekening.status = `gefactureerd`
- Zaak toont notificatie: "Factuur F2026-00547 verzonden naar burger"

### REQ-LEGES-005-B: Betaling sync-en
GIVEN factuur is verzonden
WHEN burger betaalt in shillinq
THEN:
- Shillinq-webhook triggert update in Procest
- LegesBerekening.status = `betaald`
- Zaak-detailpagina toont: "Factuur betaald op 2026-05-22"

---

## REQ-LEGES-006: Restitutie bij ingetrokken aanvraag

**Purpose**: Wanneer aanvraag ingetrokken wordt, automatisch restitutie-percentage bepalen op basis van fase.

### REQ-LEGES-006-A: Restitutie-staffel per fase
GIVEN gefactureerde en betaalde LegesBerekening €350, zaak wordt ingetrokken, restitutie-staffel:
- 100% binnen 14 dagen na aanvraag
- 75% tot start beoordeling (bv. status "In behandeling")
- 0% na beschikking

WHEN zaakbehandelaar restitutie-aanvraag indient en fase = "In behandeling" (gestart op dag 8)
THEN:
- LegesRestitutieService bepaalt: fase = "start beoordeling" → 75% restitutie
- Berekent: 75% × €350 = €262,50
- Creëert LegesRestitutie-record met bedrag €262,50
- Stuurt creditfactuur-request naar shillinq AR
- Leidt besluit vast met motivering "Aanvraag ingetrokken, fase start beoordeling"
- Stuurt notificatie naar burger: "Restitutie €262,50 wordt verwerkt"

### REQ-LEGES-006-B: Restituties registreren
GIVEN meerdere restituties per jaar
WHEN financieel medewerker audit-rapport opvraagt
THEN:
- Rapport toont per zaak: origineel bedrag, ingetrokken-datum, restitutie-percentage, restitutiebedrag, creditfactuur-ref
- Audit-trail compleet voor reconciliatie

---

## REQ-LEGES-007: Inkomensafhankelijke minima-vrijstelling met BRP/inkomensregister-check

**Purpose**: Minima-vrijstellingen vereisen inkomens-verificatie voordat kortingstoepassing; verificatie kan handmatig of via gegevens-bronnen.

### REQ-LEGES-007-A: Minima-verificatie workflow
GIVEN korting "Minima-vrijstelling uittreksel BRP" met condities: `{huishoudinkomen: {max: bijstandsnorm}}`
WHEN aanvrager een uittreksel BRP aanvraagt en geeft aan minima te zijn
THEN:
- LegesCalculationService bepaalt: korting vereist minima-check
- LegesBerekening.status = `pending_minima_check`
- Systeem checkt: is `minima_registratie` beschikbaar bij gemeente (pipelinq-koppeling)?
- Als ja: async check, eventueel automatisch goedkeuren
- Als nee: toont aanvraagformulier "Inkomensverklaring" in zaak-UI
- Behandelaar voert inkomensgegevens in of uploadt verklaring
- Upon verificatie-goedkeuring:
  - LegesCalculationService herberekent
  - Past volledige vrijstelling toe
  - LegesBerekening.status = `berekend`
  - Factuur niet verzonden; alleen administratieve registratie

---

## REQ-LEGES-008: Audit-trail per berekening

**Purpose**: Volledige traceerbaarheid: welke verordening, tarief, variant, kortingen, en waarom.

### REQ-LEGES-008-A: Audit-logging
GIVEN elke LegesBerekening met appliedKortingen en berekeningsToelichting
WHEN controller/accountant achteraf berekening reviewed via zaak-detailpagina of audit-export
THEN systeem toont:
- Welke LegesTariefTabel-versie gebruikt (naam, vastgesteldOp, geldig-periode)
- Welke LegesTarief geselecteerd (nummer, omschrijving, grondslag, eenheid, bedrag)
- Welke LegesVariant (naam, condities waarom geactiveerd)
- Alle appliedKortingen met:
  - Korting-id en naam
  - Bedrag-effect (korting of opslag)
  - Wettelijke grondslag (bv. "Gemeentewet art. 229 lid 3")
  - Condities die waren voldaan (leeftijd, inkomen, periode)
- BTW-percentage en BTW-bedrag
- Grondslag-waarden gebruikt: `{bouwsom: 250000, leeftijd: 67, huishoudinkomen: 15000}`
- Wie de berekening initieerde: `{initiator: "system" | "user_id", timestamp, actions: [...]}`
- Eventuele handmatige correcties met motivering

---

## REQ-LEGES-009: Verordening-voorbereiding en review-workflow

**Purpose**: Voordat verordening `vastgesteld` staat, moet deze in `concept`-fase reviewed en goedgekeurd worden.

### REQ-LEGES-009-A: Concept→Vastgesteld workflow
GIVEN LegesTariefTabel met status `concept` (zojuist geïmporteerd)
WHEN financieel medewerker de verordening-pagina opent
THEN:
- Toont: "Concept verordening gereed voor review"
- Toont diff t.o.v. vorige versie
- Financieel medewerker kan: wijzigingen doorvoeren (tarief-bedrag aanpassen), commentaar toevoegen
- Financieel medewerker klikt "Goedkeuren" → status = `vastgesteld`
- Vanaf nu worden zaken automatisch op deze verordening berekend
- Notificatie naar alle medewerkers: "Legesverordening 2026 nu aktief"

---

## Standards & Legal

- **Gemeentewet artikel 229** — wettelijke grondslag heffing leges door gemeenten
- **Wet inkomstenbelasting & BTW-richtlijn** — BTW-behandeling per dienst
- **VNG Modelverordening leges** — landelijke template
- **GEMMA productcatalogus** — gestandaardiseerde productcodes
- **NEN 7510 + AVG** — beveiliging privacy inkomensdata

status: proposed

# Leverancier Zaakportaal

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Portalen › Leverancier

**Rationale:** Leveranciers-portaal.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Gemeenten en andere overheden hebben honderden tot duizenden leveranciers waarmee zij dagelijks zaken doen: aannemers, ICT-leveranciers, schoonmaakbedrijven, advocaten, accountants, zorgaanbieders, jeugdhulpaanbieders. Elk van deze leveranciers heeft regelmatig vragen ("waar staat mijn factuur?", "wat is de status van mijn aanbestedingsinschrijving?", "heeft de gemeente mijn contractverlenging al getekend?"), maar moet voor elk antwoord bellen, mailen of een formulier invullen. De gemeente verwerkt deze vragen handmatig — duur, traag en frustrerend voor beide partijen.

Parallel aan het bestaande zaakportaal voor burgers biedt `leverancier-zaakportaal` een gespecialiseerde portaalomgeving waar leveranciers zelf realtime inzicht hebben in hun zaken bij de gemeente: aanbestedingen waarop ze hebben ingeschreven (incl. status, gunningsdatum, beoordelingsmotivatie), lopende contracten (incl. einddatum, verlengingsoptie, contactpersoon), facturen (incl. ontvangstdatum, betaalstatus, verwachte betaaldatum op basis van mandaat-routing), klachten/issues, en KPI's rondom betaaltermijn. Authenticatie verloopt via **eHerkenning niveau 2+ of 3** (rechtspersoon-authenticatie); meerdere medewerkers per leverancier kunnen toegang krijgen met rolgebaseerde rechten (admin, financieel, contracten, sales).

Voor de gemeente betekent dit: 40-60% minder inkomende KCC-calls van leveranciers (proven case in studies bij gemeenten Eindhoven en Den Haag), automatische naleving van transparantie-eisen rondom aanbestedingen (Aanbestedingswet 2012 art. 2.130), en hogere leveranciers-tevredenheid (gemeten via NPS). Voor de leverancier: 24/7 inzicht zonder telefonisch wachten, voorspelbaarheid in cashflow (betaaltermijn-tracking), en zelfdienst voor administratieve handelingen (adreswijziging, bankrekening-wijziging, contactpersoon-wijziging).

## Data Model

**Supplier**: `id`, `kvkNumber`, `legalName`, `tradeName`, `addressRef`, `primaryContactRef`, `iban`, `vatNumber`, `sbiCodes[]`, `accreditations[]` (e.g. PSO, MVO-Prestatieladder, ISO-9001), `status` (active/inactive/blacklisted), `onboardedAt`, `lastVerifiedAt`.

**SupplierUser**: `id`, `supplierRef`, `userRef`, `role` (admin/finance/contracts/sales/read_only), `eherkenningLevel`, `addedBy`, `addedAt`, `lastLoginAt`, `mfaEnabled`.

**SupplierTender** (lookup view op zaak): `caseRef`, `supplierRef`, `tenderId`, `title`, `submittedAt`, `status` (draft/submitted/under_evaluation/awarded/rejected/withdrawn), `awardDate`, `rejectionReason`, `appealDeadline`, `contractValue`.

**SupplierContract** (lookup view op zaak): `caseRef`, `supplierRef`, `contractNumber`, `subject`, `startDate`, `endDate`, `renewalOption`, `noticePeriodDays`, `contractValue`, `accountManagerRef`, `documentRef`.

**SupplierInvoice** (lookup view op zaak): `caseRef`, `supplierRef`, `invoiceNumber`, `invoiceDate`, `dueDate`, `amount`, `vatAmount`, `status` (received/under_review/approved/paid/disputed/rejected), `expectedPaymentDate`, `actualPaymentDate`, `disputeReason`.

**SupplierMessage**: `id`, `supplierRef`, `caseRef`, `direction` (inbound/outbound), `subject`, `body`, `attachmentRefs[]`, `sentBy`, `sentAt`, `readAt`.

**SupplierKPI**: `supplierRef`, `metric` (avg_payment_days/on_time_payment_pct/dispute_rate/contract_compliance_score), `value`, `period`, `calculatedAt`.

## Requirements

### REQ-001: eHerkenning Login voor Rechtspersonen

GIVEN een leverancier bezoekt `leveranciers.gemeente.nl`
WHEN hij/zij op "Inloggen met eHerkenning" klikt
THEN routeert het systeem naar de eHerkenning-broker met niveau 2+ minimaal
AND valideert het de KvK-claim tegen het Supplier-register
AND creëert/koppelt het automatisch een SupplierUser-record met de rol uit de eHerkenning-machtiging (KvK-bevoegd-functionaris)
AND start het een sessie met 2-uurs TTL en re-authentication-vereiste voor financiële mutaties

### REQ-002: Multi-Account per Leverancier met Rolgebaseerde Rechten

GIVEN een leverancier wil dat zowel zijn financieel medewerker als accountmanager toegang krijgen
WHEN de supplier-admin een collega uitnodigt via emailadres + rol
THEN verstuurt het systeem een uitnodigingsmail met activatielink (KvK-koppeling via eHerkenning vereist)
AND koppelt het bij activatie een SupplierUser-record met de toegekende rol
AND tonen verschillende rollen verschillende dashboard-tabs (finance ziet facturen, sales ziet aanbestedingen, contracts ziet contracten)
AND mag de admin op elk moment rollen wijzigen of toegang intrekken

### REQ-003: Real-Time Aanbestedingsstatus

GIVEN een leverancier heeft ingeschreven op aanbesteding "Reconstructie Hoofdstraat"
WHEN hij/zij de aanbestedings-tab opent
THEN toont het systeem de actuele status (ingediend / in beoordeling / gegund / afgewezen)
AND bij gunning: gunningsdatum, contractwaarde, ingangsdatum
AND bij afwijzing: motivatie conform Aanbestedingswet 2.130, mogelijkheden bezwaar, deadline (20 dagen)
AND een download-knop voor het beoordelingsverslag (geanonimiseerd)

### REQ-004: Factuurstatus met Verwachte Betaaldatum

GIVEN een leverancier heeft factuur 2026-0042 ingediend
WHEN hij/zij de facturen-tab opent
THEN toont het systeem de huidige status (ontvangen / in controle / goedgekeurd / betaald / betwist)
AND bij goedgekeurd: verwachte betaaldatum (berekend uit mandaat-routing + standaard 30 dagen)
AND bij betwist: reden van betwisting + benodigde actie van leverancier
AND een totaalbalk met openstaand bedrag, ouderdomsanalyse (0-30 / 30-60 / 60-90 / 90+ dagen)

### REQ-005: Contract-Overzicht met Verloopwaarschuwingen

GIVEN een leverancier heeft 4 lopende contracten
WHEN hij/zij de contracten-tab opent
THEN toont het systeem alle contracten met einddatum, contractwaarde, accountmanager
AND markeert het contracten die binnen 90 dagen aflopen met een waarschuwingsbadge
AND toont het verlengingsopties (auto-renewal / einddatum / opzegtermijn)
AND geeft het een directe knop "Verlenging aanvragen" die een zaak in Procest opent

### REQ-006: Berichten-uitwisseling per Zaak

GIVEN een leverancier heeft een vraag over de status van zaak XZ-2026-0123
WHEN hij/zij op "Bericht sturen" klikt binnen die zaak
THEN creëert het systeem een SupplierMessage-record gekoppeld aan de zaak
AND verschijnt het bericht in de inbox van de behandelend ambtenaar in Procest
AND notificeert het de leverancier per email bij een antwoord
AND bewaart het de volledige conversatie-historie binnen de zaak voor auditdoeleinden

### REQ-007: Zelfdienst voor Stamgegevens-Mutaties

GIVEN een leverancier verhuist of wijzigt bankrekening
WHEN hij/zij in "Mijn gegevens" een wijziging doorvoert
THEN start het systeem een Procest-zaak "Leverancier-mutatie" met de wijziging als payload
AND vereist het voor IBAN-wijziging een extra verificatie (re-auth + verificatie via bekend-bij-bank-protocol)
AND wordt de wijziging pas effectief na ambtelijke goedkeuring (4-ogen-principe bij IBAN)
AND notificeert het de leverancier wanneer de wijziging is verwerkt

### REQ-008: KPI-Dashboard voor Leverancier

GIVEN een leverancier heeft 18 maanden historie met de gemeente
WHEN hij/zij het KPI-dashboard opent
THEN toont het systeem: gemiddelde betaaltermijn (eigen vs. gemeentegemiddelde), on-time-payment percentage, aantal geschillen, contract-compliance-score
AND toont het een trendlijn over 12 maanden per metric
AND staat het toe om de data te exporteren naar CSV voor eigen analyse

## Standards

- **eHerkenning 2+/3** (Logius) voor rechtspersoon-authenticatie
- **KvK Handelsregister API** voor automatische validatie van rechtspersoon-data
- **Aanbestedingswet 2012** artikel 2.130 (motiveringsplicht), 2.127 (gunning), 4.15 (klachtafhandeling)
- **EU-Richtlijn 2014/24 (klassieke richtlijn)** voor Europese aanbestedingen
- **UBL 2.1 / Peppol BIS Billing 3.0** voor inkomende e-facturen
- **NLCIUS** (Nederlandse Core Invoice Usage Specification)
- **NL Design System** voor portaal-UX consistent met overheid-look-and-feel
- **WCAG 2.1 AA** voor toegankelijkheid (verplicht voor overheidsportalen)
- **AVG artikel 25, 32** voor privacy-by-design rond bedrijfsgevoelige data
- **Common Ground laag 5 (interactie)** als architectuurprincipe
- **TIBR / Wet Open Overheid** voor transparantie in besluitvorming over aanbestedingen

## Cross-app Dependencies

- **OpenRegister**: zaak-storage met supplier-scoped queries, contract/factuur/aanbesteding als zaak-subtypes
- **OpenConnector**: e-factuur Peppol-ingestion, eHerkenning-broker, KvK-API koppeling
- **Procest**: kern zaaksysteem dat alle zaken vasthoudt; portaal is leveranciers-view
- **Decidesk**: mandaatregister bepaalt verwachte betaaldatum-berekening (welke ambtenaar in welk tempo tekent)
- **Pipelinq**: workflow-triggers voor "leverancier-mutatie", "contract-verlenging-aanvraag"
- **Docudesk**: PDF-generatie van beoordelingsverslagen en gunningsbrieven
- **Shillinq**: in omgekeerde richting voor uitgaande facturen aan leverancier (boetes, terugvorderingen)
- **NLDesign**: portaal-UX

## Target Users

- **Aannemers / ICT-leveranciers / dienstverleners** met regelmatige zaken bij gemeentes
- **Zorgaanbieders / jeugdhulpaanbieders** met DBC's en raamovereenkomsten
- **Advocaten / accountants** met lopende dossiers en facturatie
- **MKB-leveranciers** met behoefte aan voorspelbare cashflow-info
- **Inkoopafdelingen van leveranciers** die meerdere overheidsklanten in één overzicht willen
- **Accountmanagers van leveranciers** voor relatie-management met gemeentelijke contactpersonen

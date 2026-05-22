---
status: proposed
app: procest
spec: kcc-werkplek-zaaksysteem-bridge
depends_on:
  - procest base (zaaktype + zaak + burger)
  - pipelinq kcc-werkplek
  - openconnector (DigiD authenticatie)
related:
  - hydra/openspec/specs/i18n-nl-en/spec.md
target_users:
  - KCC-medewerker (Klant Contact Centrum)
  - Telefonist / receptioniste
  - Specialist-behandelaar (back-office)
  - Klachtenfunctionaris
  - Burger / ondernemer
  - Manager Dienstverlening
---

# KCC-werkplek met realtime zaaksysteem-integratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Portalen › KCC-werkplek

**Rationale:** Bridge-view.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Nederlandse gemeenten ontvangen via hun Klant Contact Centrum (KCC) honderden tot duizenden inkomende contacten per dag: telefoon, email, webformulier, social media, en in toenemende mate chat. De KCC-medewerker — vaak gepositioneerd als generalist die zoveel mogelijk in één keer goed wil afhandelen ("first time fix") — heeft idealiter direct inzicht in: wie belt (autorisatie via BSN), welke lopende zaken die persoon heeft, welke historische contacten al hebben plaatsgevonden, welke standaard-acties beschikbaar zijn (status terugkoppelen, nieuwe zaak openen, klacht registreren, doorverbinden naar specialist), en hoe een belplan-routering verloopt zodat de juiste specialist wordt bereikt.

In de huidige praktijk is de KCC-werkplek vaak een separaat CRM- of telefonie-systeem (Mitel, Cisco UCCX, Genesys, of zelfs een eenvoudige softphone) dat niet of nauwelijks geïntegreerd is met het zaaksysteem. De KCC-medewerker moet bij elke beller handmatig in het zaaksysteem zoeken op naam of BSN, kan vaak alleen zaken vinden van zijn/haar eigen afdeling, weet niet welke contactmomenten er eerder zijn geweest, en moet voor elke actie (status doorgeven, nieuwe zaak openen) overschakelen naar een ander systeem. Dit leidt tot lange behandeltijden, slechte klantervaring (burger moet zijn verhaal opnieuw vertellen), verlies van first-time-fix-potentieel, en geen geconsolideerde rapportage over kanaal-overstijgende klantreis.

De `kcc-werkplek-zaaksysteem-bridge` capability brengt de KCC-werkplek van pipelinq direct in de procest-zaakcontext zodat: (1) bij elke inkomende telefoon, email, of webcontact automatisch een zaak-voorblad opent in de KCC-werkplek; (2) burger-authenticatie via DigiD (voor portaal-contacten) of identificatie-vragen (voor telefoon) realtime de juiste persoon koppelt; (3) bestaande zaken van de beller direct zichtbaar zijn met status en laatste actie; (4) quick-actions beschikbaar zijn om standaard-handelingen in seconden te voltooien; (5) belplan-routering datagedreven wordt op basis van zaaktype, expertise-vereisten, en realtime beschikbaarheid van specialisten; (6) elk contact gelogd wordt als contactmoment op de zaak, ongeacht of het tot een nieuwe actie leidde; en (7) escalaties naar back-office specialisten warm doorverbonden kunnen worden met context-overdracht.

## Data Model

**Contactmoment** — registratie van elk inkomend contact. Velden: `kanaal` (`telefoon`, `email`, `webformulier`, `chat`, `social_media`, `balie`), `richting` (`inkomend`, `uitgaand`), `startTijd`, `eindTijd`, `duurSeconden`, `bellerIdentificatie` (telefoonnummer / email / sociale-id), `geidentificeerdeBurgerId` (nullable, FK naar Burger), `identificatieMethode` (`digid`, `bsn_verificatie`, `identificatievragen`, `niet_geidentificeerd`), `kccMedewerkerId`, `gerelateerdeZaken` (array van zaak-ids), `nieuweZaakIds` (array van tijdens dit contact aangemaakte zaken), `aard` (`informatieverzoek`, `statusverzoek`, `klacht`, `melding`, `nieuwe_aanvraag`, `doorverbinding`), `samenvatting`, `volgensIntent`, `firstTimeFix` (boolean), `transferNaar` (rol of medewerker).

**Burger** — natuurlijk persoon (of ondernemer). Velden: `bsn` (versleuteld), `kvkNummer` (voor ondernemers), `naam`, `adres`, `telefoonnummers` (array), `emails` (array), `bekendeIdentificaties` (telefoonnummer → bsn mapping), `voorkeursKanaal`, `voorkeursTaal`.

**KccQuickAction** — geconfigureerde quick-action beschikbaar in werkplek. Velden: `naam` (bv. "Status zaak doorgeven"), `actieType` (`status_geven`, `nieuwe_zaak`, `klacht_registreren`, `doorverbinden`, `bel_terug_inplannen`, `email_sturen`, `kopie_document_sturen`), `vereisteContext` (bv. heeft_open_zaak, is_geidentificeerd), `targetZaaktype` (voor nieuwe-zaak-acties), `template` (voor email-acties), `permissies` (welke KCC-rol mag deze actie uitvoeren).

**Belplan** — routering-regels voor inkomende telefoon. Velden: `naam`, `triggerNummer` (gemeentelijk algemeen nummer, vakspecifiek nummer), `routeringStappen` (array van stappen: keuzemenu, identificatie, vaardigheid-matching, wachtrij-overflow), `openingstijden`, `terugvalActie` (voicemail / SLA-callback), `prioriteit`.

**SpecialistBeschikbaarheid** — realtime beschikbaarheid voor warm doorverbinden. Velden: `medewerkerId`, `expertises` (array van zaaktype-codes), `status` (`beschikbaar`, `in_gesprek`, `wrap_up`, `afwezig`, `niet_storen`), `huidigeWachtrijLengte`, `gemiddeldeBehandelduur`, `laatsteUpdate`.

**Doorverbinding** — registratie van warm doorverbinden met context. Velden: `contactmomentId`, `vanMedewerkerId`, `naarMedewerkerId` (of `naarWachtrij`), `doorverbindingsReden`, `contextOverdracht` (vrije tekst + zaak-refs), `geaccepteerd` (boolean), `acceptatieTijd`.

**KlantSentiment** — sentiment-tracking per contact. Velden: `contactmomentId`, `sentimentScore` (-1 tot 1), `sentimentLabel` (`positief`, `neutraal`, `negatief`, `boos`), `triggerWoorden`, `escalatieAanbevolen` (boolean).

## Requirements

### REQ-KCC-001: Automatisch zaak-voorblad bij inkomende telefoon

**GIVEN** een KCC-medewerker met de werkplek open en een geconfigureerd belplan dat inkomende oproepen koppelt aan de werkplek
**WHEN** een burger belt vanaf een telefoonnummer dat bekend is in het Burger-register (via `bekendeIdentificaties`)
**THEN** systeem opent binnen 2 seconden een zaak-voorblad in de werkplek met: NAW van de beller, alle open zaken (max 10, met status en laatste actiedatum), recente contactmomenten (laatste 5), openstaande facturen, en suggesties voor waarschijnlijk gespreksonderwerp op basis van recente zaken (bv. "Heeft 3 dagen geleden omgevingsvergunning ingediend — waarschijnlijk statusvraag").

### REQ-KCC-002: DigiD-authenticatie voor portaal/chat-contacten

**GIVEN** een burger die via het gemeentelijke portaal een chat opent of via een webformulier authenticeert
**WHEN** de DigiD-authenticatie succesvol is via openconnector
**THEN** systeem koppelt het BSN aan een Burger-record (creëert nieuw record als nog onbekend), tagt het Contactmoment met `identificatieMethode: digid`, geeft de KCC-medewerker volledig inzicht in zaken (in tegenstelling tot anonieme of zwak-geidentificeerde contacten waarvoor alleen openbare zaakdata beschikbaar is), en logt de DigiD-authenticatie voor AVG-doeleinden (welke persoonsgegevens zijn verwerkt op grond van DigiD-machtiging).

### REQ-KCC-003: Identificatie-vragen bij telefonisch contact zonder DigiD

**GIVEN** een telefonisch contact waarbij het telefoonnummer niet bekend is of waarbij hoge zekerheid van identificatie nodig is (bv. opvragen specifieke status van een omgevingsvergunning)
**WHEN** de KCC-medewerker de identificatie-flow start
**THEN** systeem toont een geleide vragenlijst met progressieve identificatievragen (naam + geboortedatum + adres → BSN bevestiging → eventueel out-of-wallet-vraag zoals "wat was uw laatste aanvraag"), berekent een identificatie-score, en koppelt de Burger pas aan het contactmoment wanneer de score boven een configureerbare drempel komt; lager dan drempel betekent alleen openbare/geanonimiseerde info delen.

### REQ-KCC-004: Quick-action "Status terugkoppelen" in één klik

**GIVEN** een geïdentificeerde beller met één of meer open zaken, en de quick-action "Status terugkoppelen" geconfigureerd
**WHEN** de KCC-medewerker een zaak selecteert en op "Status terugkoppelen" klikt
**THEN** systeem toont een gegenereerde tekst met de actuele status (bv. "Uw aanvraag omgevingsvergunning Z2026-00547 is op 12 mei door de vergunningverlener ontvangen en wordt nu beoordeeld. Verwachte beschikkingsdatum: 15 juni 2026"), waarbij de KCC-medewerker de tekst kan voorlezen en bevestigt dat dit is gecommuniceerd; logt automatisch een uitgaand contactmoment op de zaak met de exacte gecommuniceerde status.

### REQ-KCC-005: Nieuwe zaak openen vanuit KCC-werkplek

**GIVEN** een telefonisch contact waarin de burger een nieuw verzoek indient (bv. "ik wil een melding doen over een kapotte lantaarnpaal") en quick-action "Nieuwe zaak" met `targetZaaktype: melding_openbare_ruimte`
**WHEN** de KCC-medewerker op de quick-action klikt
**THEN** systeem opent een minimaal intake-formulier voor het zaaktype, prefilt de burger-gegevens (NAW + telefoonnummer + email), laat de KCC-medewerker tijdens het gesprek de meldingsdetails invullen (locatie, soort defect, urgentie), creëert de zaak met initiatie-bron `kcc_telefoon`, koppelt het contactmoment aan de zaak, en geeft de melding-id direct terug zodat de KCC-medewerker dit aan de burger kan doorgeven.

### REQ-KCC-006: Klacht registreren als apart zaaktype

**GIVEN** een ontevreden beller en quick-action "Klacht registreren" met sentiment-flag uit KlantSentiment >= negatief
**WHEN** de KCC-medewerker de actie initieert
**THEN** systeem creëert een klacht-zaak van type "Klacht ex artikel 9:1 Awb" (interne klachtenprocedure), legt de klacht-tekst vast, koppelt aan eventueel onderliggende zaken waarover geklaagd wordt, routeert naar de klachtenfunctionaris met SLA volgens klachtenregeling (zes weken default, verlenging mogelijk), en triggert automatisch een ontvangstbevestiging-brief via docudesk.

### REQ-KCC-007: Warm doorverbinden met context-overdracht naar specialist

**GIVEN** een complexe vraag waarvoor een back-office specialist nodig is, een SpecialistBeschikbaarheid-overzicht, en een geïdentificeerde beller met een open zaak waarover de vraag gaat
**WHEN** de KCC-medewerker "Doorverbinden" kiest en een specialist of vakgebied selecteert
**THEN** systeem checkt realtime beschikbaarheid, plaatst de oproep in de wachtrij voor de specialist met een context-pop-up die laat zien: bellergegevens, gerelateerde zaak, samenvatting van het gesprek tot nu toe, sentiment-indicator, en quick-action history; de specialist kan de oproep accepteren of weigeren (met reden), en bij acceptatie wordt het contactmoment overgenomen met behoud van de hele context-trail.

### REQ-KCC-008: Datagedreven belplan-routering

**GIVEN** een Belplan voor het algemene gemeentenummer met routeringStappen ["keuzemenu", "vaardigheid-matching op zaaktype", "wachtrij-overflow naar generalist"]
**WHEN** een burger belt en in het keuzemenu "Omgevingsvergunningen" kiest
**THEN** systeem matched op vaardigheid "omgevingsvergunningen" in SpecialistBeschikbaarheid, plaatst de oproep in de wachtrij van beschikbare specialisten op die vaardigheid, en bij overflow (wachtrij > drempel of wachttijd > SLA) routeert naar een generalist met escalatie-flag zodat de generalist weet dat warm doorverbinden waarschijnlijk nodig zal zijn.

### REQ-KCC-009: Contactmoment-historie als geconsolideerde klantreis

**GIVEN** een burger die in een week tijd 3 keer heeft gebeld, 2 keer email heeft gestuurd, en 1 keer via portaal heeft ingelogd over dezelfde zaak
**WHEN** een KCC-medewerker of zaakbehandelaar de klantreis opent
**THEN** systeem toont een chronologisch overzicht van alle 6 contactmomenten kanaal-overstijgend, met per moment: kanaal, duur, medewerker, samenvatting, en gerelateerde zaken/acties; toont een aggregatie ("3 statusverzoeken — verbeterpunt: proactief status communiceren") en biedt een drill-down naar elk individueel contactmoment.

### REQ-KCC-010: Realtime sentiment-detectie en escalatie-aanbeveling

**GIVEN** een actief telefoongesprek met spraak-naar-tekst transcriptie (optioneel via pipelinq AI-koppeling) of een chat-gesprek
**WHEN** het systeem trigger-woorden detecteert ("ongelooflijk", "klacht", "wethouder", "advocaat", "media", langdurig negatieve toon)
**THEN** systeem updatet de KlantSentiment-score realtime, toont een onopvallende notification aan de KCC-medewerker ("Sentiment: negatief — overweeg escalatie of klacht registreren"), suggesteert relevante quick-actions (klacht registreren, warm doorverbinden naar manager), en logt het sentiment-verloop op het contactmoment voor rapportage en coaching.

## Standards

- **GEMMA KCC-architectuur** — referentie-architectuur voor klantcontactcentra binnen gemeenten
- **NORA-principes voor multikanaal-dienstverlening** — kanaal-overstijgende klantreis
- **Awb hoofdstuk 9 (Klachtbehandeling)** — wettelijk kader voor klachtafhandeling
- **AVG / NEN 7510** — verwerking van persoonsgegevens bij contactregistratie
- **DigiD koppelvlakspecificatie + eHerkenning** — authenticatie burger/ondernemer
- **PCI-DSS (indien betalingsverwerking via KCC)** — beveiliging bij telefonische betalingen
- **SIPS / SIP-trunking standaarden** — telefonie-integratie
- **ISO 18295 (Customer Contact Centres)** — kwaliteitsnorm KCC

## Cross-app

- **pipelinq kcc-werkplek** — de daadwerkelijke werkplek-UI met telefonie-integratie, chat, en email-inbox; deze spec voegt de zaaksysteem-context toe
- **procest base** — zaaktypes, zaken, en burgers vormen de context waarbinnen contactmomenten worden gelogd
- **openconnector** — DigiD/eHerkenning authenticatie, BRP-koppeling voor burger-identificatie, telefonie-koppeling (SIP-trunks, callbacks)
- **docudesk** — automatisch genereren van bevestigingsbrieven (klacht-ontvangst, callback-bevestiging, status-rapport per post als burger dat verkiest)
- **shillinq** — telefonische betalingen aan de balie of via KCC (bv. leges direct telefonisch afrekenen)
- **mydash** — KCC-dashboards (gemiddelde behandeltijd, first-time-fix percentage, top-10 contactredenen, sentiment-trend, callback-SLA)
- **leges-heffingen** — KCC-medewerker kan tijdens contact een leges-betaalverzoek versturen of een restitutie initiëren
- **mandaat-matrix** — bepaalt welke acties (bv. klacht-gegrond verklaren, restitutie toekennen) een KCC-medewerker mag uitvoeren of moet doorzetten

## Target users

KCC-medewerkers zijn de primaire gebruikers: zij bedienen de werkplek dagelijks en willen first-time-fix maximaliseren met minimale switching tussen systemen. Telefonisten/receptionisten van kleinere gemeenten combineren KCC-rol met balie-functie en willen één werkplek voor alle kanalen. Specialist-behandelaars in de back-office ontvangen warme doorverbindingen en willen context vooraf zien zodat het gesprek effectief is. Klachtenfunctionarissen ontvangen automatisch gerouteerde klachten en willen complete trail van het oorspronkelijke contact. Burgers en ondernemers ervaren de werkplek indirect — zij merken het aan minder herhaling, snellere afhandeling, en consistente informatie ongeacht kanaal. Managers Dienstverlening sturen op KPI's (NPS, first-time-fix, SLA-doorlooptijd) en willen kanaal-overstijgende rapportage met sentiment-trends.

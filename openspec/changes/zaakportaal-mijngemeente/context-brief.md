---
status: draft
---
# Citizen-facing zaakportaal "Mijn gemeente"

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Portalen › Mijngemeente

**Rationale:** Inwoner-portaal.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

De huidige procest-applicatie is een interne werkomgeving voor gemeentelijke behandelaren, mandaatverleners en beleidsmedewerkers. De burger of het bedrijf dat de tegenpartij is in een zaak heeft echter geen directe toegang tot het systeem; hij of zij is afhankelijk van losse Berichtenbox-berichten, telefonische updates of generieke gemeentelijke websites die niet zaak-specifiek zijn. Dit leidt tot herhaaldelijk telefoonverkeer ("waar staat mijn aanvraag?"), onnodige onzekerheid over termijnen, en gemiste kansen voor zelfbediening die zowel de gemeente als de burger tijd zou besparen.

Deze brief beschrijft "Mijn gemeente": een afzonderlijke UI-surface, gericht op burgers en bedrijven, die geauthenticeerd toegang biedt tot alle zaken die zij bij de gemeente lopend hebben. De authenticatie verloopt via de wettelijk verplichte stelsels DigiD (voor burgers) en eHerkenning (voor bedrijven), met optionele machtigingen-ondersteuning via DigiD Machtigen en eHerkenning Ketenmachtiging. Na inloggen krijgt de gebruiker een overzicht van al zijn zaken (opgehaald via BSN of KvK-nummer), kan hij de status en termijnen per zaak inzien op een grafische tijdlijn, downloadt hij alleen documenten waarvan hij zelf geadresseerde is, kan hij berichten sturen naar zijn behandelaar, en kan hij zelfstandig nieuwe verzoeken indienen zoals bezwaarschriften, klachten of subsidieaanvragen. Notificaties worden zowel per e-mail als via de officiële Berichtenbox geleverd.

Mijn gemeente is bewust gescheiden van de interne procest-UI: het draait op een eigen subdomein (bijvoorbeeld mijn.gemeente.nl), heeft een eigen designsysteem (NL Design System, conform overheidstoegankelijkheidseisen WCAG 2.2 AA), en exposeert alleen die zaakgegevens waarop de ingelogde persoon recht heeft te zien (privacy by design). Het portaal moet ook werken met DigiD-machtigingen (mantelzorger machtigt zorgontvanger, ouder machtigt minderjarig kind tot 16, professionele bewindvoerder) en eHerkenning-ketenmachtigingen (adviseur of accountant namens een bedrijf). De integratie met procest gebeurt via een dedicated API-laag die alleen leesbewerkingen en specifieke schrijfacties (bericht sturen, bezwaar indienen) toelaat, zodat interne zaakdata-integriteit beschermd blijft.

Een belangrijk ontwerpprincipe is dat het portaal géén "schaduwadministratie" wordt: alle gegevens komen rechtstreeks uit procest en openregister, niets wordt gedupliceerd in een aparte portaaldatabase. Dit voorkomt dat statussen of documenten in het portaal achterlopen op de werkelijkheid, en het minimaliseert de data-attack surface (een lek in het portaal levert niets op wat niet via authenticatie al toegankelijk is). Sessies worden conform Logius-richtlijnen kort gehouden (15 minuten inactiviteit) en alle sessie-tokens zijn gebonden aan IP + user-agent.

Een tweede ontwerpprincipe is "geen funnel": het portaal mag nooit een verkoopkanaal worden voor gemeentelijke producten. De UI is sober, taakgericht en presentatie-arm. Burgers komen om iets af te handelen of na te kijken, niet om geïnformeerd te worden over nieuwe gemeentediensten. Marketing-elementen, banners, cross-sells of "u kunt ook overwegen" suggesties zijn expliciet uitgesloten. Dit sluit aan op de overheidsbeginselen van gelijke behandeling en niet-discriminatie: het portaal mag geen voorkeuren creëren door persoonlijke targeting.

In scope voor deze eerste spec: zaakinzage, statusvolgen, documentdownload, berichten met behandelaar, bezwaar/klacht/subsidie indienen, notificatievoorkeuren. Buiten scope voor deze spec maar wel als roadmap-uitbreiding genoemd: betalingen via iDEAL (leges, dwangsommen), afspraak inplannen voor balie-bezoek, productenwizard voor "welke vergunning heb ik nodig", e-formulieren met conditionele logica voor complexere aanvragen, en interactie via WhatsApp/Signal-kanalen. Deze zijn architecturaal voorzien (via openconnector-adapters) maar niet inhoudelijk uitgewerkt in deze brief.

## Data Model

### PortaalGebruiker (sessie-state, niet persistente entiteit)

```json
{
  "sessieId": "sess-2026-77234abc",
  "ingelogdSinds": "2026-04-15T10:22:00+02:00",
  "authenticatieMethode": "digid",
  "betrouwbaarheidsniveau": "substantieel",
  "ingelogdAls": {
    "type": "burger",
    "bsn": "123456789",
    "naam": "M.A. Janssen-de Vries"
  },
  "machtiging": null,
  "sessieVerloopt": "2026-04-15T10:37:00+02:00"
}
```

### PortaalGebruiker met machtiging (DigiD Machtigen)

```json
{
  "sessieId": "sess-2026-88123def",
  "authenticatieMethode": "digid",
  "betrouwbaarheidsniveau": "substantieel",
  "ingelogdAls": {
    "type": "gemachtigde",
    "bsn": "555666777",
    "naam": "P. de Jong"
  },
  "machtiging": {
    "voorBsn": "123456789",
    "voorNaam": "M.A. Janssen-de Vries",
    "machtigingsType": "wettelijk-vertegenwoordiger",
    "geldig_tot": "2027-04-15"
  }
}
```

### PortaalGebruiker als bedrijf (eHerkenning)

```json
{
  "sessieId": "sess-2026-44908ghi",
  "authenticatieMethode": "eherkenning",
  "betrouwbaarheidsniveau": "substantieel-plus",
  "ingelogdAls": {
    "type": "bedrijf",
    "kvkNummer": "12345678",
    "vestigingsnummer": "000012345678",
    "naam": "Janssen & Partners B.V.",
    "namensPersoon": {
      "bsn": "234567890",
      "naam": "J. Janssen",
      "rolBijOnderneming": "bestuurder"
    }
  },
  "machtiging": null
}
```

### ZaakOverzichtItem (lijst-view)

```json
{
  "zaakId": "zaak-2026-vth-09128",
  "zaakKenmerk": "Z/2026/09128",
  "zaaktype": "omgevingsvergunning-aanvraag",
  "onderwerp": "Bouw uitbouw achterzijde woning Plataanlaan 14",
  "status": "vergunning-verleend",
  "ingediendOp": "2026-01-12",
  "actie": null,
  "termijnen": {
    "afhandelTermijnEinde": "2026-04-15",
    "termijnOverschreden": false,
    "dagenResterend": 0
  },
  "documentAantal": 6,
  "ongelezenBerichten": 0
}
```

### ZaakDetail (detail-view)

```json
{
  "zaakId": "zaak-2026-vth-09128",
  "zaakKenmerk": "Z/2026/09128",
  "zaaktype": {
    "code": "omgevingsvergunning-aanvraag",
    "naam": "Omgevingsvergunning aanvraag",
    "wetgevingsbasis": "Omgevingswet artikel 5.1"
  },
  "onderwerp": "Bouw uitbouw achterzijde woning Plataanlaan 14",
  "huidigeStatus": "vergunning-verleend",
  "tijdlijn": [
    {"datum": "2026-01-12", "status": "ingediend", "toelichting": "Aanvraag ontvangen", "actor": "burger"},
    {"datum": "2026-01-15", "status": "ontvankelijkheid-getoetst", "toelichting": "Aanvraag is volledig", "actor": "gemeente"},
    {"datum": "2026-02-08", "status": "inhoudelijk-getoetst", "toelichting": "Toets ruimtelijke kwaliteit positief", "actor": "gemeente"},
    {"datum": "2026-04-02", "status": "vergunning-verleend", "toelichting": "Besluit genomen", "actor": "gemeente"}
  ],
  "termijnen": {
    "afhandelTermijnWettelijk": "8 weken",
    "afhandelTermijnEinde": "2026-04-15",
    "beslistermijnEinde": "2026-04-15",
    "termijnOverschreden": false,
    "termijnOpgeschortGedurende": "0 dagen"
  },
  "behandelaar": {
    "naam": "K. Bakker",
    "afdeling": "VTH",
    "bereikbaar": "ma-do 9-17"
  },
  "documenten": [
    {"id": "doc-9128-01", "naam": "Aanvraagformulier.pdf", "soort": "aanvraag", "datum": "2026-01-12", "downloadbaarVoor": ["aanvrager"]},
    {"id": "doc-9128-02", "naam": "Bouwtekening.pdf", "soort": "bijlage", "datum": "2026-01-12", "downloadbaarVoor": ["aanvrager"]},
    {"id": "doc-9128-06", "naam": "Beschikking_Z2026_09128.pdf", "soort": "beschikking", "datum": "2026-04-02", "downloadbaarVoor": ["aanvrager"]}
  ],
  "berichten": [],
  "mogelijkeActies": ["bericht-sturen", "bezwaar-indienen", "klacht-indienen"]
}
```

### PortaalBericht

```json
{
  "id": "msg-2026-77123",
  "zaakId": "zaak-2026-vth-09128",
  "verzender": {
    "type": "burger",
    "bsn": "123456789",
    "naam": "M.A. Janssen-de Vries"
  },
  "ontvanger": {
    "type": "medewerker",
    "medewerkerId": "behandelaar-vth-44"
  },
  "onderwerp": "Vraag over voorwaarde 3 in beschikking",
  "inhoud": "Geachte mevrouw Bakker, kunt u toelichten wat exact wordt bedoeld met 'voorgeschreven dakhellingshoek'?",
  "bijlagen": [],
  "verzondenOp": "2026-04-10T14:33:00+02:00",
  "gelezenDoorOntvangerOp": null,
  "beantwoordOp": null
}
```

### PortaalVerzoek (bezwaar / klacht / subsidie)

```json
{
  "id": "verz-2026-09128-bezw-01",
  "soort": "bezwaarschrift",
  "tegenZaakId": "zaak-2026-vth-09128",
  "tegenBeschikkingId": "besch-2026-09128",
  "indiener": {
    "type": "burger",
    "bsn": "123456789",
    "naam": "M.A. Janssen-de Vries"
  },
  "onderwerp": "Bezwaar tegen omgevingsvergunning Z/2026/09128",
  "motivering": "Ik ben het niet eens met voorwaarde 3 omdat ...",
  "bijlagen": ["doc-9128-bezw-bijlage-01"],
  "ingediendOp": "2026-04-12T10:15:00+02:00",
  "binnenTermijn": true,
  "nieuweZaakId": "zaak-2026-bezw-04711",
  "ontvangstBevestigingVerstuurdOp": "2026-04-12T10:15:30+02:00"
}
```

### PortaalNotificatieVoorkeur

```json
{
  "id": "pref-bsn-123456789",
  "bsn": "123456789",
  "kanalen": {
    "email": {"actief": true, "adres": "marja@example.nl", "geverifieerd": true},
    "berichtenbox": {"actief": true},
    "sms": {"actief": false}
  },
  "gebeurtenissen": {
    "statuswijziging": true,
    "documentToegevoegd": true,
    "berichtVanBehandelaar": true,
    "termijnHerinnering": true
  }
}
```

## Requirements

### REQ-POR-001: Authenticatie via DigiD en eHerkenning

Het portaal MOET burgers laten inloggen via DigiD (betrouwbaarheidsniveau minimaal "substantieel" voor zaakinzage) en bedrijven via eHerkenning (minimaal niveau "substantieel"); andere authenticatiemechanismen MOGEN NIET worden toegestaan.

**GIVEN** een burger navigeert naar mijn.gemeente.nl **WHEN** hij op "inloggen" klikt **THEN** moet hij worden doorgestuurd naar DigiD met de gemeente als terugkeer-URL, en moet na succesvolle authenticatie betrouwbaarheidsniveau "substantieel" of hoger zijn vereist.

**GIVEN** een ondernemer wil inloggen namens zijn bedrijf **WHEN** hij eHerkenning kiest **THEN** moet hij via eHerkenning op niveau "substantieel" of hoger inloggen en moet zijn KvK-nummer plus zijn rol bij de onderneming worden vastgelegd in de sessie.

### REQ-POR-002: Zaakoverzicht op basis van BSN of KvK

Na inloggen MOET het portaal alle zaken tonen waarin de ingelogde persoon (of bedrijf) als initiator of geadresseerde betrokken is, opgehaald via BSN-match of KvK/OIN-match in procest.

**GIVEN** een burger met BSN `123456789` is ingelogd **WHEN** hij naar "mijn zaken" navigeert **THEN** moet het portaal alle zaken tonen waarin deze BSN voorkomt als aanvrager, geadresseerde of belanghebbende, met daarbij status, type, datum en eventuele acties.

**GIVEN** een bedrijf met KvK `12345678` is ingelogd via eHerkenning **WHEN** het zaakoverzicht wordt opgevraagd **THEN** moeten alle zaken worden getoond waarin dit KvK-nummer of bijbehorende OIN voorkomt.

### REQ-POR-003: DigiD-machtigingen en eHerkenning-ketenmachtigingen

Het portaal MOET DigiD Machtigen ondersteunen zodat een gemachtigde (mantelzorger, ouder, professionele bewindvoerder) namens een ander zaken kan inzien en acties kan uitvoeren; eveneens MOET eHerkenning Ketenmachtiging worden ondersteund voor adviseurs of accountants.

**GIVEN** een gemachtigde logt in via DigiD Machtigen voor BSN `123456789` **WHEN** het zaakoverzicht wordt opgevraagd **THEN** moeten de zaken van de gemachtigde (vertegenwoordigde) worden getoond, en moet de gemachtigde duidelijk in de UI worden vermeld als "ingelogd als [gemachtigde] namens [vertegenwoordigde]".

**GIVEN** de machtiging is beperkt tot bepaalde zaaktypen (bijvoorbeeld alleen "WMO") **WHEN** het zaakoverzicht wordt opgehaald **THEN** moeten alleen die zaaktypen zichtbaar zijn waarop de machtiging van toepassing is.

### REQ-POR-004: Documentdownload alleen voor geadresseerde

Het portaal MOET documenten alleen tonen en downloaden waarvan de ingelogde persoon (of zijn vertegenwoordigde) expliciet als ontvanger of belanghebbende is gemarkeerd in procest; interne documenten (adviezen, ambtelijke notities) MOGEN NIET zichtbaar zijn.

**GIVEN** zaak `zaak-2026-vth-09128` bevat 12 documenten waarvan 6 voor de aanvrager en 6 intern **WHEN** de aanvrager de detailpagina opent **THEN** moeten alleen de 6 voor de aanvrager bestemde documenten zichtbaar zijn; de 6 interne moeten volledig worden weggelaten (niet eens als titel of teaser).

**GIVEN** een gemachtigde probeert een document te downloaden waarop hij geen recht heeft **WHEN** de download-actie wordt geprobeerd **THEN** moet het systeem een 403-respons geven en moet de poging worden gelogd.

### REQ-POR-005: Status-tijdlijn met termijnvisualisatie

Voor iedere zaak MOET een visuele tijdlijn worden getoond met afgeronde stappen, de huidige status en geplande/wettelijke termijnen, inclusief duidelijke visuele indicator wanneer een termijn dreigt te worden overschreden.

**GIVEN** een omgevingsvergunning-zaak heeft afhandeltermijn 8 weken en is na 6 weken nog in behandeling **WHEN** de burger de zaak opent **THEN** moet de tijdlijn de afgeronde stappen tonen met datum, de huidige status visueel highlighten, en de resterende termijn (2 weken) met progress-indicator tonen.

**GIVEN** een zaak heeft de afhandeltermijn overschreden **WHEN** de tijdlijn wordt getoond **THEN** moet dit visueel duidelijk worden aangegeven (rode indicator + tekst "termijn overschreden, neem contact op met behandelaar") en moet een actieknop "vraag om uitleg" zichtbaar zijn.

### REQ-POR-006: Bericht aan behandelaar

De burger MOET via het portaal direct een bericht kunnen sturen aan de behandelaar van een specifieke zaak, met optionele bijlagen; berichten MOETEN worden gekoppeld aan de zaak in procest en worden gelogd.

**GIVEN** een burger heeft een vraag over voorwaarde 3 in zijn beschikking **WHEN** hij in het portaal "bericht sturen" kiest en zijn vraag verstuurt **THEN** moet het bericht worden opgeslagen als `PortaalBericht` gekoppeld aan de zaak, moet de behandelaar in procest een notificatie ontvangen, en moet de burger een ontvangstbevestiging zien.

**GIVEN** de behandelaar reageert via procest **WHEN** het antwoord wordt verzonden **THEN** moet de burger een notificatie ontvangen via zijn voorkeurkanaal (e-mail of Berichtenbox) en moet het antwoord zichtbaar zijn in de zaak-detailpagina.

### REQ-POR-007: Bezwaar indienen via portaal

Bij iedere beschikking waarvoor de bezwaartermijn nog niet is verstreken MOET de burger via het portaal een bezwaarschrift kunnen indienen; het systeem MOET de termijngeldigheid controleren en automatisch een nieuwe bezwaarzaak aanmaken in procest.

**GIVEN** een beschikking heeft `bezwaarTermijnEindDatum=2026-05-14` en huidige datum is 2026-04-12 **WHEN** de burger "bezwaar indienen" kiest **THEN** moet het portaal een formulier tonen met motiveringsveld en upload-mogelijkheid, en moet bij indiening een nieuwe `PortaalVerzoek` (soort=bezwaarschrift) worden gemaakt, gelinkt aan de oorspronkelijke beschikking, en moet een nieuwe bezwaarzaak in procest worden aangemaakt.

**GIVEN** een burger probeert bezwaar in te dienen na het verstrijken van de termijn **WHEN** hij "bezwaar indienen" probeert **THEN** moet het portaal de optie deactiveren of een waarschuwing tonen ("termijn verstreken — neem contact op voor uitzondering") en moet de actie niet automatisch worden verwerkt.

### REQ-POR-008: Klacht en subsidie-aanvraag

Het portaal MOET een centraal indieningskanaal bieden voor klachten (over de bejegening of dienstverlening) en subsidie-aanvragen, met passende intake-formulieren die de zaak in procest plaatsen onder het juiste zaaktype.

**GIVEN** een burger heeft een klacht over een gemeentelijke dienstverlening **WHEN** hij in het portaal "klacht indienen" kiest **THEN** moet een intake-formulier verschijnen dat zijn klacht categoriseert (bejegening, doorlooptijd, anders), zijn motivering registreert, en bij indiening een klacht-zaaktype aanmaakt in procest met automatische ontvangstbevestiging.

**GIVEN** een burger wil een subsidie aanvragen voor "monumentale woning gevelrenovatie" **WHEN** hij in het portaal naar "subsidie aanvragen" gaat **THEN** moet een lijst van beschikbare subsidieregelingen worden getoond (uit opencatalogi) en moet bij keuze het bijbehorende aanvraagformulier verschijnen met regelingspecifieke velden.

### REQ-POR-009: Notificatievoorkeuren per gebruiker

De gebruiker MOET zelf zijn notificatievoorkeuren kunnen beheren (kanalen: e-mail, Berichtenbox, SMS; gebeurtenissen: statuswijziging, documenttoegevoegd, bericht); de Berichtenbox-kanaal MOET altijd actief blijven voor wettelijk verplichte bekendmakingen.

**GIVEN** een burger wil geen e-mailnotificaties meer ontvangen **WHEN** hij in zijn instellingen "e-mail uit" zet **THEN** moeten toekomstige niet-wettelijke notificaties (statuswijziging, bericht) niet meer per e-mail worden verstuurd, maar moeten wettelijk verplichte bekendmakingen (beschikking, dwangsom) wel via Berichtenbox blijven gaan.

**GIVEN** een gebruiker registreert een nieuw e-mailadres **WHEN** hij dit opslaat **THEN** moet het systeem een verificatiemail sturen en moet het adres pas worden gebruikt voor notificaties nadat de verificatielink is geklikt.

### REQ-POR-010: Toegankelijkheid en NL Design System

Het portaal MOET voldoen aan WCAG 2.2 AA en gebruikmaken van het NL Design System voor consistente overheidsbeleving; alle interactieve elementen MOETEN toetsenbordbedienbaar en screenreader-vriendelijk zijn.

**GIVEN** een gebruiker met een screenreader navigeert door het portaal **WHEN** hij het zaakoverzicht doorloopt **THEN** moeten alle zaken correct worden voorgelezen met status en termijn, en moet de tijdlijn-visualisatie een tekstuele alternatieve representatie bieden.

**GIVEN** een gebruiker bedient het portaal alleen met toetsenbord **WHEN** hij door de pagina tabt **THEN** moet alle functionaliteit (zaakdetail openen, bericht sturen, bezwaar indienen) bereikbaar zijn zonder muis, met duidelijke focus-indicatoren conform NLDS.

## Standards & Sources

- **Wet digitale overheid (Wdo)** — verplichte authenticatie via DigiD/eHerkenning op minimaal niveau "substantieel"
- **eIDAS-verordening (EU) 910/2014** — betrouwbaarheidsniveaus authenticatie
- **DigiD Machtigen** — Logius-koppelvlak voor wettelijke vertegenwoordigers en bewindvoerders
- **eHerkenning Ketenmachtiging** — machtigingsstructuur voor adviseurs/accountants
- **Algemene wet bestuursrecht (Awb)** — artikel 6:4 (bezwaarschrift indienen), 9:1 e.v. (klachtrecht), 4:5 (aanvraag-eisen)
- **WCAG 2.2 AA** — Web Content Accessibility Guidelines
- **Tijdelijk besluit digitale toegankelijkheid overheid** — wettelijke verplichting voor overheidswebsites
- **NL Design System (NLDS)** — Conduction Design System ondersteund via nldesign-app, gebaseerd op NLDS
- **Forum Standaardisatie "pas toe of leg uit"-lijst** — SAML 2.0, OpenID Connect, HTTPS, PDF/A
- **MijnOverheid Berichtenbox** — Logius API voor wettelijke kennisgevingen
- **GEMMA Klantbeeld** — VNG referentie-architectuur voor "Mijn Gemeente"-portalen
- **Conduction openconnector** — DigiD/eHerkenning OAuth/SAML-bridges
- **Tijdelijk besluit Wpg** — bewaartermijnen voor digitale dienstverleningssporen
- **AVG artikel 25** — privacy by design en privacy by default
- **AVG artikel 32** — beveiliging van de verwerking (encryptie, audit-trail)
- **NEN-EN 301 549** — Europese standaard voor digitale toegankelijkheid (verwijzing vanuit WCAG)
- **Forum Standaardisatie API-strategie** — REST-API's voor portaal-procest-koppeling
- **Burgerservicenummer wet** — gebruik en bescherming van BSN
- **Handelsregisterwet 2007** — gebruik en bescherming van KvK-/OIN-gegevens
- **OWASP ASVS 4.0 Level 2** — minimaal beveiligingsniveau voor authenticated overheidsapplicaties
- **OWASP Top 10 (2021)** — algemene web-security baseline
- **MijnOverheid Beeldhuisstijl** — afstemming wanneer portaal verwijst naar Berichtenbox/MijnOverheid

## Cross-app integration

- **procest (host)** — leverancier van zaakdata, statusinformatie, behandelaars; ontvanger van portaalberichten, bezwaarschriften, klachten en subsidie-aanvragen.
- **openconnector** — DigiD SAML/OIDC-koppeling, eHerkenning SAML-koppeling, DigiD Machtigen-koppeling, eHerkenning Ketenmachtiging-koppeling, Berichtenbox MijnOverheid-koppeling, BRP-validatie van BSN.
- **openregister** — bronwaarheid van documenten en hun toegangsrechten, audit-logging van portaal-acties (lees, download, indiening).
- **opencatalogi** — catalogus van beschikbare subsidieregelingen, dienstencatalogus per zaaktype.
- **docudesk** — ontvangstbevestigingen, bevestigingsbrieven, intake-formulieren voor klachten/bezwaren.
- **nldesign-app** — NL Design System componenten en huisstijl van de gemeente.
- **mydash** — dashboard voor portaalgebruik (aantal logins, ingediende verzoeken, doorlooptijd berichten) voor de gemeentelijke dienstverleningsorganisatie.

## Target users

**Primair:**
- **Burger** — natuurlijke persoon die als aanvrager, geadresseerde of belanghebbende in een gemeentelijke zaak betrokken is; logt in met DigiD.
- **Ondernemer / vertegenwoordiger van bedrijf** — natuurlijke persoon die namens een bedrijf zaken bij de gemeente regelt; logt in met eHerkenning.
- **Wettelijke vertegenwoordiger** — ouder van minderjarig kind, curator, bewindvoerder, mantelzorger met machtiging; logt in via DigiD Machtigen.
- **Professionele adviseur** — accountant, advocaat, milieuconsultant; logt in via eHerkenning Ketenmachtiging.

**Secundair:**
- **Behandelaar in procest** — ontvangt portaalberichten en bezwaren, beantwoordt vragen.
- **Klachtencoördinator** — verwerkt klachten ingediend via het portaal.
- **Subsidieadviseur** — beoordeelt subsidie-aanvragen die binnenkomen via het portaal.
- **Klantcontactcentrum (KCC)** — gebruikt zelfde portaal-API om burgers telefonisch te helpen met statusvragen.

**Stakeholders:**
- **Functionaris gegevensbescherming** — controleert AVG-conformiteit van portaalacties (data-minimalisatie, expliciete toestemming).
- **Toegankelijkheidsadviseur** — controleert WCAG 2.2 AA-conformiteit en NLDS-toepassing.
- **Logius (DigiD/eHerkenning/MijnOverheid leverancier)** — stelt koppelvlakken beschikbaar via openconnector.
- **CISO / informatiebeveiligingsfunctionaris** — bewaakt sessie-management, encryptie, audit-trail en datalek-detectie.
- **Communicatieafdeling gemeente** — beheert tekst en tone-of-voice van portaalcomponenten (notificaties, intake-formulieren, ontvangstbevestigingen).
- **Dienstverleningsmanager / hoofd burgerzaken** — gebruikt mydash-dashboards om dienstverlening te sturen op portaalgebruik en doorlooptijden.
- **Onafhankelijke beoordelaar toegankelijkheid** — voert WCAG 2.2 AA audits uit en publiceert toegankelijkheidsverklaringen op toegankelijkheidsregister.nl.
- **Logius-toezicht (Dienst Toetsing Aansluitvoorwaarden)** — controleert of de DigiD-aansluiting voldoet aan alle technische en organisatorische eisen.
- **Belastingdienst / Toeslagen** — bij subsidieregelingen die raken aan inkomenstoetsing (cross-domein, via openconnector).

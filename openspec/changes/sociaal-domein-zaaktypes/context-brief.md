---
status: draft
---
# Sociaal domein zaaktype-family (WMO + Jeugdwet + Participatiewet)

## Purpose

Met de decentralisaties van 2015 zijn gemeenten in Nederland verantwoordelijk geworden voor het volledige sociaal domein, een takenpakket dat voorheen verdeeld was over Rijk, provincies en zorgkantoren. Het sociaal domein vormt een eigen zaakuniversum binnen de gemeentelijke uitvoering, met fundamenteel andere privacy-eisen, doorlooptijden en samenwerkingspatronen dan het VTH-domein dat al door procest wordt afgedekt. Waar VTH-zaken (Omgevingsvergunning, Toezicht, Handhaving) overwegend openbare belangen en publiekrechtelijke handhaving betreffen, draaien WMO- (Wet maatschappelijke ondersteuning), Jeugdwet- en Participatiewet-zaken om individuele burgers in kwetsbare situaties. De gegevens die hierbij worden verwerkt vallen onder de categorie "bijzondere persoonsgegevens" zoals gedefinieerd in artikel 9 AVG: medische gegevens, gezinssituatie, financiële omstandigheden, justitiële antecedenten, en in toenemende mate ook etniciteit en religieuze overtuiging waar dit relevant is voor passende ondersteuning.

Deze brief introduceert een complete zaaktype-familie voor het sociaal domein, met daarin minimaal: WMO-onderzoek + indicatiestelling + beschikking voor hulpmiddelen, huishoudelijke hulp, dagbesteding en begeleiding; Jeugdwet-zaken met gezinsplan, ondersteuning, en verlengingstrajecten; en Participatiewet-zaken voor bijstandsuitkering, loonkostensubsidie en re-integratietrajecten. Bovenop de bestaande zaaktype-primitives uit procest worden domein-specifieke uitbreidingen gedefinieerd: een verzwaard toegangsmodel waarin alleen de toegewezen behandelaar plus diens directe collega's binnen het wijkteam de inhoud mogen inzien (en niet bijvoorbeeld de hele afdeling), automatische anonimisering bij gegevensdeling met externe partijen, en ondersteuning voor multi-disciplinair overleg (MDO) waarin meerdere professionals uit verschillende domeinen (sociaal werker, jeugdarts, schuldhulpverlener) gezamenlijk aan één casus werken.

Het doel is dat een gemeente met deze spec haar volledige sociaal-domein-uitvoering kan onderbrengen in procest zonder dat er een aparte applicatie nodig is naast het zaaksysteem, en zonder dat de strenge privacy-eisen worden ondergegraven door het generieke zaaktype-model. Door hergebruik van openregister's `pii-detection-masking` (waar bijzondere persoonsgegevens automatisch worden gedetecteerd en gemaskeerd bij export of integratie) ontstaat een consistent privacy-by-design model. De integratie met openconnector zorgt dat externe partijen (zorgaanbieders, CJG, GGD) gestructureerd kunnen worden geïnformeerd zonder bulk-export van dossiers.

Naast de drie hoofdwetten worden raakvlakken meegenomen die in de dagelijkse uitvoering onlosmakelijk verbonden zijn met sociaal-domein-zaken: schuldhulpverlening (Wgs), inburgering (Wi2021), leerplicht/RMC, en doelgroepenvervoer. Deze zijn niet als eigen zaaktype-hoofdcategorieën opgenomen maar als specialisaties van bestaande WMO/Participatiewet/Jeugdwet-zaaktypes, om consistentie en uitlegbaarheid te behouden. Voor regiogemeenten (centrumgemeente-rol bij beschermd wonen, maatschappelijke opvang) is ondersteuning voor regio-gedeelde dossiers expliciet opgenomen, met heldere afspraken over verantwoordelijkheid voor classificatie, bewaartermijnen en datalek-meldplicht.

Een belangrijke ontwerpkeuze in deze spec is dat het sociaal domein zoveel mogelijk hetzelfde technische framework gebruikt als de andere zaaktypes in procest (statusengine, taken, documenten, audit), maar dat aanvullende AVG- en toegangscontroles afdwingbaar worden gemaakt door middel van een verplicht classificatieblok en hardgecodeerde guards in de queries. Daardoor kunnen bestaande procest-functies (notificaties, rapportages, mydash) zonder fork worden hergebruikt, terwijl niet-bevoegde medewerkers nooit per ongeluk inhoudelijke data te zien krijgen.

## Data Model

De zaaktype-familie introduceert drie hoofdschema's (WmoZaak, JeugdwetZaak, ParticipatiewetZaak) plus ondersteunende entiteiten (Indicatiestelling, Gezinsplan, ReIntegratieTraject, MdoOverleg, AvgClassificatie). Alle entiteiten erven van de bestaande Zaak-basis maar voegen domein-specifieke velden en een verplicht `avgClassificatie`-blok toe.

### AvgClassificatie (waardetype, niet eigen entiteit)

```json
{
  "categorieen": ["medisch", "financieel"],
  "bijzonderePersoonsgegevens": true,
  "rechtvaardiging": "artikel-9-2-h-avg",
  "rechtvaardigingToelichting": "Verwerking noodzakelijk voor medische beoordeling indicatiestelling WMO conform artikel 2.3.5 Wmo 2015.",
  "bewaarTermijnJaren": 15,
  "vernietigingDatum": "2041-03-15",
  "toegangsBeperking": "alleen-behandelaar-en-wijkteam",
  "anonimiseringBijDelen": true,
  "exportBeperking": "geen-bulk-export"
}
```

### WmoZaak

```json
{
  "id": "zaak-2026-wmo-04832",
  "zaaktype": "wmo-melding",
  "bsn": "123456789",
  "naam": "Janssen-de Vries, M.A.",
  "aanvraagSoort": "huishoudelijke-hulp",
  "aanvraagDatum": "2026-03-12",
  "meldingKanaal": "telefonisch",
  "ondersteuningsvraag": "Cliënt kan na heupoperatie tijdelijk geen huishoudelijke taken meer uitvoeren. Vraagt 4 uur per week ondersteuning.",
  "wijkteam": "wijkteam-zuid",
  "behandelaarId": "medewerker-892",
  "tweedeBehandelaarId": "medewerker-104",
  "status": "onderzoek-loopt",
  "avgClassificatie": {
    "categorieen": ["medisch"],
    "bijzonderePersoonsgegevens": true,
    "rechtvaardiging": "artikel-9-2-h-avg",
    "bewaarTermijnJaren": 15,
    "toegangsBeperking": "alleen-behandelaar-en-wijkteam"
  },
  "doorlooptijdWettelijk": {
    "onderzoekTermijnWeken": 6,
    "beschikkingTermijnWeken": 2,
    "totaalWettelijkWeken": 8
  },
  "indicatiestellingId": "ind-2026-04832",
  "huishoudensSamenstelling": {
    "type": "alleenstaand",
    "leeftijdsgroep": "75-plus",
    "mantelzorgAanwezig": false
  }
}
```

### Indicatiestelling

```json
{
  "id": "ind-2026-04832",
  "zaakId": "zaak-2026-wmo-04832",
  "indicatieSteller": "wmo-consulent-892",
  "datumOnderzoek": "2026-03-28",
  "vorm": "huisbezoek",
  "onderzoekVerslag": "verslag-bestand-id-7733",
  "geadviseerdeOndersteuning": {
    "soort": "huishoudelijke-hulp",
    "omvangPerWeek": 4,
    "eenheid": "uur",
    "duurMaanden": 12,
    "leverancierKeuzeBurger": true
  },
  "beschikkingId": "besch-2026-04832",
  "evaluatieDatum": "2027-03-28"
}
```

### JeugdwetZaak

```json
{
  "id": "zaak-2026-jeugd-00921",
  "zaaktype": "jeugdwet-melding",
  "gezinId": "gezin-04472",
  "jeugdigeBsn": "987654321",
  "jeugdigeLeeftijd": 9,
  "verzoekKanaal": "huisarts-verwijzing",
  "verzoekDatum": "2026-02-18",
  "verwijzer": {
    "type": "huisarts",
    "agbCode": "01-029384",
    "naam": "Praktijk Bos & Co"
  },
  "ondersteuningsvraag": "Jeugdige vertoont gedragsproblemen op school en thuis na echtscheiding ouders. Gezin vraagt om gespecialiseerde jeugdhulp.",
  "wijkteam": "jeugdteam-noord",
  "behandelaarId": "jeugdconsulent-203",
  "status": "gezinsplan-opstellen",
  "avgClassificatie": {
    "categorieen": ["medisch", "gezinssituatie"],
    "bijzonderePersoonsgegevens": true,
    "rechtvaardiging": "artikel-9-2-h-avg",
    "bewaarTermijnJaren": 20,
    "toegangsBeperking": "alleen-jeugdteam"
  },
  "gezinsplanId": "plan-2026-00921",
  "mdoOverlegIds": ["mdo-2026-00440"],
  "ondertoezichtstellingActief": false,
  "verlengingHistorie": []
}
```

### Gezinsplan

```json
{
  "id": "plan-2026-00921",
  "zaakId": "zaak-2026-jeugd-00921",
  "opgesteldDoor": "jeugdconsulent-203",
  "opgesteldDatum": "2026-03-04",
  "gezinsleden": [
    {"rol": "moeder", "bsn": "111222333", "akkoord": true, "akkoordDatum": "2026-03-06"},
    {"rol": "vader", "bsn": "444555666", "akkoord": true, "akkoordDatum": "2026-03-08"},
    {"rol": "jeugdige", "bsn": "987654321", "akkoord": false, "leeftijdToestemmingsvereiste": false}
  ],
  "doelen": [
    "Verbeteren communicatie tussen jeugdige en ouders",
    "Schoolprestaties stabiliseren binnen 6 maanden",
    "Sociale vaardigheden vergroten via groepstraining"
  ],
  "inzetTrajecten": [
    {"soort": "ambulante-jeugdhulp", "aanbieder": "Jeugdzorg West", "startDatum": "2026-04-01", "duurMaanden": 6}
  ],
  "evaluatieMomenten": ["2026-07-01", "2026-10-01"],
  "verlengingMogelijk": true
}
```

### ReIntegratieTraject

```json
{
  "id": "reint-2026-01278",
  "zaakId": "zaak-2026-pw-01278",
  "klantmanagerId": "klantmanager-477",
  "startDatum": "2026-04-01",
  "trajectSoort": "werkfit-maken",
  "afstandTotArbeidsmarkt": "groot",
  "instrumenten": [
    {"soort": "loonkostensubsidie", "percentageLoonwaarde": 60, "looptijdMaanden": 12},
    {"soort": "scholing", "opleiding": "Heftruckchauffeur SVH-1", "kostenBudget": 1850.00},
    {"soort": "begeleiding-op-de-werkplek", "jobcoach": "Werkstap B.V."}
  ],
  "samenwerkendePartijen": [
    {"partij": "UWV", "rol": "no-risk-polis"},
    {"partij": "Werkbedrijf Regio Zuid", "rol": "matching"}
  ],
  "evaluatieMomenten": ["2026-07-01", "2026-10-01", "2027-01-01"],
  "tegenprestatieVerplicht": false,
  "vrijstellingArbeidsverplichting": null
}
```

### ParticipatiewetZaak

```json
{
  "id": "zaak-2026-pw-01278",
  "zaaktype": "bijstandsaanvraag",
  "bsn": "234567890",
  "aanvraagSoort": "algemene-bijstand",
  "aanvraagDatum": "2026-03-01",
  "ingangsdatumGewenst": "2026-03-15",
  "leeftijdsgroep": "27-plus-tot-aow",
  "huishoudensSituatie": "alleenstaand-met-kinderen",
  "vermogensToets": {
    "uitgevoerd": true,
    "vermogen": 2400.00,
    "vermogensvrijstelling": 6505.00,
    "boven_vermogensvrijstelling": false
  },
  "inkomensToets": {
    "uitgevoerd": true,
    "inkomenPerMaand": 0.00,
    "bijstandsnormPerMaand": 1234.45,
    "rechtOpBijstand": true
  },
  "reIntegratieTrajectId": "reint-2026-01278",
  "behandelaarId": "klantmanager-477",
  "status": "beschikking-voorbereiding",
  "avgClassificatie": {
    "categorieen": ["financieel"],
    "bijzonderePersoonsgegevens": true,
    "rechtvaardiging": "artikel-9-2-b-avg",
    "bewaarTermijnJaren": 10,
    "toegangsBeperking": "alleen-werk-en-inkomen-team"
  }
}
```

### Toestemming (gegevensdeling)

```json
{
  "id": "toestem-2026-00921-01",
  "zaakId": "zaak-2026-jeugd-00921",
  "verleendDoorBsn": "111222333",
  "verleendDoorNaam": "moeder",
  "verleendDatum": "2026-03-05",
  "geldigTot": "2026-09-05",
  "intrekkingMogelijk": true,
  "scope": {
    "tePartijen": ["Jeugdzorg West", "Basisschool De Vlinder"],
    "tegegevens": ["gezinsplan-doelen", "evaluatie-momenten"],
    "tedoel": "Afstemming jeugdhulp en schoolsituatie",
    "ingetrokken": false
  },
  "vastgelegdViaKanaal": "huisbezoek-gespreksverslag",
  "bewijsBestandId": "verslag-bestand-id-7733"
}
```

### MdoOverleg

```json
{
  "id": "mdo-2026-00440",
  "zaakIds": ["zaak-2026-jeugd-00921"],
  "overlegDatum": "2026-04-22T10:00:00+02:00",
  "deelnemers": [
    {"rol": "jeugdconsulent", "medewerkerId": "jeugdconsulent-203", "organisatie": "gemeente"},
    {"rol": "jeugdarts", "naam": "M. Bakker", "organisatie": "GGD", "toestemmingDeelnameDoorClient": true},
    {"rol": "schoolmaatschappelijk-werker", "naam": "P. de Jong", "organisatie": "Basisschool De Vlinder", "toestemmingDeelnameDoorClient": true}
  ],
  "agenda": ["Status gezinsplan", "Schoolsituatie", "Vervolgafspraken"],
  "verslag": "verslag-mdo-440",
  "toestemmingenGeregistreerd": true,
  "gedeeldeGegevens": "alleen-anonimiseerde-samenvatting"
}
```

## Requirements

### REQ-SOC-001: Zaaktype-familie sociaal domein

Het systeem MOET drie hoofdzaaktypen ondersteunen (WmoZaak, JeugdwetZaak, ParticipatiewetZaak) elk met eigen levenscyclus, statusovergangen en wettelijke termijnen.

**GIVEN** een wmo-consulent maakt een nieuwe melding aan **WHEN** hij zaaktype "wmo-melding" kiest **THEN** moet de zaak automatisch de WMO-statusflow krijgen (melding → onderzoek → indicatiestelling → beschikking → uitvoering → evaluatie) en de wettelijke termijn van 8 weken (6 onderzoek + 2 beschikking) registreren.

**GIVEN** een jeugdconsulent maakt een jeugdwetzaak aan **WHEN** de zaak wordt opgeslagen **THEN** moet het systeem automatisch een leeg gezinsplan-object koppelen en de status "gezinsplan-opstellen" zetten.

**GIVEN** een klantmanager registreert een bijstandsaanvraag **WHEN** de aanvraag wordt opgeslagen **THEN** moet het systeem placeholders aanmaken voor de verplichte vermogens- en inkomenstoets en pas verder doorzetten naar "beschikking-voorbereiding" zodra beide zijn afgerond.

### REQ-SOC-002: Verplichte AVG-classificatie bij aanmaak

Iedere zaak in de sociaal-domein-familie MOET bij aanmaak een ingevuld `avgClassificatie`-blok bevatten; aanmaak zonder classificatie MOET worden afgewezen.

**GIVEN** een behandelaar slaat een nieuwe zaak op zonder avgClassificatie **WHEN** het systeem de save-actie verwerkt **THEN** moet de save falen met een validatiefout en moet de behandelaar verplicht worden de classificatie in te vullen.

**GIVEN** een zaak heeft `categorieen=[medisch]` **WHEN** de zaak wordt opgeslagen **THEN** moet `bijzonderePersoonsgegevens` automatisch op `true` worden gezet en moet de minimale bewaartermijn van 15 jaar (WMO) of 20 jaar (Jeugdwet) worden afgedwongen.

### REQ-SOC-003: Toegangsbeperking op zaakniveau

Alleen medewerkers in het toegewezen wijkteam of jeugdteam MOGEN de zaakinhoud zien; andere medewerkers MOGEN alleen niet-inhoudelijke metadata (zaaknummer, status, behandelaar) zien.

**GIVEN** zaak `zaak-2026-wmo-04832` heeft `wijkteam=wijkteam-zuid` **WHEN** een medewerker van wijkteam-noord de zaak opvraagt **THEN** moet het systeem alleen zaaknummer, status en datum tonen, en de inhoudelijke velden (ondersteuningsvraag, indicatiestelling, dossier) blokkeren met een 403-respons.

**GIVEN** een medewerker is gemarkeerd als "tweede behandelaar" op een zaak **WHEN** hij de zaak opent **THEN** moet hij volledige toegang krijgen, ook als hij niet in het primaire wijkteam zit.

**GIVEN** een functionaris gegevensbescherming heeft auditrechten **WHEN** zij een zaak opent met als doel AVG-controle **THEN** moet zij metadata + auditlog kunnen zien zonder volledige zaakinhoud, met expliciete vermelding "FG-audit-modus" in de header.

### REQ-SOC-004: Anonimisering bij gegevensdeling met derden

Bij export of integratie van zaakgegevens naar externe partijen (zorgaanbieder, CJG, GGD) MOETEN bijzondere persoonsgegevens automatisch worden geanonimiseerd of vervangen door pseudoniem-codes, tenzij expliciete toestemming van de cliënt is geregistreerd.

**GIVEN** een jeugdconsulent deelt zaakgegevens met een externe zorgaanbieder **WHEN** de export wordt gestart **THEN** moet `pii-detection-masking` uit openregister worden ingeschakeld die BSN, geboortedatum, medische details en gezinssamenstelling vervangt door pseudoniemen, tenzij de cliënt expliciet toestemming heeft gegeven (geregistreerd in een toestemmingsobject).

**GIVEN** een MDO-overleg legt gedeelde gegevens vast **WHEN** het systeem de gedeelde samenvatting genereert **THEN** moeten persoonsidentificerende elementen automatisch worden weggelaten conform de instelling `gedeeldeGegevens=alleen-anonimiseerde-samenvatting`.

### REQ-SOC-005: WMO-onderzoek en indicatiestelling

Het systeem MOET een onderzoek-stap ondersteunen waarin een huisbezoek, telefonisch onderzoek of dossieronderzoek wordt vastgelegd, met daaropvolgend een indicatiestelling die de geadviseerde ondersteuning bevat.

**GIVEN** de status van een WMO-zaak is "onderzoek-loopt" **WHEN** de consulent het onderzoeksverslag uploadt en de indicatiestelling invult **THEN** moet het systeem de status automatisch verplaatsen naar "beschikking-voorbereiding" en moet de wettelijke beschikkingstermijn van 2 weken starten.

**GIVEN** de indicatiestelling adviseert "huishoudelijke-hulp 4 uur per week voor 12 maanden" **WHEN** de beschikking wordt opgesteld **THEN** moeten deze waarden automatisch worden overgenomen in de beschikkingstekst (via de beschikking-generatie-pipeline).

### REQ-SOC-006: Jeugdwet-gezinsplan en verlengingen

Iedere Jeugdwet-zaak MOET een gezinsplan bevatten dat door alle handelingsbekwame gezinsleden is geaccordeerd; verlengingen MOETEN een aparte evaluatie en nieuwe akkoordregistratie vereisen.

**GIVEN** een gezinsplan is opgesteld voor 6 maanden en de evaluatiedatum nadert **WHEN** de consulent een verlenging aanmaakt **THEN** moet het systeem een nieuw verlengingsobject aanmaken, alle gezinsleden om akkoord vragen, en de oorspronkelijke plan-id koppelen via `verlengingHistorie`.

**GIVEN** een jeugdige is 16 jaar of ouder **WHEN** een gezinsplan wordt opgesteld **THEN** moet ook de jeugdige zelf akkoord geven (`leeftijdToestemmingsvereiste=true`), naast de gezaghebbende ouders.

### REQ-SOC-007: Participatiewet vermogens- en inkomenstoets

Een bijstandsaanvraag MAG NIET worden doorgezet naar beschikking zonder afgeronde vermogens- en inkomenstoets; bij vermogen boven de vrijstellingsgrens MOET de aanvraag automatisch worden gemarkeerd als "afwijzingsvoorstel".

**GIVEN** een bijstandsaanvraag wordt ingediend door een alleenstaande **WHEN** de klantmanager de vermogenstoets uitvoert en €8.500 vermogen registreert (boven de vrijstellingsgrens van €6.505) **THEN** moet het systeem automatisch `boven_vermogensvrijstelling=true` zetten en de zaak markeren als "afwijzingsvoorstel" met motivatie-template.

**GIVEN** de inkomenstoets resulteert in een inkomen onder de bijstandsnorm **WHEN** beide toetsen zijn afgerond **THEN** moet het systeem `rechtOpBijstand=true` zetten en automatisch een re-integratietraject-object aanmaken.

### REQ-SOC-008: Multi-disciplinair overleg met expliciete toestemmingen

MDO-overleggen MOETEN deelnemers van buiten de gemeente alleen kunnen toevoegen na expliciete toestemming van de cliënt, en deze toestemming MOET per deelnemer worden vastgelegd.

**GIVEN** een jeugdconsulent wil een schoolmaatschappelijk werker toevoegen aan een MDO **WHEN** de deelnemer wordt toegevoegd **THEN** moet het systeem eerst de cliënt-toestemming verifiëren (via een toestemmingsobject of expliciete `toestemmingDeelnameDoorClient=true`) en bij ontbreken een waarschuwing tonen.

**GIVEN** een MDO is afgerond en het verslag wordt gegenereerd **WHEN** het verslag wordt opgeslagen **THEN** moet het systeem voor elke externe deelnemer een log-entry maken met "welke gegevens zijn gedeeld" en "op basis van welke toestemming".

### REQ-SOC-009: Wettelijke bewaartermijnen en automatische vernietiging

Het systeem MOET per zaak een vernietigingsdatum berekenen op basis van de wettelijke bewaartermijn (WMO 15 jaar, Jeugdwet 20 jaar, Participatiewet 10 jaar na laatste mutatie) en automatisch vernietigingsvoorstellen genereren wanneer de termijn verstrijkt.

**GIVEN** een WMO-zaak is afgesloten op 2026-03-15 met `bewaarTermijnJaren=15` **WHEN** het systeem de vernietigingsdatum berekent **THEN** moet `vernietigingDatum=2041-03-15` worden opgeslagen.

**GIVEN** de huidige datum is binnen 30 dagen van de vernietigingsdatum **WHEN** een dagelijkse batch-job draait **THEN** moet het systeem een vernietigingsvoorstel genereren voor de archivaris met de zaak en motivatie.

### REQ-SOC-010: Auditlog voor toegang tot bijzondere persoonsgegevens

Iedere lees-actie op een sociaal-domein-zaak met bijzondere persoonsgegevens MOET worden gelogd met medewerker-id, tijdstip, IP-adres en de specifieke velden die werden opgevraagd.

**GIVEN** een wmo-consulent opent zaak `zaak-2026-wmo-04832` **WHEN** de zaak wordt getoond **THEN** moet er een auditlog-entry worden geschreven met `medewerkerId`, `tijdstip`, `actie=read`, `zaakId`, en `geraadpleegdeVelden`.

**GIVEN** een functionaris gegevensbescherming wil een AVG-rechten-rapportage **WHEN** hij het overzicht opvraagt voor een specifieke burger **THEN** moet het systeem alle log-entries tonen van wie wanneer welke zaak van die burger heeft ingezien.

## Standards & Sources

- **Wet maatschappelijke ondersteuning 2015 (Wmo 2015)** — artikel 2.3.5 (onderzoek), 2.3.2 (melding), 2.3.6 (beschikking)
- **Jeugdwet (2015)** — artikel 2.3 (jeugdhulpplicht), 6.1.2 (gezinsplan), 7.3 (gegevensverwerking)
- **Participatiewet (2015)** — artikel 18 (algemene bijstand), 31-34 (vermogens- en inkomenstoets), 9 (re-integratie)
- **Algemene Verordening Gegevensbescherming (AVG)** — artikel 9 (bijzondere persoonsgegevens), artikel 6.1.c/e (rechtmatigheid), artikel 30 (verwerkingsregister)
- **Uitvoeringswet AVG (UAVG)** — artikel 23 (uitzonderingsgronden gemeentelijke taken)
- **Selectielijst gemeenten en intergemeentelijke organen 2020** — bewaartermijnen sociaal domein
- **iWMO / iJW standaarden (Zorginstituut)** — berichtenverkeer met zorgaanbieders
- **GEMMA Informatiemodel Sociaal Domein** — referentie-architectuur VNG
- **NEN 7510 / NEN 7512 / NEN 7513** — informatiebeveiliging in de zorg (relevant voor toegangsbeperking)
- **Convenant Gegevensuitwisseling Sociaal Domein** — VNG modelconvenant
- **Wet aanpak meervoudige problematiek sociaal domein (Wams)** — wetsvoorstel/aanstaande wet voor multidisciplinair samenwerken in casusoverleg
- **Wet gemeentelijke schuldhulpverlening (Wgs)** — raakvlak met Participatiewet bij integrale benadering schulden
- **Wet inburgering 2021 (Wi2021)** — raakvlak met Participatiewet voor inburgeringsplichtigen
- **GEMMA Zaaktypecatalogus (ZTC) 2** — referentie zaaktype-codes per sociaal-domein-zaaktype
- **Selectielijst gemeenten 2020** — concrete bewaartermijnen per zaaktype-categorie (15 jaar WMO, 20 jaar Jeugdwet, 10 jaar Participatiewet bijstand, 5 jaar re-integratie)
- **Beleidsregels Functionaris Gegevensbescherming Sociaal Domein** — IBD/VNG-richtlijnen voor FG-toezicht op bijzondere persoonsgegevens

## Cross-app integration

- **openregister** — `pii-detection-masking` voor automatische anonimisering bij export; `audit-logging` voor toegang tot bijzondere persoonsgegevens; bewaartermijn-engine voor vernietigingsvoorstellen.
- **openconnector** — iWMO/iJW-berichtenverkeer met zorgaanbieders; CJG-koppeling; GGD-systeemkoppeling; BSN-validatie via BRP.
- **docudesk** — beschikking-templates voor WMO/Jeugdwet/Participatiewet (zie ook brief 2 beschikking-generatie); standaardbrieven voor vraagverhelderende gesprekken en evaluaties.
- **opencatalogi** — publicatie van zaaktype-catalogus voor het sociaal domein conform GEMMA.
- **mydash** — dashboard voor wijkteam-managers met doorlooptijden, caseload-verdeling en wettelijke termijnoverschrijdingen.

## Target users

**Primair:**
- **WMO-consulent** — voert vraagverhelderende gesprekken, stelt indicaties op, bewaakt termijnen.
- **Jeugdconsulent / jeugdteam-medewerker** — stelt gezinsplannen op, organiseert MDO's, monitort verlengingen.
- **Klantmanager Werk & Inkomen** — verwerkt bijstandsaanvragen, voert toetsen uit, begeleidt re-integratie.

**Secundair:**
- **Wijkteam-manager** — bewaakt caseload en wettelijke termijnen via mydash-dashboard.
- **Functionaris Gegevensbescherming (FG)** — controleert AVG-naleving, verzorgt rechten-verzoeken van burgers.
- **Beleidsmedewerker sociaal domein** — analyseert geanonimiseerde data voor beleidsvorming.
- **Archivaris** — beoordeelt vernietigingsvoorstellen en bewaartermijn-uitzonderingen.

**Externe stakeholders (via openconnector):**
- Zorgaanbieders (WMO en Jeugdwet) — ontvangen toewijzingen via iWMO/iJW.
- Huisartsen / jeugdartsen — verwijzers in Jeugdwet-trajecten.
- UWV — uitwisseling re-integratiegegevens en re-integratiegegevens werkfit-trajecten.
- Sociale Verzekeringsbank (SVB) — kindgebonden budget, AIO-aanvulling, persoonsgebonden budget (Pgb)-trekkingsrechten.
- Centraal Justitieel Incassobureau (CJIB) — voor terugvordering onterecht ontvangen bijstand.
- Centrum voor Jeugd en Gezin (CJG) — voorportaal en lichte interventies in Jeugdwet.
- GGD — uitvoerder van publieke jeugdzorg, jeugdgezondheidszorg en deelnemer aan MDO.
- Veilig Thuis — meldpunt huiselijk geweld en kindermishandeling, gerelateerd aan Jeugdwet-trajecten.
- Schuldhulpverleningspartners (kredietbanken, vrijwilligersorganisaties) — gekoppeld aan Participatiewet- en WMO-trajecten via Wgs-flow.
- Sociale wijkteams (samenwerkingsverbanden van meerdere gemeenten) — gedeelde casuïstiek op regioniveau.

---
status: draft
---
# Beschikking compose → ondertekenen → Berichtenbox → archief

## Purpose

Een beschikking is in het Nederlands bestuursrecht de schriftelijke uitwerking van een individueel besluit dat bestemd is voor één belanghebbende of een specifiek bepaalbare groep belanghebbenden. Een beschikking is het sluitstuk van vrijwel iedere gemeentelijke zaak: het officiële besluit waarmee de gemeente rechten of plichten van een burger of bedrijf vaststelt. Het opstellen, ondertekenen, verzenden en archiveren van een beschikking is in de huidige praktijk vaak een fragmentarisch proces waarbij vier of vijf verschillende systemen worden ingezet (zaaksysteem voor de data, Word/PDF voor de opmaak, een aparte ondertekenservice, een aparte Berichtenbox-koppeling, een aparte archiefkoppeling), met handmatige overdrachtsstappen die foutgevoelig zijn en moeilijk auditbaar.

Deze brief beschrijft een geïntegreerde 4-app-pipeline voor het volledige beschikking-traject binnen het Conduction-ecosysteem: procest beheert de zaak en de beschikking-state-machine, docudesk levert de template-engine en stelt de definitieve beschikkingstekst samen, openconnector verzorgt de eIDAS-gekwalificeerde elektronische handtekening (via een gecertificeerde Trust Service Provider) en de Berichtenbox-aanlevering, en openregister fungeert als duurzame archiefopslag conform de TMLO/MDTO-metadata-eisen. De state-machine bewaakt iedere overgang: ontwerp → akkoord-mandaat → ondertekend → verzonden → ontvangen-bevestiging → gearchiveerd, met automatische triggers voor bezwaartermijn, archiefoverdracht en signalen naar andere processen.

Het doel is dat een behandelaar binnen procest één knop "beschikking opstellen" indrukt, vervolgens een conceptbeschikking ziet die volledig is voorgevuld met zaakgegevens (NAW, beslissing, motivering, rechtsmiddelenclausule, leges), deze waar nodig kan corrigeren, in mandaat laat akkoord geven door een gemandateerd ambtenaar of B&W-besluit, ondertekent met een gekwalificeerde digitale handtekening, automatisch verzendt naar de Berichtenbox van de burger (MijnOverheid) of het bedrijf (eHerkenning OIN), en aan het einde van de termijn automatisch geconsolideerd en gearchiveerd ziet worden. Het hele traject is volledig auditbaar, alle juridisch relevante gebeurtenissen (mandaat, ondertekening, verzending, ontvangst) zijn voorzien van een tijdstempel en cryptografisch bewijs, en de bezwaartermijn loopt automatisch vanaf de bekendmakingsdatum.

De pipeline is bewust generiek opgezet zodat hij voor álle beschikkingstypen werkt: omgevingsvergunningen, WMO-toekenningen, bijstandsbesluiten, dwangsommen, last onder bestuursdwang, subsidiebesluiten, leges-aanslagen en horeca-/evenementenvergunningen. Per beschikkingstype is alleen een template (in docudesk) plus een mandaatregeling nodig; de state-machine, ondertekening, verzending en archivering werken voor alle types identiek. Dit voorkomt dat iedere domeinmodule een eigen beschikkingsflow moet implementeren, wat in veel zaaksystemen tot fragmentatie leidt. Templates zijn versiebeheerd, zodat bij een wetswijziging (bijvoorbeeld een nieuwe rechtsmiddelenclausule onder de Omgevingswet) een nieuwe templateversie kan worden uitgerold zonder dat oude beschikkingen retroactief worden beïnvloed.

Een tweede kernaspect is juridische verdedigbaarheid: bij een bezwaarprocedure of rechtszaak moet onomstotelijk kunnen worden aangetoond wie heeft ondertekend, op welk moment, op basis van welk mandaat, en wat exact is bekendgemaakt aan de geadresseerde. Het audit-bewijspakket (zie REQ-BES-009) bundelt al deze informatie in één export. De eIDAS-validatie kan jaren later opnieuw worden uitgevoerd dankzij de Europese Trust List, en de PDF/A-3-archiefformaten zorgen dat de visuele weergave en de embedded validatiebijlagen duurzaam toegankelijk blijven, ook na een eventuele leveranciersmigratie.

Niet in scope voor deze spec: de daadwerkelijke template-redactie (alleen vakinhoudelijk juristen maken templates, deze worden via docudesk beheerd), de gemeentespecifieke mandaatregelingen (worden eenmalig ingericht), en de behandeling van bezwaarprocedures zelf (apart zaaktype, raakvlak via REQ-BES-006 en REQ-BES-007).

## Data Model

### Beschikking

```json
{
  "id": "besch-2026-04832",
  "zaakId": "zaak-2026-wmo-04832",
  "zaaktype": "wmo-melding",
  "beschikkingType": "toekenning",
  "kenmerk": "Z/2026/04832/B01",
  "ontwerpVersie": 3,
  "huidigeStatus": "ondertekend",
  "templateId": "tpl-wmo-toekenning-huishoudelijke-hulp-v4",
  "samengesteldeInhoud": {
    "format": "pdf-a3",
    "bestandId": "doc-2026-99231",
    "checksumSha256": "a7b3...",
    "paginas": 4
  },
  "geadresseerde": {
    "type": "burger",
    "bsn": "123456789",
    "naam": "M.A. Janssen-de Vries",
    "berichtenboxKanaal": "mijnoverheid",
    "berichtenboxBevestigd": true
  },
  "beslissing": {
    "soort": "toekenning",
    "onderwerp": "huishoudelijke ondersteuning",
    "omvang": "4 uur per week",
    "ingangsdatum": "2026-04-01",
    "einddatum": "2027-04-01"
  },
  "motivering": "Op basis van het onderzoek van 28 maart 2026 (verslag bijgevoegd) en de indicatiestelling door wmo-consulent is geconstateerd dat aanvrager tijdelijk niet in staat is zelfstandig de huishoudelijke taken uit te voeren. Op grond van artikel 2.3.5 Wmo 2015 wordt 4 uur per week huishoudelijke ondersteuning toegekend voor een periode van 12 maanden.",
  "rechtsmiddelenClausule": "Indien u het niet eens bent met dit besluit kunt u binnen zes weken na de verzenddatum een bezwaarschrift indienen bij het college van burgemeester en wethouders, postbus 1234, 1000 AB Amsterdam.",
  "legesbedrag": 0.00,
  "bekendmakingDatum": "2026-04-02",
  "bezwaarTermijnEindDatum": "2026-05-14",
  "mandaatGegeven": {
    "mandaatregelingId": "mr-2024-007-wmo",
    "mandaatNiveau": "afdelingsmanager",
    "akkoordDoor": "afdelingsmanager-wmo-15",
    "akkoordDatum": "2026-04-01T14:22:00+02:00"
  },
  "handtekening": {
    "tspProvider": "kpn-gekwalificeerde-handtekening",
    "tspProviderEidasId": "NL-TSP-0001",
    "ondertekenaar": "afdelingsmanager-wmo-15",
    "ondertekeningTijdstip": "2026-04-01T14:25:33+02:00",
    "soort": "gekwalificeerde-elektronische-handtekening",
    "certificaatSerienummer": "0x7a82bc...",
    "validatieRapportId": "val-2026-99231"
  },
  "verzending": {
    "kanaal": "berichtenbox-mijnoverheid",
    "verzondenOp": "2026-04-02T09:00:00+02:00",
    "verzondenDoor": "systeem",
    "berichtId": "MO-2026-04-02-771234",
    "ontvangstBevestigingOp": "2026-04-03T11:42:00+02:00",
    "leesBevestigingOp": "2026-04-04T18:55:00+02:00"
  },
  "archief": {
    "gearchiveerdOp": null,
    "archiefId": null,
    "tmloMetadata": null,
    "vernietigingsdatum": "2041-04-02"
  }
}
```

### BeschikkingTemplate (docudesk)

```json
{
  "id": "tpl-wmo-toekenning-huishoudelijke-hulp-v4",
  "naam": "WMO toekenning huishoudelijke hulp v4",
  "zaaktypeFamilie": "wmo-melding",
  "beschikkingTypes": ["toekenning"],
  "versie": "4.0",
  "ingangsdatum": "2026-01-01",
  "huidigeStatus": "actief",
  "templateEngine": "twig-jinja-style",
  "templateBron": "tpl-bron-bestand-id-77234",
  "verplichteVelden": ["geadresseerde.naam", "beslissing.omvang", "beslissing.ingangsdatum", "motivering"],
  "rechtsmiddelenClausuleBron": "clausule-wmo-bezwaar-college-bw-v2",
  "huisstijl": {
    "logoId": "img-gemeente-logo",
    "kleur": "#003D7A",
    "lettertype": "Arial"
  },
  "ondertekenaarRol": "afdelingsmanager-wmo",
  "mandaatregelingId": "mr-2024-007-wmo"
}
```

### BeschikkingType (waardetype, niet eigen entiteit)

Mogelijke waarden voor `beschikkingType`:
- `toekenning` — positieve beslissing op een aanvraag (vergunning, uitkering, subsidie)
- `afwijzing` — negatieve beslissing op een aanvraag
- `gedeeltelijke-toekenning` — toekenning met afwijkende voorwaarden of beperkter dan gevraagd
- `intrekking` — een eerder genomen besluit wordt ingetrokken
- `wijziging` — een eerder besluit wordt gewijzigd (anders dan intrekking)
- `weigering-buiten-behandeling` — aanvraag wordt niet inhoudelijk behandeld (Awb 4:5)
- `last-onder-dwangsom` — handhavingsbesluit
- `last-onder-bestuursdwang` — handhavingsbesluit met fysieke uitvoeringsdreiging
- `legesaanslag` — financieel besluit
- `subsidievaststelling` — definitief subsidiebedrag na verantwoording

### MandaatRegeling

```json
{
  "id": "mr-2024-007-wmo",
  "naam": "Mandaatregeling WMO toekenningen",
  "verleendDoor": "college-bw",
  "verleendDatum": "2024-03-15",
  "intrekkingsDatum": null,
  "mandaatGroepen": [
    {"niveau": "consulent", "tot_bedrag": 5000, "zaaktypes": ["wmo-melding"], "beschikkingTypes": ["toekenning"]},
    {"niveau": "afdelingsmanager", "tot_bedrag": 25000, "zaaktypes": ["wmo-melding"], "beschikkingTypes": ["toekenning", "afwijzing"]},
    {"niveau": "directeur", "tot_bedrag": null, "zaaktypes": ["wmo-melding"], "beschikkingTypes": ["toekenning", "afwijzing", "wijziging"]}
  ],
  "ondermandaatToegestaan": true
}
```

### StateMachineLog

```json
{
  "id": "smlog-2026-04832-007",
  "beschikkingId": "besch-2026-04832",
  "overgang": {
    "van": "akkoord-mandaat",
    "naar": "ondertekend",
    "tijdstip": "2026-04-01T14:25:33+02:00",
    "actor": "afdelingsmanager-wmo-15",
    "actorType": "medewerker",
    "trigger": "handmatig",
    "bewijsMateriaal": {
      "soort": "tsp-handtekening-rapport",
      "rapportId": "val-2026-99231"
    }
  }
}
```

### BezwaarTrigger

```json
{
  "id": "bezw-trig-2026-04832",
  "beschikkingId": "besch-2026-04832",
  "bekendmakingDatum": "2026-04-02",
  "bezwaarTermijnEindDatum": "2026-05-14",
  "herinneringDatum": "2026-05-07",
  "bezwaarOntvangen": false,
  "bezwaarZaakId": null,
  "archiefTriggerActief": true,
  "archiefDatum": "2026-05-15"
}
```

### TmloMetadata (bij archivering)

```json
{
  "id": "tmlo-2026-99231",
  "beschikkingId": "besch-2026-04832",
  "schema": "TMLO-1.2",
  "fields": {
    "identificatieKenmerk": "Z/2026/04832/B01",
    "aggregatieniveau": "Archiefstuk",
    "naam": "Beschikking WMO huishoudelijke hulp",
    "classificatie": "openbaar-met-uitzondering-persoonsgegevens",
    "creatieDatum": "2026-04-01",
    "bekendmakingDatum": "2026-04-02",
    "verantwoordelijkeOrganisatie": "Gemeente Amsterdam",
    "vertrouwelijkheid": "vertrouwelijk",
    "bewaartermijn": "15 jaar na afsluiting",
    "vernietigingsdatum": "2041-04-02",
    "relatieAndereStukken": ["zaak-2026-wmo-04832"]
  }
}
```

## Requirements

### REQ-BES-001: Conceptbeschikking vanuit zaakgegevens samenstellen

Het systeem MOET een conceptbeschikking kunnen samenstellen op basis van een template (docudesk) en de actuele zaakgegevens (procest), waarbij alle verplichte velden zijn voorgevuld en ontbrekende verplichte velden expliciet worden gemarkeerd.

**GIVEN** een WMO-zaak heeft een afgeronde indicatiestelling en de behandelaar klikt op "beschikking opstellen" **WHEN** het systeem het template `tpl-wmo-toekenning-huishoudelijke-hulp-v4` toepast **THEN** moet een conceptbeschikking worden gegenereerd waarin geadresseerde, omvang ondersteuning, ingangsdatum en motivering automatisch zijn ingevuld op basis van de zaakdata en de indicatiestelling.

**GIVEN** een verplicht veld in het template kan niet worden ingevuld vanuit de zaak (bijvoorbeeld omdat de motivering nog moet worden uitgewerkt) **WHEN** de conceptbeschikking wordt getoond **THEN** moet het ontbrekende veld duidelijk worden gemarkeerd en moet de behandelaar het zelf invullen voordat de status verder kan dan "ontwerp".

### REQ-BES-002: Mandaatverificatie voor akkoordstap

Voordat een beschikking kan overgaan van "ontwerp" naar "akkoord-mandaat" MOET het systeem verifiëren dat de gekozen akkoordgever volgens de geldende mandaatregeling bevoegd is voor dit beschikkingstype en bedrag.

**GIVEN** een beschikking betreft een toekenning van €18.000 in het zaaktype WMO **WHEN** de behandelaar een consulent (mandaatniveau tot €5.000) selecteert als akkoordgever **THEN** moet het systeem de selectie afwijzen met een melding dat een afdelingsmanager (tot €25.000) of directeur nodig is.

**GIVEN** een afdelingsmanager met geldig mandaat geeft akkoord **WHEN** de akkoord-actie wordt geregistreerd **THEN** moet de state-machine overgaan naar "akkoord-mandaat" en moet de mandaatreferentie (regeling + niveau + persoon + tijdstip) worden opgeslagen in het `mandaatGegeven`-blok van de beschikking.

### REQ-BES-003: eIDAS-gekwalificeerde elektronische handtekening

Het ondertekenen van een beschikking MOET gebeuren via een eIDAS-gekwalificeerde Trust Service Provider (TSP), en het ondertekeningsresultaat MOET een validatierapport opleveren dat duurzaam aan de beschikking wordt gekoppeld.

**GIVEN** de beschikking heeft status "akkoord-mandaat" en de afdelingsmanager start de ondertekening **WHEN** de TSP-flow wordt aangeroepen via openconnector **THEN** moet de behandelaar via de TSP (bijvoorbeeld KPN Gekwalificeerde Handtekening of EvidosSign) ondertekenen en moet het resulterende validatierapport worden opgeslagen met TSP-id, certificaat-serienummer en tijdstempel.

**GIVEN** de TSP retourneert een validatie-resultaat "ongeldig" of "verlopen certificaat" **WHEN** het systeem de respons verwerkt **THEN** mag de status NIET overgaan naar "ondertekend" en moet de fout worden gelogd met een waarschuwing naar de behandelaar.

### REQ-BES-004: Berichtenbox-aanlevering met kanaalkeuze burger/bedrijf

Verzending van een ondertekende beschikking naar de geadresseerde MOET via de juiste Berichtenbox-flow gebeuren: MijnOverheid voor burgers (BSN-gebaseerd) en eHerkenning OIN voor bedrijven (KvK/OIN-gebaseerd), met fallback naar fysieke post als de geadresseerde geen Berichtenbox heeft geactiveerd.

**GIVEN** de geadresseerde is een burger met BSN en heeft MijnOverheid Berichtenbox geactiveerd **WHEN** het systeem de verzending uitvoert **THEN** moet de beschikking via openconnector naar MijnOverheid worden gestuurd, moet `berichtId` worden opgeslagen, en moet de verzendstatus worden bijgewerkt op basis van de ontvangstbevestiging.

**GIVEN** de geadresseerde is een bedrijf met een OIN **WHEN** het systeem de verzending uitvoert **THEN** moet de beschikking via de eHerkenning OIN-koppeling worden gestuurd naar het zakelijke Berichtenbox-eindpunt.

**GIVEN** de geadresseerde heeft geen Berichtenbox geactiveerd **WHEN** het systeem dit detecteert **THEN** moet de beschikking worden gemarkeerd voor fysieke verzending en moet een PDF-printtaak worden aangemaakt voor de postkamer.

### REQ-BES-005: State-machine voor beschikkingsstatus

Het systeem MOET een formele state-machine afdwingen met de toegestane overgangen ontwerp → akkoord-mandaat → ondertekend → verzonden → ontvangen-bevestiging → gearchiveerd; iedere overgang MOET worden gelogd met actor, tijdstip en bewijsmateriaal.

**GIVEN** een beschikking heeft status "ondertekend" **WHEN** iemand probeert direct naar "gearchiveerd" te springen **THEN** moet de overgang worden afgewezen omdat deze niet in de toegestane transities is gedefinieerd; alleen "ondertekend → verzonden" is toegestaan.

**GIVEN** iedere statusovergang gebeurt **WHEN** de overgang wordt verwerkt **THEN** moet een `StateMachineLog`-entry worden geschreven met van/naar, tijdstip, actor en bewijsmateriaal-referentie (bijvoorbeeld TSP-rapport-id bij ondertekening).

### REQ-BES-006: Bezwaartermijn-trigger op bekendmakingsdatum

Het systeem MOET automatisch een bezwaartermijn van 6 weken (Awb artikel 6:7) starten op de bekendmakingsdatum, een herinnering plaatsen op 1 week voor afloop, en bij ontvangst van een bezwaarschrift de bezwaarprocedure automatisch koppelen aan de oorspronkelijke beschikking.

**GIVEN** een beschikking is verzonden met `bekendmakingDatum=2026-04-02` **WHEN** de state-machine de status naar "verzonden" zet **THEN** moet `bezwaarTermijnEindDatum=2026-05-14` worden berekend en moet een herinnering worden ingepland voor 2026-05-07.

**GIVEN** een bezwaarschrift wordt ontvangen tegen deze beschikking **WHEN** het bezwaar wordt geregistreerd **THEN** moet automatisch een link worden gelegd naar de oorspronkelijke beschikking en moet de bezwaarprocedure worden gestart binnen het bestaande bezwaar-zaaktype.

### REQ-BES-007: Archiefoverdracht met TMLO/MDTO-metadata

Na verstrijken van de bezwaartermijn (of na herroeping/inwilliging bezwaar) MOET de beschikking automatisch worden geconsolideerd tot een onveranderbare archiefkopie en worden overgedragen aan het archief (openregister) met volledige TMLO- of MDTO-metadata.

**GIVEN** een beschikking heeft `bezwaarTermijnEindDatum=2026-05-14` en er is geen bezwaar ingediend **WHEN** een dagelijkse batch-job op 2026-05-15 draait **THEN** moet de beschikking automatisch worden gearchiveerd, moet een TMLO-1.2-metadatablok worden gegenereerd en aan de archiefkopie worden gekoppeld, en moet de status overgaan naar "gearchiveerd".

**GIVEN** een gemeente werkt al volledig met MDTO (opvolger van TMLO) **WHEN** de archiefoverdracht plaatsvindt **THEN** moet het systeem op basis van de gemeente-instelling MDTO-metadata genereren in plaats van TMLO.

### REQ-BES-008: Niet-wijzigbare beschikking na ondertekening

Een beschikking met status "ondertekend" of verder MAG NIET meer inhoudelijk worden gewijzigd; alleen het toevoegen van procesgebeurtenissen (verzending, ontvangstbevestiging, bezwaar) is toegestaan.

**GIVEN** een beschikking heeft status "ondertekend" **WHEN** iemand probeert de motivering aan te passen **THEN** moet de wijziging worden afgewezen met een melding dat een nieuwe versie (intrekkings- of wijzigingsbeschikking) moet worden opgesteld.

**GIVEN** een beschikking moet worden ingetrokken of gecorrigeerd **WHEN** een behandelaar "wijzigingsbeschikking opstellen" kiest **THEN** moet het systeem een nieuwe beschikking aanmaken met type "wijziging" of "intrekking" die expliciet refereert aan de oorspronkelijke beschikking.

### REQ-BES-009: Audit-bewijs voor juridische verificatie

Bij iedere beschikking MOET het systeem een verifieerbaar audit-bewijspakket kunnen genereren met daarin: alle statusovergangen, mandaatreferentie, TSP-validatierapport, verzendbewijzen, ontvangstbevestiging en eventueel bezwaarprocesgegevens.

**GIVEN** een rechter of bezwaarcommissie vraagt om verificatie van een beschikking **WHEN** een medewerker "audit-pakket exporteren" kiest **THEN** moet het systeem een ondertekend ZIP-pakket genereren met de gearchiveerde PDF, het TSP-validatierapport, de state-machine-log, de mandaatregeling die geldig was op het moment van akkoord, en de verzendbewijzen.

**GIVEN** een eIDAS-validatie wordt opnieuw uitgevoerd op het audit-pakket **WHEN** de PDF en het validatierapport worden gecontroleerd **THEN** moet de signature-integriteit worden bevestigd en moet de TSP-certificaatketen verifieerbaar zijn ten opzichte van de Europese Trust List.

### REQ-BES-010: Templates versiebeheer met effectieve datum

Beschikkingstemplates in docudesk MOETEN versiebeheerd zijn met een ingangsdatum; het samenstellen van een beschikking MOET gebruikmaken van de template-versie die op de bekendmakingsdatum (of een eerdere relevante datum) geldig was.

**GIVEN** template `tpl-wmo-toekenning-huishoudelijke-hulp` heeft versie 3 ingangsdatum 2025-01-01 en versie 4 ingangsdatum 2026-01-01 **WHEN** een beschikking wordt opgesteld op 2026-04-01 **THEN** moet versie 4 worden gebruikt.

**GIVEN** een beschikking uit 2025 wordt opnieuw verzonden of heruitgegeven **WHEN** het systeem de template-versie kiest **THEN** moet versie 3 (geldig in 2025) worden gebruikt om consistente bewoordingen te garanderen.

## Standards & Sources

- **Algemene wet bestuursrecht (Awb)** — artikel 3:41 (bekendmaking), 6:7 (bezwaartermijn), 1:3 (besluit), 10:3-10:12 (mandaat)
- **eIDAS-verordening (EU) 910/2014** — gekwalificeerde elektronische handtekening, Trust Service Providers, Europese Trust List
- **Wet elektronische dienstverlening burgerzaken (Wedb)** — Berichtenbox voor burgers
- **Wet digitale overheid (Wdo)** — verplichte elektronische dienstverlening
- **TMLO (Toepassingsprofiel Metadatering Lokale Overheden) 1.2** — VHIC/VNG
- **MDTO (Metagegevens voor Duurzaam Toegankelijke Overheidsinformatie)** — Nationaal Archief, opvolger TMLO
- **Archiefwet 1995** — overdrachts-, bewaar- en vernietigingsverplichtingen
- **NEN-ISO 14641:2018** — elektronische archivering
- **PDF/A-3 (ISO 19005-3)** — duurzaam archiefformaat met embedded validatiebijlagen
- **MijnOverheid Berichtenbox API** — Logius-koppelvlak
- **OIN-register** — Overheidsidentificatienummer voor zakelijke berichtenbox
- **GEMMA Zaakprocesmodel** — VNG referentie-architectuur
- **NEN 2082** — eisen aan functionaliteit voor records management
- **Open Notitie Beheer-API (Dimpact/VNG)** — koppelvlak voor procesgebonden notities
- **API Strategie voor de Nederlandse Overheid 1.0** — Forum Standaardisatie verplicht voor publicatie REST-API's
- **ETSI EN 319 102-1** — procedures voor het creëren en valideren van AdES-handtekeningen
- **ETSI EN 319 162** — Associated Signature Container (ASiC) voor het bundelen van handtekeningen
- **ZGW-API (RGBZ 2)** — Zaakgericht Werken API-set die per gemeente de canonieke zaakdata-uitwisseling bepaalt
- **WOO (Wet Open Overheid)** — bekendmakingsverplichtingen voor besluiten van algemene strekking

## Cross-app integration

- **procest (host)** — beheert zaak en beschikking-state-machine, mandaatregeling-objecten, bezwaartermijn-triggers.
- **docudesk** — template-engine (Twig-style), template-versiebeheer, samenstelling van PDF/A-3 met huisstijl en logo's, vulling van placeholders vanuit zaakdata.
- **openconnector** — adapter naar eIDAS-TSP's (KPN, EvidosSign, ConnectiSafe), Berichtenbox MijnOverheid (Logius), eHerkenning OIN-Berichtenbox, fysieke post-printservice (Print Mail).
- **openregister** — archiefopslag met TMLO/MDTO-metadata, vernietigingstermijn-engine, audit-logging van alle archiefacties.
- **opencatalogi** — publicatie van beschikkingstype-catalogus (welke beschikkingen verstrekt deze gemeente).
- **mydash** — dashboard voor doorlooptijden van beschikkingen, ondertekenings-doorlooptijd, Berichtenbox-bezorgingsstatistieken.

## Target users

**Primair:**
- **Behandelaar / consulent** — start beschikking-flow, vult ontbrekende velden in, start ondertekening.
- **Afdelingsmanager / gemandateerd ambtenaar** — geeft akkoord namens college, ondertekent met TSP.
- **Postkamer / scanstraat** — verwerkt fysieke-post-fallback.

**Secundair:**
- **Archivaris** — beheert TMLO/MDTO-metadata-mapping, controleert archiefoverdracht, beoordeelt vernietigingsvoorstellen.
- **Juridisch medewerker bezwaarcommissie** — gebruikt audit-pakket bij bezwaarbehandeling.
- **Functionaris gegevensbescherming** — controleert AVG-classificatie en toegangscontroles op beschikkingen.
- **Auditor / accountant** — gebruikt audit-pakket bij rechtmatigheidscontroles.

**Stakeholders:**
- **Burger / bedrijf (geadresseerde)** — ontvangt beschikking in Berichtenbox of fysieke post, kan via Mijn Gemeente (zie brief 3) status volgen.
- **eIDAS Trust Service Provider** — verzorgt de gekwalificeerde handtekening en valideert certificaten.
- **Logius (MijnOverheid)** — Berichtenbox-leverancier voor burgers.
- **B&W / college van burgemeester en wethouders** — bron van mandaatverlening die de hele pipeline juridisch grondvest; ontvangt periodiek rapportage over uitgeoefend mandaat.
- **Gemeenteraad / rekenkamer** — incidentele audit van beschikkingsdoorlooptijden en mandaatnaleving via mydash.
- **Nationale Ombudsman** — gebruikt het audit-pakket bij klachten over besluiten.
- **Rechter (bestuursrechter)** — bij beroep volgt het hele audit-pakket inclusief eIDAS-validatie als bewijsmiddel.
- **Nationaal Archief / regionaal historisch centrum** — uiteindelijke ontvanger van zaakdossiers die na hun bewaartermijn niet vernietigd worden maar permanent bewaard blijven (B-categorie).

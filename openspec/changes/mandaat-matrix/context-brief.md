---
status: proposed
app: procest
spec: mandaat-matrix
depends_on:
  - procest base (zaaktype + zaak + beslissing)
  - openregister abac-policy-engine
  - decidesk (mandateringsbesluit als raadsbesluit)
related:
  - hydra/openspec/specs/i18n-nl-en/spec.md
target_users:
  - Juridisch medewerker
  - HR-medewerker
  - Mandaatcoordinator / mandaathouder
  - Zaakbehandelaar
  - Afdelingshoofd / teamleider
  - Auditor / accountant
---

# Mandaat-matrix voor zaak-gestuurde besluitvorming

## Purpose

In het Nederlandse openbaar bestuur is mandaat een formele bevoegdheid om in naam van een ander bestuursorgaan een besluit te nemen. Het college van burgemeester en wethouders neemt jaarlijks honderden tot duizenden besluiten, maar in de praktijk worden de meeste van die besluiten in mandaat genomen door ambtenaren. Wie welk type besluit mag nemen wordt vastgelegd in een mandaatbesluit (artikel 10:3 Awb), een formeel raadsbesluit of collegebesluit dat de mandaatregeling bevat. Deze regeling bestaat doorgaans uit een uitgebreide mandaattabel waarin per bevoegdheid is vastgelegd: het soort besluit (bv. "verlenen omgevingsvergunning kleine bouwactiviteit"), de gemandateerde (functietitel), eventuele ondergeschiktheid (alleen onder voorwaarden), en het bedrag-/grootteplafond.

In de huidige praktijk is deze mandaattabel meestal een Word-document of Excel-bestand dat door de afdeling Juridische Zaken wordt beheerd. Bij elke beslissing in een zaaksysteem moet handmatig worden gecheckt of de behandelaar bevoegd is, wat in de praktijk neerkomt op "vraag het je leidinggevende" of "kijk naar het mandaatbesluit van vorig jaar". Dit leidt tot rechtsongeldige besluiten (genomen door iemand zonder mandaat), vertraging in besluitvorming (escalaties die niet nodig waren), en moeilijk te reconstrueren audit-trails ("wie mocht dit ook alweer beslissen?"). Bij personeelsmutaties — een teamleider die met pensioen gaat, een nieuwe afdeling die wordt opgericht, een wisseling van portefeuillehouder — moet de hele mandaattabel handmatig worden bijgewerkt.

De `mandaat-matrix` capability brengt een datagedreven mandaatregister naar procest dat: (1) per organisatie en per orgaan (college, burgemeester, raad, secretaris) een mandaattabel onderhoudt; (2) elk mandaat koppelt aan een wettelijke grondslag (mandateringsbesluit) en een geldigheidsperiode; (3) per zaaktype en beslissings-type bepaalt wie bevoegd is op basis van rol + plafond + ondergeschikte voorwaarden; (4) effective dating ondersteunt zodat mandaten met terugwerkende kracht of toekomstige ingangsdatum kunnen worden vastgelegd; (5) bij elke beslissing in een zaak realtime checkt of de behandelaar bevoegd is en zo niet, automatisch escaleert naar de juiste mandaathouder; (6) een complete audit-trail bewaart die per besluit toont welk mandaat is gebruikt; en (7) personeelsmutaties verwerkt door rol-koppelingen en automatische delegatie bij afwezigheid.

## Data Model

**MandateringsBesluit** — formeel besluit dat één of meer mandaten vaststelt of wijzigt. Velden: `besluitNummer`, `besluitNaam`, `besluitOrgaan` (raad / college / burgemeester / secretaris), `besluitDatum`, `inwerkingtreding`, `vervalDatum` (nullable), `vastgesteldDoor` (decidesk besluit-id), `gepubliceerdIn`, `status` (`concept`, `vastgesteld`, `vervallen`, `ingetrokken`), `bijlageDocumentId` (PDF-mandaatregeling).

**Mandaat** — individueel mandaat binnen een besluit. Velden: `besluitId`, `mandaatNummer` (hiërarchisch: "M.3.1.2"), `omschrijving` (bv. "Verlenen omgevingsvergunning bouwactiviteit < €100.000 bouwsom"), `bevoegdheidType` (`besluit_nemen`, `besluit_ondertekenen`, `dwangsom_opleggen`, `boete_opleggen`, `subsidie_verlenen`, `contract_aangaan`, `aanstelling_doen`), `wettelijkeGrondslag` (Awb / sectorale wet artikel-ref), `geldigVanaf`, `geldigTotEnMet`, `voorwaarden` (JSON: plafond_bedrag, plafond_omvang, mandaat_intern_extern), `subdelegatieToegestaan` (boolean), `gemandateerdeRol` (FK naar OrganisatieRol), `mandantOrgaan` (welk orgaan delegeert).

**OrganisatieRol** — rol binnen de organisatie. Velden: `rolNaam` (bv. "Hoofd VTH"), `rolType` (`bestuurder`, `directielid`, `afdelingshoofd`, `teamleider`, `senior_behandelaar`, `behandelaar`, `medewerker`), `parentRolId` (hiërarchie), `afdeling`, `team`, `mandaatNiveau` (hoog/middel/laag — voor snelle filtering).

**MedewerkerRolToewijzing** — wie heeft welke rol wanneer. Velden: `medewerkerId`, `rolId`, `toewijzingVanaf`, `toewijzingTotEnMet`, `toewijzingType` (`primair`, `waarnemer`, `tijdelijk`, `interim`), `toegewezenDoor`, `toewijzingsBesluitId` (optioneel: aanstellingsbesluit).

**MandaatGebruik** — log van elke beslissing waarbij een mandaat is toegepast. Velden: `zaakId`, `beslissingId`, `mandaatId`, `gemandateerdeId` (medewerker die besloot), `rolOpMomentVanBesluit`, `beslissingType`, `beslissingTimestamp`, `bevoegdheidsCheckResult` (`bevoegd`, `niet_bevoegd_geescaleerd`, `bevoegd_via_waarnemer`), `gebruikteVoorwaarden` (snapshot), `geescaleerdNaar` (nullable).

**MandaatEscalatie** — escalatieroute wanneer een behandelaar niet bevoegd is. Velden: `zaakId`, `beslissingType`, `initiatorId` (wie wilde beslissen), `escalatieReden` (`niet_bevoegd`, `plafond_overschreden`, `subdelegatie_niet_toegestaan`, `belangenconflict`), `escalatiePadEindigtBij` (rol-id van bevoegde mandaathouder), `status` (`open`, `goedgekeurd`, `afgewezen`, `terugverwezen`), `besluitTijd`.

## Requirements

### REQ-MANDAAT-001: Mandateringsbesluit importeren uit decidesk

**GIVEN** een vastgesteld collegebesluit "Algemene mandaatregeling gemeente 2026" in decidesk met bijlage mandaattabel.xlsx
**WHEN** een juridisch medewerker de import-functie start en het besluit-id selecteert
**THEN** systeem creëert een nieuw MandateringsBesluit met status `concept`, parseert de tabel naar individuele Mandaat-records, valideert dat elke regel een gemandateerde rol heeft (en die rol bestaat in OrganisatieRol), toont een diff t.o.v. de vorige mandaatregeling (nieuwe mandaten, gewijzigde plafonds, vervallen mandaten), en plaatst het besluit in review-status voor juridische goedkeuring.

### REQ-MANDAAT-002: Bevoegdheidscheck bij beslissings-actie in zaak

**GIVEN** een zaak met type "Omgevingsvergunning" in fase "Beschikking opstellen", een behandelaar met rol "Senior vergunningverlener", en de actie "Vergunning verlenen" met benodigd mandaat `M.3.1.2 - Verlenen omgevingsvergunning < €100.000 bouwsom`
**WHEN** de behandelaar de actie probeert uit te voeren op een zaak met bouwsom €75.000
**THEN** systeem checkt of de rol van de behandelaar het mandaat M.3.1.2 bezit op het huidige moment, valideert dat het bouwsom-plafond niet wordt overschreden (€75K < €100K), en staat de beslissing toe; logt een MandaatGebruik-record met `bevoegdheidsCheckResult: bevoegd`.

### REQ-MANDAAT-003: Automatische escalatie bij plafond-overschrijding

**GIVEN** dezelfde behandelaar met mandaat M.3.1.2 (plafond €100.000), maar nu een zaak met bouwsom €250.000
**WHEN** de behandelaar "Vergunning verlenen" probeert uit te voeren
**THEN** systeem detecteert dat het bouwsom-plafond is overschreden, blokkeert de beslissing, zoekt het naast-hogere mandaat (bv. M.3.1.3 voor bouwsom < €500K toegekend aan rol "Hoofd VTH"), creëert een MandaatEscalatie naar de mandaathouder, stuurt een notification naar het Hoofd VTH met de zaak-link, en wijzigt de zaak-status naar "Wacht op besluit hoger mandaat".

### REQ-MANDAAT-004: Effective dating ondersteunen

**GIVEN** een mandateringsbesluit dat per 1 juli 2026 in werking treedt en het huidige mandateringsbesluit dat op 30 juni 2026 vervalt
**WHEN** een behandelaar op 25 juni 2026 een beslissing wil nemen die effectief op 5 juli 2026 wordt
**THEN** systeem moet het mandaat toepassen dat geldig is op de besluitvormingsdatum (25 juni → oude mandaattabel), niet op de effectieve datum; logt expliciet welk besluit-id is geraadpleegd; biedt een optie aan om de beslissing te plannen voor 1 juli zodat het nieuwe mandaat van toepassing wordt.

### REQ-MANDAAT-005: Subdelegatie afdwingen

**GIVEN** een Mandaat M.4.2.1 ("Vaststellen bestemmingsplan") met `subdelegatieToegestaan: false`, gemandateerd aan rol "Wethouder Ruimtelijke Ordening"
**WHEN** een medewerker met rol "Beleidsmedewerker RO" deze beslissing probeert te nemen (terwijl de wethouder normaal gesproken zou kunnen subdelegeren)
**THEN** systeem weigert de actie expliciet met de melding "Mandaat M.4.2.1 staat subdelegatie niet toe; alleen de Wethouder RO kan dit besluit nemen", escaleert naar de wethouder, en logt de poging in MandaatGebruik met resultaat `niet_bevoegd_geescaleerd`.

### REQ-MANDAAT-006: Waarnemer-mandaat bij afwezigheid

**GIVEN** een Hoofd VTH die met vakantie is van 15-30 augustus, een MedewerkerRolToewijzing met `toewijzingType: waarnemer` voor het Hoofd Stadsbeheer in die periode, en een zaak die in die periode beslist moet worden waarbij Hoofd VTH mandaathouder is
**WHEN** een behandelaar op 22 augustus een mandaat-check uitvoert
**THEN** systeem detecteert dat Hoofd VTH afwezig is, vindt de waarnemer-toewijzing, accepteert het besluit door Hoofd Stadsbeheer met `bevoegdheidsCheckResult: bevoegd_via_waarnemer`, logt expliciet wie als waarnemer optreedt voor welke periode en op welk mandaat, zodat dit in de audit-trail terug te vinden is.

### REQ-MANDAAT-007: Mandaat-matrix raadplegen per zaaktype

**GIVEN** een zaaktype "Aanvraag standplaatsvergunning markt" met meerdere beslissingsmomenten (ontvankelijkheidsbeslissing, vergunning verlenen, intrekking)
**WHEN** een zaakbehandelaar de mandaat-matrix opent vanuit het zaaktype-overzicht
**THEN** systeem toont per beslissings-type welk mandaat van toepassing is, welke rollen dat mandaat bezitten, welke plafonds gelden, en welke personen op dit moment in die rollen zitten; biedt een filter "wat mag ik?" zodat de behandelaar zijn eigen bevoegdheden kan zien.

### REQ-MANDAAT-008: Audit-trail per genomen besluit

**GIVEN** een afgesloten zaak met meerdere beslissingen die elk een MandaatGebruik-log hebben
**WHEN** een accountant een audit doet en het zaakdossier opent
**THEN** systeem toont per beslissing: welk mandaat is toegepast (met mandaatNummer en omschrijving), welk besluit dat mandaat vaststelde (met decidesk-link), welke rol de behandelaar had op het moment van besluit (snapshot zodat latere rolwijzigingen niet retro-actief het oude besluit lijken te ondergraven), welke voorwaarden golden, en eventuele escalaties.

### REQ-MANDAAT-009: Belangenconflict-check

**GIVEN** een mandaat-check waarbij de behandelaar zelf belanghebbende is (bv. omgevingsvergunning op het adres van de behandelaar, of een familielid uit BRP-koppeling) of waarbij eerder een melding van belangenconflict is geregistreerd
**WHEN** de behandelaar een beslissing probeert te nemen
**THEN** systeem detecteert het belangenconflict (op basis van BSN-match met aanvrager/eigenaar in de zaak, of expliciete conflict-melding), blokkeert de beslissing met `escalatieReden: belangenconflict`, escaleert naar een alternatieve gemandateerde uit dezelfde rol-groep, en logt dit als bestuurlijk-juridisch significant event.

### REQ-MANDAAT-010: Personeelsmutaties verwerken zonder mandaatregeling-wijziging

**GIVEN** een vertrekkende teamleider en een nieuwe teamleider die dezelfde functie overneemt
**WHEN** HR de MedewerkerRolToewijzing van de oude teamleider afsluit (`toewijzingTotEnMet`) en een nieuwe toewijzing aanmaakt voor de nieuwe teamleider
**THEN** systeem moet automatisch alle mandaten die aan de rol "Teamleider X" gekoppeld zijn nu effectief overdragen aan de nieuwe persoon zonder dat het mandateringsbesluit gewijzigd hoeft te worden; lopende escalaties die geadresseerd waren aan de oude persoon worden automatisch geheradresseerd naar de nieuwe rolhouder.

## Standards

- **Algemene wet bestuursrecht (Awb) artikel 10:1 t/m 10:12** — wettelijk kader voor mandaat, volmacht, en delegatie
- **Gemeentewet artikelen over collegebesluiten en mandaatregelingen**
- **VNG model-mandaatbesluiten** — landelijke templates voor mandaatregelingen
- **GEMMA Procesarchitectuur** — referentie-architectuur voor besluitvormingsprocessen in gemeenten
- **NORA / ENSIA** — controles op autorisatie en bevoegdhedenbeheer
- **AVG / NEN 7510** — logging van wie welke beslissing nam (verwerkingsverantwoordelijkheid)
- **ISO 27001 A.9 (Access Control)** — toegangsbeheer en bevoegdheden

## Cross-app

- **openregister abac-policy-engine** — implementeert de fijnmazige autorisatie-checks op basis van rol + voorwaarden; mandaat-matrix produceert ABAC-policies die door de policy-engine worden ge-evalueerd
- **decidesk** — mandateringsbesluiten worden als raadsbesluit/collegebesluit vastgesteld in decidesk en geïmporteerd; juridisch sluitende koppeling tussen mandaat en wettelijke grondslag
- **procest base** — zaak + beslissing zijn de natuurlijke aanhakingspunten; bij elke beslissings-actie wordt een mandaat-check uitgevoerd
- **openconnector** — koppeling naar HR-systemen (AFAS, ADP) om medewerker-rol-toewijzingen automatisch te synchroniseren
- **mydash** — dashboards met mandaat-gebruik (welke mandaten worden vaak ingezet, welke escalaties komen veel voor, doorlooptijd-impact)
- **docudesk** — automatisch genereren van mandaat-overzichten als bijlage bij jaarverslagen en bestuurlijke verantwoording
- **leges-heffingen** — restitutie-besluiten gebruiken de mandaat-matrix om te bepalen wie een restitutie mag toekennen

## Target users

Juridisch medewerkers van de afdeling JBZ (Juridische en Bestuurlijke Zaken) onderhouden de mandaatregeling en willen wijzigingen sluitend kunnen vastleggen en deployen. HR-medewerkers verwerken personeelsmutaties en willen dat rol-toewijzingen automatisch leiden tot de juiste bevoegdheden zonder handmatige mandaatupdates. Mandaatcoordinatoren binnen afdelingen onderhouden de specifieke mandaten van hun afdeling en zien dashboards van mandaat-gebruik. Zaakbehandelaars willen realtime weten of ze bevoegd zijn en bij twijfel een werkende escalatieroute hebben. Afdelingshoofden en teamleiders fungeren als mandaathouders en willen efficient escalaties kunnen afhandelen. Auditors en accountants reviewen de mandaat-trail bij jaarrekening-controles en willen onomstotelijk kunnen vaststellen dat besluiten door bevoegden zijn genomen.

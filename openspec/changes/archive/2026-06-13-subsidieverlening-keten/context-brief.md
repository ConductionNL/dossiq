---
status: draft
---
# Subsidieverlening-keten

## Purpose

The `subsidieverlening-keten` capability extends procest with end-to-end subsidy-grant lifecycle management for Dutch government grant-making bodies (gemeenten, provincies, ministeries, agentschappen, fondsen, en private vermogensfondsen die zich aan de governance-richtlijnen van het FIN willen conformeren). Subsidy issuance is a fundamentally different administrative process from permitting (VTH) — it commits public money over multi-year horizons (vaak 2-5 jaar, soms 7 jaar voor langlopende onderzoeks- of infrastructuurtrajecten), requires periodic substantiation of how the money was spent, supports advance disbursement (voorschotten conform AWB 4:95) followed by final-settlement (vaststelling conform AWB 4:46), and may end in clawback (terugvordering conform AWB 4:57) if the grantee failed to meet conditions. The Algemene wet bestuursrecht (AWB) titel 4.2 sets the legal framework; sector-specific regelingen (e.g. ASV gemeenten, Kaderwet subsidies OCW, Regeling Europese EZK- en LNV-subsidies, Subsidieregeling instituten OCW, Subsidieregeling sport, ZonMW-regelingen, NWO-regelingen) layer on top with their own termijnen, rapportage-cycli, en accountantsverklaring-drempels.

Existing Nextcloud and zaaksysteem implementations treat a subsidy as a generic case. That collapses the lifecycle into a single zaak with ad-hoc statuses, loses the multi-year horizon, and provides no native support for tussenrapportages, vaststelling, or terugvordering. The result is that finance, jurists, and beleidsmedewerkers track subsidies in parallel spreadsheets, lose visibility on bewijsstukken, miss AWB-termijnen on tussenbeschikkingen, and cannot produce the openbaar subsidieregister required under the Wet open overheid.

This capability provides a coherent state machine that spans aanvraag, beoordeling, beschikking, uitvoering (which may span years), tussenrapportage(s), vaststelling, and optional terugvordering, with each phase modelled as a typed sub-process and linked bewijsstukken store. It produces a Wet open overheid-ready subsidieregister feed, drives AWB-termijnbewaking through the shared termijnbewaking engine, and integrates with the financial back-office for voorschot disbursement and nacalculatie. The capability is explicitly distinct from VTH-vergunningverlening — subsidies are about money out, conditional on substantiation; vergunningen are about activity authorization, conditional on rule-compliance.

## Data Model

The capability introduces eight schemas in the `procest-subsidie` register, all extending procest base entities where appropriate. `SubsidieRegeling` is the policy-level definition (regeling-naam, juridische grondslag, plafond, looptijd, doelgroep, beoordelingscriteria-template, tussenrapportage-frequentie). `SubsidieAanvraag` extends procest `Zaak` and adds aangevraagd-bedrag, project-startdatum, project-einddatum (which may be years in the future), begroting (gestructureerd als kostenposten), cofinanciering, en aanvrager (with KvK or BSN reference). `SubsidieBeoordeling` captures the inhoudelijke en financiële toets, scorings volgens regeling-criteria, advies, en advies-onderbouwing.

`SubsidieBeschikking` is the formal granting decision and is the pivot of the model — it carries verleend-bedrag (may differ from aangevraagd), looptijd (start- en einddatum, often meerjarig), voorschot-schema (lijst van geplande voorschotbetalingen met datum en bedrag), verplichtingen (lijst van voorwaarden, bijv. minimaal aantal deelnemers, verplichte cofinanciering, rapportageritme), wettelijke grondslag, bezwaartermijn-einde, and trekt eventuele eerdere beschikking in. A beschikking kan een verleningsbeschikking, wijzigingsbeschikking, of vaststellingsbeschikking zijn.

`SubsidieUitvoering` is de lopende-fase entiteit en bevat references naar alle tussenrapportages, ingediende bewijsstukken, betaalde voorschotten (met betaal-id voor reconciliatie met financieel systeem), en de actuele subsidie-status (verleend, in-uitvoering, tussenrapportage-ontvangen, tussenrapportage-beoordeeld, vaststelling-aangevraagd, vastgesteld, terugvordering-gestart, afgerond). `Tussenrapportage` is een typed sub-zaak waarbij de subsidie-ontvanger inhoudelijke voortgang en financiële verantwoording indient; deze triggert een eigen beoordelings-proces en kan leiden tot bijstelling van de beschikking.

`SubsidieVaststelling` is de eindverantwoording — werkelijke kosten, realisatie van de verplichtingen, accountantsverklaring (voor subsidies boven €125.000 verplicht volgens Kaderregeling), en het eindrapport. De vaststellingsbeschikking bepaalt het definitieve subsidiebedrag; bij lager-dan-verleend volgt automatisch een terugvorderings-trigger voor het verschil. `Terugvordering` is een aparte zaak die de invordering van te veel uitgekeerde voorschotten regelt — inclusief betaalherinneringen, eventuele invorderingsrente conform Awb, en aansluiting op het deurwaarders-traject indien nodig.

`Bewijsstuk` is een polymorf attachment-type dat aan elke fase kan hangen — aanvraagdocument, begroting, projectplan, cofinancieringsverklaring, voortgangsrapport, urenstaat, factuur, bankafschrift, accountantsverklaring, eindrapport — met type-specifieke validatieregels en bewaartermijn-koppeling voor archivering. Alle entiteiten dragen audit-trail (wie/wanneer/wat) en zijn gekoppeld aan procest `Behandelaar` en `Organisatieonderdeel`.

## Requirements

### REQ-SUB-001: Multi-year beschikking with voorschot-schema

The system MUST support beschikkingen with a looptijd spanning multiple years and a voorschot-schema of zero or more scheduled disbursements. Each voorschot has a planned date, amount, and condition (e.g. "after Q2 tussenrapportage approved").

- GIVEN a beschikking is being drafted with looptijd 2026-01-01 to 2028-12-31 and verleend-bedrag €450.000
- WHEN the behandelaar adds a voorschot-schema of three €120.000 yearly advances plus a €90.000 nabetaling op vaststelling
- THEN the system MUST validate dat the total scheduled disbursements equal the verleend-bedrag and reject the beschikking if not

- GIVEN a beschikking is verleend with a voorschot scheduled for 2027-01-15 conditional on Q4-2026 tussenrapportage
- WHEN the scheduled date arrives but the tussenrapportage is not yet beoordeeld
- THEN the system MUST NOT trigger the disbursement signal and MUST notify the behandelaar that the condition is unmet

- GIVEN a voorschot is approved for disbursement
- WHEN the system signals the financial back-office via the betalings-integration
- THEN the system MUST record the disbursement reference, expected payment date, and mark the voorschot status as "in betaling"

### REQ-SUB-002: AWB termijn-binding for each phase

Every phase of the keten MUST be bound to its AWB-prescribed decision termijn via the shared termijnbewaking engine. Default termijnen are 8 weeks for beschikking op aanvraag (AWB 4:13), 22 weeks for complex regelingen, and regelingsspecifieke termijnen for tussenrapportage en vaststelling.

- GIVEN a SubsidieAanvraag is registered onder regeling "Innovatiefonds 2026"
- WHEN the aanvraag wordt geregistreerd
- THEN the system MUST create a termijn-counter with the regeling-specific termijn (default 13 weeks) en MUST link it to the termijnbewaking engine

- GIVEN a vaststellings-aanvraag is ingediend
- WHEN the system registreert deze
- THEN the system MUST start een nieuwe 22-week beoordelings-termijn voor de vaststellingsbeschikking

- GIVEN a termijn is approaching expiration with less than two weeks remaining
- WHEN the daily termijn-scan runs
- THEN the system MUST notify the behandelaar AND the teamleider via the configured notification channel

### REQ-SUB-003: Verplichtingen-tracking and substantiation

Each verplichting in een beschikking MUST be trackable with a status (open, in-uitvoering, voldaan, niet-voldaan), required bewijsstukken, deadline, and link naar de fase waarin het wordt verantwoord (tussenrapportage of vaststelling).

- GIVEN a beschikking has a verplichting "minimaal 50 deelnemers in jaar 1, te bewijzen met deelnemerslijst"
- WHEN the subsidie-ontvanger submits a tussenrapportage met deelnemerslijst attached
- THEN the system MUST surface the verplichting and matching bewijsstuk to the beoordelaar in een single pane

- GIVEN a verplichting blijft op status "niet-voldaan" bij vaststelling
- WHEN the vaststellings-beslissing wordt voorbereid
- THEN the system MUST automatisch flag deze als korting-grond op het definitieve subsidiebedrag en MUST require the behandelaar to record a explicit decision (lower vaststelling vs. waiver met motivering)

### REQ-SUB-004: Tussenrapportage as typed sub-zaak

Tussenrapportages MUST be modelled as typed sub-zaken with their own behandelproces, eigen termijn, eigen bewijsstukken, en eigen beoordelings-uitkomst. Multiple tussenrapportages per beschikking MUST be supported (jaarlijks, halfjaarlijks, op mijlpaal).

- GIVEN a beschikking heeft een tussenrapportage-frequentie "jaarlijks per kalenderjaar"
- WHEN the year change passes
- THEN the system MUST automatisch create een Tussenrapportage-zaak in status "verwacht" en notify the subsidie-ontvanger via the configured portal channel

- GIVEN a tussenrapportage is ingediend door de aanvrager
- WHEN de behandelaar het beoordeelt en goedkeurt
- THEN the system MUST update de SubsidieUitvoering status en MUST trigger eventuele voorwaardelijke voorschotten die afhankelijk waren van deze rapportage

### REQ-SUB-005: Vaststelling met optional terugvordering

The vaststellings-procedure MUST compute het verschil tussen totaal-uitgekeerd voorschotten en het definitief vastgestelde subsidiebedrag, en MUST automatisch een Terugvorderings-zaak openen als het verschil positief is.

- GIVEN a beschikking with verleend €450.000, three voorschotten van €120.000 reeds uitgekeerd, en een vaststellings-beoordeling van €330.000 (€30.000 lager dan voorschotten-totaal)
- WHEN the vaststellingsbeschikking wordt geslagen
- THEN the system MUST automatisch een Terugvordering-zaak openen voor €30.000 met de wettelijke grondslag, de standaard 6-weeks bezwaartermijn, en de eerste betaaltermijn op standaard 4 weeks

- GIVEN a terugvordering remains onbetaald na de bezwaartermijn en eerste betaaltermijn
- WHEN de invorderingstermijn verstrijkt
- THEN the system MUST de invorderingsrente conform Awb 4:97 berekenen vanaf de oorspronkelijke betaaldatum en aan de terugvordering toevoegen

### REQ-SUB-006: Subsidieregister-publication feed

The system MUST expose alle verleende, lopende, en vastgestelde subsidies via een gestructureerde JSON feed conform de Wet open overheid en de standaard voor subsidieregisters van VNG, voor publicatie op de gemeentewebsite of het centraal register.

- GIVEN een beschikking is onherroepelijk (bezwaartermijn verstreken zonder bezwaar)
- WHEN het dagelijkse register-publication job runt
- THEN the system MUST de subsidie opnemen in de feed met regeling, ontvanger (rechtspersoon of in geval van particulieren geanonimiseerd conform AVG-richtlijn VNG), bedrag, looptijd, en doel

- GIVEN een vaststellingsbeschikking is genomen
- WHEN het register-publication job runt
- THEN the system MUST het definitieve bedrag opnemen en de status updaten naar "vastgesteld"

### REQ-SUB-007: Bewijsstukken-management with bewaartermijn

Alle bewijsstukken MUST be linked aan hun bron-fase, type, en bewaartermijn conform Selectielijst gemeenten en provincies of sector-specifieke regeling, met automatische archief-trigger via de docudesk-integration.

- GIVEN een Bewijsstuk wordt geüpload bij een tussenrapportage
- WHEN het document wordt opgeslagen
- THEN the system MUST automatisch het document-type detecteren of de gebruiker laten kiezen uit een whitelist, en de bewaartermijn afleiden uit de regeling-configuratie

- GIVEN een subsidie-zaak is afgerond en de bewaartermijn is bereikt
- WHEN de archief-trigger draait
- THEN the system MUST de bewijsstukken-bundel inclusief metadata overdragen aan de docudesk archief-handover

### REQ-SUB-008: Cofinanciering en EU-staatssteun checks

Voor subsidies met cofinanciering of mogelijke EU-staatssteunimplicaties (de-minimis, AGVV, etc.) MUST het system de relevante checks ondersteunen en de juiste declarations vastleggen.

- GIVEN een aanvraag voor een bedrag boven de de-minimis drempel (€300.000 per drie jaar per onderneming)
- WHEN de behandelaar de aanvraag in toets neemt
- THEN the system MUST een verplicht veld tonen voor de staatssteun-rechtsgrond (de-minimis, AGVV-artikel, of notificatieplicht) en MUST de eerdere de-minimis-meldingen van dezelfde ontvanger ophalen via de zoek-functie

- GIVEN een beschikking valt onder AGVV
- WHEN de beschikking wordt verleend
- THEN the system MUST de AGVV-melding genereren in het juiste format voor publicatie op de TAM-register

### REQ-SUB-009: Wijzigingsbeschikking workflow

Het system MUST wijzigingsbeschikkingen ondersteunen die een bestaande beschikking aanpassen (bedrag, looptijd, verplichtingen) met behoud van audit-trail naar de oorspronkelijke beschikking.

- GIVEN een subsidie-ontvanger vraagt een verlenging van de projectperiode aan
- WHEN de behandelaar een wijzigingsbeschikking voorbereidt
- THEN the system MUST de oorspronkelijke beschikking als basis pakken, het diff tonen, en de wijzigingsbeschikking koppelen aan de oorspronkelijke via een trekt-in / wijzigt-relatie

- GIVEN een wijzigingsbeschikking is onherroepelijk
- WHEN deze ingaat
- THEN the system MUST de SubsidieUitvoering bijwerken naar de nieuwe condities en MUST de voorschot-schema en eventuele tussenrapportage-frequentie herberekenen

### REQ-SUB-010: Reporting and dashboards

Het system MUST een set standaard rapportages produceren voor management en accountantscontrole — totaal verleend per regeling per jaar, openstaande voorschotten, lopende terugvorderingen, overschreden termijnen, en accountantsverklaringen-status — exporteerbaar in CSV en PDF.

- GIVEN het einde van een kwartaal nadert
- WHEN de financieel controller het kwartaalrapport opvraagt
- THEN the system MUST een PDF leveren met totaal-verleend, totaal-uitgekeerd, totaal-vastgesteld, openstaande verplichtingen, en lopende terugvorderingen per regeling

- GIVEN een accountant doet een steekproef-controle
- WHEN deze een sample van 30 dossiers exporteert
- THEN the system MUST een ZIP-export leveren met per dossier de beschikking, alle bewijsstukken, en de audit-trail

## Standards & Sources

The capability is grounded in de Algemene wet bestuursrecht titel 4.2 (subsidies), met name de afdelingen 4.2.1 (algemene bepalingen), 4.2.2 (subsidieverlening), 4.2.3 (verplichtingen van de subsidie-ontvanger), 4.2.5 (vaststelling), 4.2.6 (intrekking en wijziging), en 4.2.8 (per boekjaar verstrekte subsidies aan rechtspersonen). Daarnaast geldt de Kaderwet subsidies van de relevante sectorale departementen (Kaderwet OCW-subsidies, Kaderwet EZK- en LNV-subsidies, Kaderwet VWS-subsidies, Kaderwet SZW-subsidies, Kaderwet I&W-subsidies, Kaderwet BZK-subsidies, Kaderwet JenV-subsidies), de Comptabiliteitswet 2016 (voor rijksoverheid), en de Financiële-verhoudingswet (voor gemeentelijke en provinciale verdeling). De VNG-modelverordening Algemene Subsidieverordening (ASV), het VNG-Kader Financieel beheer subsidies, en de Aanwijzingen voor subsidieverstrekking (Aanwijzing 12 voor de rijksdienst) leveren de implementatie-templates. Voor EU-staatssteun wordt verwezen naar de AGVV (Algemene Groepsvrijstellingsverordening 651/2014), de de-minimisverordening (1407/2013, met de geüpdatete drempel van €300.000 per drie jaar per onderneming sinds 2024), de DAEB-vrijstelling (Diensten van Algemeen Economisch Belang, Besluit 2012/21/EU), en de aanmeldingsplicht conform artikel 108 VWEU voor niet-vrijgestelde steun. Voor het subsidieregister is de Wet open overheid leidend met artikel 3.3 lid 2 onder f (verplichte actieve openbaarmaking van subsidie-beschikkingen), met de VNG-richtlijn subsidieregister en de informatiecategorieën-handreiking van het ministerie van BZK als implementatiestandaard. Voor archivering wordt aangesloten op de Selectielijst gemeenten en intergemeentelijke organen (2020, categorieën 4.x voor subsidie), de selectielijsten van de rijksoverheid, en de Archiefwet 1995 / Archiefregeling. Termijnen volgen AWB 4:13 (8 weken default), regelingsspecifieke uitzonderingen, en de termijnverlengingsruimte van AWB 4:14. Voor accountantsverklaringen wordt het Controleprotocol Single information Single audit (SiSa) voor specifieke uitkeringen en de NBA-handreiking 1117 voor subsidie-controles aangehouden.

## Cross-app integration

The capability depends on procest base (zaak-engine, behandelaar-model, organisatieonderdeel-model, status-machine, document-store, notification-router), openregister (schema-registratie, audit-trail, search-faceting voor portfolio-overzichten, event-bus voor status-transities), termijnbewaking-dwangsom-engine (AWB-termijnen, ingebrekestelling-pad, dwangsom-staffel bij niet tijdig beslissen op subsidie-aanvragen), en docudesk voor bewijsstukken-archief-handover en PDF/A-conversie van beschikkingen en accountantsverklaringen. Het levert een subsidieregister-feed die door opencatalogi of een nldesign-portal kan worden geconsumeerd voor publicatie conform Wet open overheid. Voor financiële integratie wordt een generiek betalings-event geëmitteerd dat door openconnector aan ERP-systemen (Coda Financials, Centric Key2Finance, Civision Middelen, Unit4 Wholesale, AFAS Profit) kan worden gekoppeld; de retour-flow (betaling-bevestigd, betaling-gefaald) komt via dezelfde openconnector-koppeling terug. Voor bezwaar-procedures kan een doorzet naar de procest-bezwaar capability worden gemaakt, waarbij de bezwaartermijn-counter automatisch via de termijnbewaking-engine wordt gestart op de dag-na-bekendmaking. Voor de AGVV/TAM-melding wordt openconnector ingezet als integratie-laag naar het centrale register van het ministerie van EZK. Voor terugvordering kan via openconnector worden gekoppeld aan een deurwaarders-traject (Cannock Chase, GGN, et cetera). Notificaties naar subsidie-ontvangers lopen via het standaard procest portal-channel met fallback op e-mail en — voor particulieren — DigiD-berichtenbox. Voor reporting naar het CBS (Statistiek Subsidies) en naar de provinciaal toezichthouder wordt een dedicated export-endpoint geleverd dat door opencatalogi of een launchpad-dashboard kan worden geconsumeerd. AI-companion-integratie via ADR-019 levert behandelaren een chat-assistent die kan adviseren over regeling-toepasbaarheid, AGVV-classificatie, en standaard motiveringen voor beschikkingen op basis van eerdere besluiten.

## Target users

Primaire gebruikers zijn subsidiebehandelaren binnen gemeenten, provincies, ministeries, uitvoerings-agentschappen (RVO, Dus-I, ZonMW, NWO, DUS, SNN, OP-Oost, OP-Zuid, OP-West), en zelfstandige bestuursorganen die met enkel- of meerjarige subsidies werken. Secundaire gebruikers zijn financieel controllers (voor voorschotten, vaststelling, en aansluiting op de gemeentelijke begroting), juristen (voor beschikking-kwaliteit, bezwaar-verweer, en EU-staatssteun-toetsing), beleidsmedewerkers (voor regeling-monitoring, doelbereik-rapportages, en effect-evaluatie), accountants (voor jaarcontrole inclusief SiSa-bijlage en NBA-1117-conforme controles), management en bestuurders (voor portfolio-overzicht en politieke verantwoording), en de gemeenteraad of Provinciale Staten (voor toezicht op subsidie-uitvoering via openbare subsidieregisters). Subsidie-ontvangers — variërend van eenmanszaken en stichtingen tot grote uitvoeringsorganisaties — gebruiken het portaal-deel voor aanvragen, tussenrapportages, en vaststellings-aanvragen, en kunnen de status van hun dossier real-time volgen. Het system is bewust opgezet zodat ook kleine fondsen en stichtingen — niet alleen overheidsorganen — het kunnen inzetten voor hun grant-making, omdat de AWB-naleving optioneel kan worden uitgeschakeld voor private fondsen die hun eigen statuten volgen. Voor onderzoeksjournalisten en wetenschappers levert het openbare subsidieregister een waardevolle dataset voor onderzoek naar overheidsfinanciering. Voor de Rekenkamer en de Algemene Rekenkamer geeft de gestructureerde audit-trail per dossier een directe basis voor doelmatigheid- en rechtmatigheid-onderzoeken zonder dat aparte data-extracties bij gemeenten hoeven te worden opgevraagd.

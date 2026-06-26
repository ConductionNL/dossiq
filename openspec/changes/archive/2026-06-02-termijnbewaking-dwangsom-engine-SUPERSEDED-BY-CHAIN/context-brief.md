---
status: draft
---
# Termijnbewaking-dwangsom-engine

## Purpose

The `termijnbewaking-dwangsom-engine` capability provides a single, auditable, stateful counter for wettelijke beslis-termijnen across every procest-driven decision and the automatic Wet dwangsom-bij-niet-tijdig-beslissen consequences that follow termijn-overschrijding. Dutch administrative law (AWB hoofdstuk 4, titel 4.1.3 en titel 4.1.3a) imposes strict deadlines on bestuursorganen for taking a beschikking on an aanvraag, and the Wet dwangsom en beroep bij niet tijdig beslissen (in werking 2009-10-01) gives the burger an automatic geldelijke vergoeding if the bestuursorgaan misses the deadline after a formal ingebrekestelling.

In practice, gemeenten and other bestuursorganen run these termijnen by hand or in spreadsheets, miss them more often than they admit, and pay out dwangsommen totalling several million euros per year fleet-wide. The Nationale ombudsman has repeatedly flagged this as a structural issue. Existing zaaksystemen carry a simple "fataledatum" field with no awareness of pauze-en-verlengings-gronden (e.g. AWB 4:14 verlenging, 4:15 opschorting wegens onvolledige aanvraag, 4:5 hersteltermijn), no integration with the ingebrekestelling-pad, and no automated computation of de cumulatieve dwangsom volgens de wettelijke staffel.

This capability provides a domain-aware termijn-engine that knows the standaard AWB-termijn (8 weken), de regelingsspecifieke termijnen (26 weken Wabo, 6 maanden Omgevingswet uitgebreid, 13 weken Wmo, etc.), de pauze- en verlengings-gronden met hun maximale duur en hun bewijslast, en de exacte dwangsom-staffel uit AWB 4:17 (€23/dag week 1-2, €35/dag week 3-4, €45/dag week 5-6, maximum €1.442 per beschikking, na de tweede week van ingebrekestelling). It surfaces upcoming deadlines pro-actively, computes dwangsommen automatically when termijnen are overschreden, and produces both the burger-notificatie and the financial-system signal voor uitbetaling. The engine is consumed by every procest capability — VTH-vergunningen, subsidieverlening, klachten, Woo-verzoeken, bezwaarschriften — through a single API.

## Data Model

The capability introduces six schemas in the `procest-termijn` register. `TermijnDefinitie` is the regeling-level configuration: zaaktype-key, wettelijke grondslag (AWB-artikel of sectorale wet), standaard-duur in dagen of weken, default-verlengingsruimte, en eventueel een afwijkende dwangsom-regime (sommige wetten zoals Wob/Woo hebben afwijkende termijnen en sancties). `TermijnInstance` is per concrete zaak — start-datum, einddatum-berekend, einddatum-actueel (na pauzes en verlengingen), status (lopend, gepauzeerd, verlengd, voltooid, overschreden), en een lijst van TermijnGebeurtenissen.

`TermijnGebeurtenis` is de audit-eenheid: type (start, pauze, hervat, verleng, voltooi, ingebrekestelling-ontvangen, overschreden, dwangsom-gestart), tijdstip, actor, grondslag (AWB-artikel of regeling), motivering, bijbehorende dagen-impact, en optioneel een document-link (bijv. hersteltermijn-brief). Deze events vormen de complete reconstructie van hoe de termijn tot zijn huidige stand kwam, wat essentieel is voor verweer bij een dwangsom-discussie.

`Ingebrekestelling` legt de formele aanmaning vast die de burger MUST geven voordat de dwangsom-staffel kan starten (AWB 4:17 lid 3): ontvangst-datum (deze datum is de pivot — twee weken later begint de dwangsom-loop), kanaal (post, e-mail, portaal), het document zelf, en een verwijzing naar de TermijnInstance. Een TermijnInstance kan meerdere ingebrekestellingen krijgen maar maar één is rechtens relevant (de eerste geldige na termijn-overschrijding).

`DwangsomBerekening` is de stateful counter die per dag (of na een terugkijk-batch) de cumulatieve dwangsom voor een case berekent: start-datum (datum-ingebrekestelling + 14 dagen), week-binnen-staffel, dagelijks-tarief, dag-loop, cumulatief-bedrag, plafond-bereikt-vlag (€1.442 max), en status (lopend, gestopt-wegens-beschikking, betaald). `DwangsomUitbetaling` is de financiële uitkeer-trigger: bedrag, IBAN van burger, betaal-referentie, status (in-betaling, betaald, gefaald), en bron-DwangsomBerekening. Alle entiteiten zijn append-only voor audit-purposes; correcties gebeuren via compenserende events.

## Requirements

### REQ-TERM-001: Termijn-binding per zaaktype

Every zaak-creation in procest MUST trigger a corresponding TermijnInstance based on the matching TermijnDefinitie for its zaaktype. The engine MUST refuse to create a TermijnInstance if no matching definitie exists, forcing explicit configuration.

- GIVEN een gemeente heeft TermijnDefinities geconfigureerd voor "omgevingsvergunning-regulier" (8 weken Wabo) en "wmo-aanvraag" (8 weken Wmo) maar niet voor "horeca-exploitatievergunning"
- WHEN een nieuwe horeca-exploitatievergunning-zaak wordt aangemaakt
- THEN the system MUST de zaak-creatie blokkeren met een duidelijke melding voor de beheerder en een link naar de TermijnDefinitie-configuratie

- GIVEN een zaak van type "omgevingsvergunning-regulier" wordt geregistreerd op 2026-06-01
- WHEN de TermijnInstance wordt aangemaakt
- THEN MUST de einddatum-berekend op 2026-07-27 staan (8 weken vanaf registratiedatum, conform AWB) en MUST een start-event in de audit-trail staan

### REQ-TERM-002: Pauze wegens onvolledige aanvraag (AWB 4:5/4:15)

Het system MUST een hersteltermijn-pauze ondersteunen die de termijn stopt op het moment van het verzoek-om-aanvulling en hervat op het moment van ontvangst van de aanvulling, of doorpauzeert tot de gestelde hersteltermijn verstrijkt.

- GIVEN een TermijnInstance loopt sinds 2026-06-01 met einddatum 2026-07-27, en de behandelaar verstuurt op 2026-06-10 een verzoek-om-aanvulling met een hersteltermijn van 14 dagen
- WHEN de behandelaar deze actie in procest registreert
- THEN the system MUST een pauze-event registreren op 2026-06-10, de status op "gepauzeerd" zetten, en de einddatum-actueel met het aantal pauze-dagen verlengen zodra de pauze eindigt

- GIVEN de burger reageert op 2026-06-18 met de aanvulling
- WHEN de behandelaar de aanvulling ontvangstdatum registreert
- THEN the system MUST een hervat-event registreren met 8 pauze-dagen, de einddatum-actueel verschuiven naar 2026-08-04, en de status terug naar "lopend" zetten

- GIVEN de burger reageert niet binnen de hersteltermijn van 14 dagen
- WHEN de hersteltermijn-deadline verstrijkt zonder reactie
- THEN the system MUST de behandelaar pro-actief notificeren met het advies om de aanvraag buiten behandeling te stellen (AWB 4:5)

### REQ-TERM-003: Verlenging volgens AWB 4:14

Het system MUST een eenmalige verlenging met een redelijke termijn ondersteunen (AWB 4:14 lid 3), waarbij de motivering verplicht is en de verlengingsbrief aan de aanvrager wordt vastgelegd.

- GIVEN een TermijnInstance loopt en de behandelaar wil verlengen
- WHEN de behandelaar een verlengings-actie initieert met motivering "complex dossier, advies derden nodig" en nieuwe einddatum 6 weken later
- THEN the system MUST de verlenging valideren (motivering aanwezig, nieuwe einddatum binnen redelijke termijn, geen eerdere verlenging dezelfde TermijnInstance), het event registreren, de einddatum-actueel bijwerken, en een verlengingsbrief-trigger emitteren

- GIVEN een TermijnInstance is al een keer verlengd
- WHEN de behandelaar een tweede verlenging probeert
- THEN the system MUST de tweede verlenging weigeren met een verwijzing naar AWB 4:14, tenzij er een uitzonderlijke regeling-grondslag wordt aangevoerd

### REQ-TERM-004: Pro-actieve notificaties bij naderende deadlines

Het system MUST elke dag scannen voor TermijnInstances waar de einddatum-actueel binnen een configureerbare drempel ligt (default 14 dagen, 7 dagen, 2 dagen) en escalaties versturen volgens een te configureren escalatie-matrix.

- GIVEN een TermijnInstance heeft einddatum-actueel over 13 dagen
- WHEN de dagelijkse termijn-scan draait
- THEN the system MUST de behandelaar notificeren via de geconfigureerde primaire channel (Nextcloud notificatie + e-mail)

- GIVEN een TermijnInstance heeft einddatum-actueel over 1 dag
- WHEN de dagelijkse scan draait
- THEN the system MUST de behandelaar EN de teamleider EN de afdelingsmanager notificeren met een rode-vlag prioriteit

- GIVEN een TermijnInstance is overschreden
- WHEN de scan draait
- THEN the system MUST de status op "overschreden" zetten, een overschreden-event registreren, en een waarschuwing in de zaak-detail-UI tonen dat een ingebrekestelling kan volgen

### REQ-TERM-005: Ingebrekestelling-registratie en validatie

Het system MUST een formele ingebrekestelling kunnen registreren met validatie dat de termijn daadwerkelijk is overschreden op het moment van ontvangst (anders is de ingebrekestelling premaat en geen dwangsom-grondslag).

- GIVEN een TermijnInstance is overschreden sinds 5 dagen, en de burger dient een ingebrekestelling in
- WHEN de behandelaar deze registreert met ontvangst-datum
- THEN the system MUST valideren dat de termijn op de ontvangst-datum daadwerkelijk was overschreden, een Ingebrekestelling-record aanmaken, en een DwangsomBerekening voorbereiden die over 14 dagen begint

- GIVEN de burger dient een ingebrekestelling in vóór de termijn is overschreden
- WHEN de behandelaar deze registreert
- THEN the system MUST deze als "premaat" markeren, geen DwangsomBerekening starten, en de behandelaar adviseren om de burger te informeren dat een nieuwe ingebrekestelling nodig is na termijn-overschrijding

### REQ-TERM-006: Dwangsom-staffel berekening volgens AWB 4:17

Het system MUST de dwangsom dagelijks berekenen volgens de exacte staffel: €23 per dag voor de eerste 14 dagen na de twee-weken-grace, €35 per dag voor de volgende 14 dagen, €45 per dag voor de daarna volgende 14 dagen, met een absoluut maximum van €1.442 per beschikking.

- GIVEN een Ingebrekestelling is geregistreerd op 2026-06-15, de bestuurszaak is op 2026-07-10 nog steeds niet beslist
- WHEN de dagelijkse DwangsomBerekening draait op 2026-07-10
- THEN MUST de cumulatieve dwangsom €230 bedragen (10 dagen × €23, want grace eindigt 2026-06-29, en 2026-07-10 is dag 11 sinds grace-einde — laatste actieve dag is 10 dagen × €23)

- GIVEN een DwangsomBerekening loopt al 40 dagen
- WHEN de dagelijkse berekening draait
- THEN MUST de cumulatieve dwangsom €1.442 zijn (plafond bereikt: 14×€23 + 14×€35 + 12×€45 = 322 + 490 + 540 = 1.352, maar bij 13 dagen op €45 wordt het plafond geraakt) en MUST de plafond-bereikt-vlag op true staan zodat verdere groei stopt

- GIVEN de bestuurszaak wordt alsnog beslist op dag 20 sinds grace-einde
- WHEN de behandelaar de beschikking registreert
- THEN MUST de DwangsomBerekening op status "gestopt-wegens-beschikking" gaan, het definitieve bedrag (€23×14 + €35×6 = €322 + €210 = €532) worden vastgesteld, en een uitkeer-trigger worden geëmitteerd

### REQ-TERM-007: Uitbetaling-signaal aan financieel systeem

Het system MUST bij stop van de DwangsomBerekening (door beschikking, plafond, of intrekking) een gestructureerd betaal-signaal emitteren met alle gegevens voor uitkering, voor consumptie door een ERP via openconnector.

- GIVEN een DwangsomBerekening sluit af met definitief bedrag €532, en de IBAN van burger is bekend uit de aanvraag
- WHEN het uitkeer-event wordt gegenereerd
- THEN MUST het signaal het bedrag, IBAN, naam-rekeninghouder, betaalkenmerk (referentie naar zaak en ingebrekestelling), wettelijke grondslag, en betaaldatum-uiterlijk (4 weken) bevatten

- GIVEN het financieel systeem bevestigt de uitbetaling via de openconnector-callback
- WHEN het system de bevestiging verwerkt
- THEN MUST een DwangsomUitbetaling-status-update plaatsvinden naar "betaald" met de betalingsreferentie en de werkelijke betaaldatum

### REQ-TERM-008: Burger-notificatie van termijn-events

Het system MUST de burger pro-actief informeren bij relevante termijn-events: ontvangst-bevestiging met termijn-toezegging, verlengingsbrief, ingebrekestelling-ontvangstbevestiging, dwangsom-toekenning, en uitbetaling-bevestiging.

- GIVEN een aanvraag is geregistreerd
- WHEN de TermijnInstance wordt aangemaakt
- THEN MUST een ontvangstbevestiging worden gegenereerd met de wettelijke termijn, de berekende einddatum, en een verwijzing naar het portaal-traject voor statusupdates

- GIVEN een ingebrekestelling is geregistreerd
- WHEN het registratie-event wordt verwerkt
- THEN MUST een ontvangstbevestiging worden gegenereerd met de bevestiging dat de dwangsom-loop over 14 dagen start als de beschikking dan nog niet is genomen

### REQ-TERM-009: Reporting voor management en accountant

Het system MUST rapportages produceren over termijn-performance per zaaktype, per afdeling, per behandelaar, en over uitgekeerde dwangsommen voor jaarrekening en accountantscontrole.

- GIVEN een afdelingshoofd vraagt het kwartaalrapport voor zijn afdeling
- WHEN het rapport wordt gegenereerd
- THEN MUST het rapport per zaaktype tonen: totaal-zaken, percentage-binnen-termijn, gemiddelde-doorlooptijd, aantal-verlengingen, aantal-overschrijdingen, aantal-ingebrekestellingen, totaal-uitgekeerde-dwangsom

- GIVEN de accountant vraagt het jaaroverzicht dwangsommen voor de jaarrekening
- WHEN het rapport wordt opgevraagd
- THEN MUST een gestructureerde lijst worden geproduceerd met per dwangsom: zaak-referentie, ingebrekestelling-datum, beschikking-datum, dwangsom-bedrag, uitbetalings-datum, en betalingsreferentie

### REQ-TERM-010: Bezwaar-handling tegen dwangsom-beschikking

Het system MUST kunnen omgaan met bezwaar tegen de toekenning of hoogte van een dwangsom (AWB 4:18 voorziet dat de hoogte een beschikking is waartegen bezwaar mogelijk is) door de DwangsomBerekening te bevriezen en de uitbetaling te pauzeren tot het bezwaar is afgehandeld.

- GIVEN een DwangsomBerekening is afgesloten en wacht op uitbetaling, en de burger of bestuursorgaan dient bezwaar in tegen de hoogte
- WHEN het bezwaar wordt geregistreerd
- THEN MUST de uitbetaling worden gepauzeerd, een bezwaar-event op de berekening worden vastgelegd, en de bezwaartermijn-counter worden gestart

- GIVEN het bezwaar wordt gegrond verklaard met een herziene hoogte
- WHEN de heroverweging wordt geregistreerd
- THEN MUST een correctie-event worden vastgelegd, de DwangsomBerekening worden bijgewerkt naar het nieuwe definitieve bedrag, en de uitbetaling worden hervat met het herziene bedrag

## Standards & Sources

De engine is gegrond op de Algemene wet bestuursrecht (titel 4.1.3 beslis-termijnen, titel 4.1.3a Wet dwangsom bij niet tijdig beslissen, AWB 4:13 t/m 4:18, AWB 4:5/4:14/4:15 voor pauze- en verlengings-gronden, AWB 4:97 voor wettelijke rente), de Wet dwangsom en beroep bij niet tijdig beslissen (Stb. 2009, 383, in werking 2009-10-01), en sectorale termijn-regelingen waaronder de Wabo (omgevingsvergunning regulier 8 weken, uitgebreid 26 weken — straks Omgevingswet artikel 16.64), de Omgevingswet (sinds 2024-01-01, met termijnen via Bal/Bbl), de Wmo 2015 (artikel 2.3.5: 6 weken voor onderzoek, 2 weken voor beschikking), de Participatiewet, de Wet open overheid artikel 4.4 (4 weken met eenmalige verlenging van 2 weken, afwijkend van standaard AWB), de Vreemdelingenwet 2000 (artikel 25 met verlengde termijnen voor asiel- en regulier-procedure), de Jeugdwet, de Wet langdurige zorg, en de Algemene wet inkomensafhankelijke regelingen (Awir, voor toeslagen). De dwangsom-staffel uit AWB 4:17 lid 2 (€23 / €35 / €45 per dag) is per 2009 wettelijk vastgesteld en geldt onveranderd; het plafond van €1.442 per beschikking is sindsdien niet ge-indexeerd ondanks meerdere ombudsman-aanbevelingen. Voor jurisprudentie wordt aangesloten op de Afdeling bestuursrechtspraak van de Raad van State (lijn-arresten over wat een "redelijke" verlenging is onder 4:14, en over premature ingebrekestellingen) en de Centrale Raad van Beroep voor sociale-zekerheid-termijnen. Voor de Nationale ombudsman richtlijnen wordt verwezen naar het rapport "Termijnoverschrijding" (2019), de "Behoorlijkheidswijzer", en de jaarverslagen waarin termijn-overschrijding consistent als top-3 klachtgrond is geclassificeerd. Voor gemeentelijke ombudslieden worden de richtlijnen van Veneklaas-rapporten gevolgd. Voor archivering volgt de capability de Selectielijst gemeenten en de Archiefwet. Voor management-rapportage wordt aangesloten op de VNG-benchmark "Burgerzaken" en de ENSIA-zelfevaluatie informatiebeveiliging, beide met termijn-prestatie als kpi-element. Het ISO 9001-kwaliteitsmanagement-framework biedt de procedure-vereisten voor termijnbewaking als formeel proces.

## Cross-app integration

The engine depends on procest base (zaak-engine, behandelaar-model, status-machine, document-store, notification-router) en levert termijn-services aan alle procest sub-capabilities — VTH-vergunningen, subsidieverlening-keten, klachten, Woo-verzoeken, bezwaarschriften, planschade, BAG/BGT-mutaties, leerplicht, huisvestingsverordening, jeugdhulp, en alle andere AWB-bestuursrechtelijke beslis-momenten. De engine emit events via de openregister event-bus (termijn-gestart, termijn-naderend-deadline, termijn-overschreden, ingebrekestelling-ontvangen, dwangsom-gestart, dwangsom-gestopt, dwangsom-uitgekeerd) zodat consumers — zoals het procest-dashboard, een launchpad management-cockpit voor termijn-KPI's, een nldesign burgerportal voor dossier-status, of een opencatalogi publicatie-frontend voor transparantie-cijfers — real-time kunnen meelopen. Voor uitbetaling van dwangsommen wordt openconnector ingezet als integratie-laag naar ERP-systemen (Coda Financials, Centric Key2Finance, Civision Middelen, Unit4 Wholesale, AFAS Profit, SAP Public Sector); de retour-flow met betaalbevestiging komt via dezelfde openconnector-koppeling terug en wordt verwerkt in DwangsomUitbetaling-status. De engine consumeert geen externe services maar is wel zelf consumable als losse module door non-procest contexten (bijv. een standalone Wmo-applicatie, een UWV-uitkeringsmodule, of een DUO-studiefinanciering systeem). Notificaties naar burgers lopen via de standaard procest notification-router met fallback op DigiD-berichtenbox (MijnOverheid Berichtenbox) en e-mail. Voor partij-organisaties (bedrijven) wordt eHerkenning gebruikt voor inloggen op de portal. De engine kan optioneel een dagelijkse Statusoverzicht-feed naar de gemeentewebsite leveren voor publicatie van overschrijdings-cijfers (transparantie-richtlijn VNG) of voor de open-data portal van de gemeente. Voor integratie met andere zaaksystemen (Decos JOIN, Roxit Suite4, Centric GWS4all) wordt een StUF-koppelvlak via openconnector voorzien zodat termijn-bewaking ook werkt over zaaksystemen heen tijdens een migratiefase. AI-companion-integratie via ADR-019 geeft behandelaren een chat-assistent die kan adviseren over of een verlengings-grond houdbaar is, of een ingebrekestelling premaat is, en hoe een dwangsom-beschikking te motiveren.

## Target users

Primaire gebruikers zijn behandelaren van aanvragen onder AWB-regime in gemeenten, provincies, ministeries, en uitvoeringsorganisaties (UWV, SVB, DUO, RVO, IND, Belastingdienst, RDW, et cetera) die dagelijks met beslis-termijnen werken. Secundaire gebruikers zijn teamleiders en afdelingshoofden (voor portfolio-monitoring en escalaties), juristen (voor dwangsom-verweer en bezwaar), financieel controllers (voor begroting en jaarrekening), accountants (voor controle uitgekeerde dwangsommen), en de Nationale ombudsman of gemeentelijke ombudsman (voor onderzoek naar termijn-discipline). Burgers krijgen de engine niet direct te zien maar profiteren van pro-actieve notificaties, transparante termijn-toezeggingen, en de zekerheid dat dwangsommen automatisch worden uitgekeerd zonder dat ze meerdere keren moeten aandringen. Voor management is de engine een dashboard-bron: termijn-performance is een KPI in toezichts-rapportages (VNG benchmark, ENSIA).

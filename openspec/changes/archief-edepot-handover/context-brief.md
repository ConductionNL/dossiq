---
status: draft
---
# Archief-edepot-handover

## Purpose

The `archief-edepot-handover` capability provides a verifiable, audit-grade overdracht van afgesloten procest-zaken naar een e-Depot conform de Archiefwet 1995 (en in de toekomst Archiefwet 2024) en de Nederlandse archief-metadatastandaarden TMLO (Toepassingsprofiel Metadatering Lokale Overheden, versie 1.2.1) en het opvolger MDTO (Metagegevens voor Duurzaam Toegankelijke Overheidsinformatie, versie 1.0/1.1). Bestuursorganen zijn wettelijk verplicht zaak-dossiers na hun bewaartermijn (typisch 5, 7, 10, 20 of permanent volgens Selectielijst gemeenten en intergemeentelijke organen 2020) over te dragen aan een gecertificeerde archiefbewaarplaats — een e-Depot beheerd door het Regionaal Historisch Centrum, het Gemeentearchief, of een commerciële dienst (De Ree, Digital Taties, Picturae, Devoteam, et cetera).

In de praktijk gebeurt deze overdracht ad-hoc, vaak handmatig door een DIV-medewerker die per dossier een ZIP exporteert, metadata in een spreadsheet bijhoudt, en de bundel via SFTP naar het e-Depot uploadt. Deze aanpak schaalt niet, is foutgevoelig (verkeerde bewaartermijn, missende metadata, originele documenten niet meegenomen), produceert geen bewijs-van-overdracht dat overheidsarchief-inspecties verwachten, en kan niet rollbacken als het e-Depot de ingestion afkeurt. Bovendien past het slecht in het Common Ground-paradigma waarin data bij de bron blijft tot het moment van archivering en daarna door de zorgdrager (de archivaris) wordt overgenomen.

This capability automates the trigger-detectie (zaak-afgesloten + bewaartermijn-bepaling), bouwt een TMLO/MDTO-conforme metadata-bundel, produceert een multi-format export (XML-metadata + PDF/A-archiveer-renderings + originele bestanden conform het SIP-format), zendt deze naar het geconfigureerde e-Depot via de geprefereerde overdracht-API (ToPX/SIP via HTTP, of Bagit-format voor sommige e-Depots), wacht op een ingestion-bevestiging met een uniek archief-id, en legt het bewijs-van-overdracht vast in de procest audit-trail. Als de ingestion faalt of wordt afgekeurd, MUST de capability een rollback uitvoeren — het dossier blijft beschikbaar in procest, de overdracht-status gaat naar "gefaald", en de DIV-medewerker krijgt een gerichte actie om het probleem op te lossen.

## Data Model

The capability introduces zes schemas in the `procest-archief` register. `BewaarTermijnRegel` is de regeling-laag — per zaaktype-key een bewaartermijn (in jaren of "permanent"), de selectielijst-categorie (bijv. "Selectielijst gemeenten 4.1.3"), de selectielijst-versie, de archiefdienst-bestemming, en eventuele uitzonderingen (bijv. langer bewaren bij rechtsgeschil of bezwaar). Gemeenten configureren deze regels eenmalig per organisatie; standaard worden de VNG-default regels meegeleverd.

`OverdrachtTrigger` is de detectie-eenheid — voor elke afgesloten zaak een berekende overdracht-datum = afsluiting-datum + bewaartermijn-jaren. De trigger heeft een status (gepland, gereed-voor-overdracht, in-overdracht, geslaagd, gefaald, vernietigd) en een verwijzing naar de zaak. Bij status "gereed-voor-overdracht" wordt de daadwerkelijke export voorbereid.

`SipBundel` is het Submission Information Package conform OAIS-model en TMLO/MDTO-profiel — een container met de zaak-metadata in TMLO/MDTO-XML, de document-metadata per attachment, de geconverteerde PDF/A renderings, de originele bestanden (om bit-perfect te bewaren), en een manifest met SHA-256 checksums voor elk bestand. De bundel-formaat is configureerbaar: BagIt (RFC 8493) voor de meeste Nederlandse e-Depots, of een proprietary ToPX-XML structuur voor oudere implementaties.

`OverdrachtTransactie` is de communicatie-eenheid met het e-Depot — de verzonden bundel, het kanaal (HTTPS, SFTP, S3), het tijdstip, de ontvangen status-codes, en eventuele foutmeldingen. Een transactie kan meerdere pogingen omvatten met exponential backoff. Bij succes ontvangt het system een ArchiefBewijs.

`ArchiefBewijs` is het bewijs-van-overdracht — uniek archief-id van het e-Depot, ondertekende ontvangst-bevestiging (PDF + XML signature), bewaarplaats-naam, ingestion-datum, en een terugverwijzing naar de SipBundel-checksums voor latere verificatie. Dit document is het bewijs dat de gemeente kan tonen aan de provinciaal archief-inspecteur bij audit.

`OverdrachtAuditLog` is de complete, append-only event-stream van elke handeling rond de overdracht — trigger-detectie, bundel-bouwen, bestand-toevoegen, checksum-berekening, verzending, ontvangst, akkoord, fout, rollback. Deze log is bron-van-waarheid voor latere reconstructie en wordt zelf bewaard volgens permanente bewaartermijn (de meta-archivering is permanent).

## Requirements

### REQ-ARCH-001: Bewaartermijn-bepaling per zaaktype

Het system MUST voor elk afgesloten zaak de bewaartermijn bepalen op basis van zaaktype en de geconfigureerde BewaarTermijnRegel, met expliciete weigering om over te dragen als geen regel is geconfigureerd.

- GIVEN een gemeente heeft BewaarTermijnRegels voor "omgevingsvergunning" (permanent), "wmo-aanvraag" (5 jaar), en "subsidie-verleend" (10 jaar)
- WHEN een omgevingsvergunning wordt afgesloten op 2026-05-21
- THEN MUST een OverdrachtTrigger worden aangemaakt met overdracht-datum "permanent" en status "gepland" (markering voor doorzetting bij volgende selectielijst-revisie of bij organisatorische beslissing)

- GIVEN een zaak van onbekend zaaktype wordt afgesloten
- WHEN de bewaartermijn-bepaling draait
- THEN MUST een waarschuwing naar de DIV-medewerker worden gestuurd met een verzoek tot configuratie van een BewaarTermijnRegel, en de trigger blijft op status "geblokkeerd-geen-regel"

- GIVEN een zaak heeft een actief bezwaar of beroep
- WHEN de bewaartermijn-bepaling draait
- THEN MUST de overdracht-datum worden opgeschort tot de juridische procedure is afgerond en het bezwaar/beroep-status terug op "afgerond" staat

### REQ-ARCH-002: TMLO/MDTO metadata-bundel-bouwen

Het system MUST een metadata-bundel produceren die volledig voldoet aan het TMLO 1.2.1 of MDTO 1.0/1.1 profiel (configurable per e-Depot — sommige zijn nog op TMLO, nieuwere op MDTO), met alle verplichte velden, alle aanwezige optionele velden, en correct genest XML.

- GIVEN een zaak met titel, omschrijving, behandelaar, betrokken organisaties, en 7 documenten wordt bundeled voor MDTO
- WHEN de bundel wordt gebouwd
- THEN MUST de XML kloppen tegen het officiële XSD-schema van MDTO 1.1 en MUST alle verplichte velden (identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn, eventGeschiedenis) zijn ingevuld

- GIVEN een zaak heeft een document zonder vastgelegd document-type
- WHEN de bundel wordt gebouwd
- THEN MUST een fout worden geraised dat het document-type ontbreekt, MUST de bundel-bouw worden afgebroken, en MUST de DIV-medewerker worden gericht naar het ontbrekende document voor classificatie

### REQ-ARCH-003: Multi-format document-export

Voor elk document in de zaak MUST het system zowel een PDF/A-2b of PDF/A-3a render (voor langetermijn-leesbaarheid) als het originele bestand (voor bit-perfect bewaring) opnemen, met checksums voor beide.

- GIVEN een zaak bevat een .docx, een .xlsx, en een gescand .pdf
- WHEN de bundel wordt gebouwd
- THEN MUST voor de .docx een PDF/A-2b worden gegenereerd via een convert-pipeline (LibreOffice headless of Apache Tika), MUST de .xlsx in PDF/A worden geconverteerd, MUST de gescande .pdf worden gevalideerd tegen PDF/A en zo nodig herrenders, en MUST alle drie originele bestanden óók in de bundel zitten

- GIVEN een document-conversie naar PDF/A faalt
- WHEN de bundel-bouw doorgaat
- THEN MUST de bundel-bouw worden afgebroken, MUST de fout in de audit-log worden vastgelegd, en MUST de DIV-medewerker worden geïnformeerd zodat zij handmatig kunnen renderen of het document kunnen vervangen

- GIVEN een document is digitaal ondertekend
- WHEN de bundel wordt gebouwd
- THEN MUST de digitale handtekening bewaard blijven in het originele bestand, MUST de handtekening-metadata in MDTO worden vastgelegd, en MUST de PDF/A-render een visuele indicatie bevatten dat het origineel was ondertekend

### REQ-ARCH-004: Checksum-verificatie en BagIt-conformiteit

De SIP-bundel MUST een SHA-256 checksum bevatten per bestand, en het manifest (BagIt of equivalent) MUST hercomputeerbaar zijn aan de ontvangst-zijde, zodat het e-Depot integriteit kan verifiëren.

- GIVEN een bundel wordt afgesloten voor verzending
- WHEN het manifest wordt geschreven
- THEN MUST een SHA-256 voor elk bestand worden berekend en in het manifest worden opgenomen volgens de BagIt-spec, en MUST een totaal-bundle checksum óók worden opgenomen voor end-to-end verificatie

- GIVEN een e-Depot ontvangt een bundel en herberekent checksums
- WHEN een mismatch wordt gedetecteerd
- THEN MUST de OverdrachtTransactie de mismatch-foutcode opvangen, MUST de status naar "gefaald-checksum-mismatch" gaan, en MUST een retry-met-nieuwe-bundel-poging worden gepland

### REQ-ARCH-005: e-Depot ingestion via geconfigureerd kanaal

Het system MUST de bundel naar het geconfigureerde e-Depot kunnen verzenden via het door dat e-Depot ondersteunde kanaal — HTTPS POST naar een ingestion-endpoint, SFTP-upload naar een drop-folder, of S3-compatible put — met authenticatie via API-key, certificaat, of OAuth2.

- GIVEN het e-Depot is geconfigureerd voor HTTPS POST met API-key
- WHEN een bundel wordt verzonden
- THEN MUST de transactie de Authorization-header met de API-key bevatten, MUST de bundel als multipart/form-data of als application/zip worden verzonden (afhankelijk van endpoint), en MUST de ontvangen response worden geparsed voor het archief-id

- GIVEN het e-Depot is geconfigureerd voor SFTP met SSH-key
- WHEN een bundel wordt verzonden
- THEN MUST een SFTP-connectie worden opgezet, MUST de bundel naar de drop-folder worden geupload, MUST een trigger-file (.complete) worden geschreven na voltooiing, en MUST een polling-process de bevestiging-folder controleren voor de archief-id-respons

- GIVEN een netwerkfout optreedt tijdens verzending
- WHEN de transactie faalt
- THEN MUST een retry worden gepland met exponential backoff (1 min, 5 min, 30 min, 2 uur, 8 uur, daarna escalatie), MUST elke poging in de audit-log worden vastgelegd, en MUST na 5 mislukte pogingen een DIV-medewerker worden geëscaleerd

### REQ-ARCH-006: Bewijs-van-overdracht vastlegging

Bij succesvolle ingestion MUST het system het ontvangen archief-id, de ondertekende ontvangst-bevestiging, en alle metadata vastleggen in een ArchiefBewijs-record, en dit als read-only attachment aan de oorspronkelijke procest-zaak hangen voor latere referentie.

- GIVEN een e-Depot retourneert succesvol een archief-id "EDP-2026-ABC-12345" met een ondertekende PDF-bevestiging
- WHEN het system de respons verwerkt
- THEN MUST een ArchiefBewijs-record worden aangemaakt met het archief-id, de bevestiging-PDF, de bewaarplaats-naam, en de ingestion-datum, en MUST dit bewijs als read-only document aan de procest-zaak worden gekoppeld

- GIVEN een archiefinspectie vraagt naar het bewijs van overdracht voor een specifieke zaak
- WHEN de archief-inspecteur het bewijs opvraagt
- THEN MUST een gestructureerde export (PDF + JSON) van het ArchiefBewijs + relevante OverdrachtAuditLog-events worden gegenereerd, inclusief checksum-verificatie tegen de oorspronkelijke bundle-checksums

### REQ-ARCH-007: Rollback bij ingestie-fout

Als het e-Depot de ingestion afkeurt (metadata-fout, schema-mismatch, virus-detectie, capacity-limit), MUST het system een rollback uitvoeren waarbij het dossier in procest beschikbaar blijft, de overdracht-status op "gefaald" gaat, en een gerichte actie aan de DIV-medewerker wordt voorgesteld.

- GIVEN een e-Depot keurt een bundel af met foutcode "MDTO_VALIDATION_FAILED" en details over een ontbrekend veld
- WHEN het system de afkeuring ontvangt
- THEN MUST de OverdrachtTransactie op status "gefaald" gaan met de exacte foutcode en -details, MUST de SipBundel worden bewaard voor diagnose, MUST de zaak in procest NIET worden vernietigd, en MUST een actie worden aangemaakt voor de DIV-medewerker met de ontbrekende veld-info

- GIVEN een DIV-medewerker corrigeert het ontbrekende veld in de zaak
- WHEN zij de overdracht-retry triggert
- THEN MUST een nieuwe SipBundel worden gebouwd met de gecorrigeerde metadata, een nieuwe transactie worden gestart, en MUST de oorspronkelijke gefaalde transactie in de audit-trail bewaard blijven voor traceability

### REQ-ARCH-008: Vernietiging na succesvolle overdracht of bewaartermijn

Na succesvolle overdracht en een geconfigureerde retentie-periode in procest zelf (typisch 3-12 maanden voor terugkijk-doeleinden) MUST het system de zaak-data in procest kunnen vernietigen, met behoud van het ArchiefBewijs voor traceerbaarheid. Voor zaken met "vernietigen"-bewaartermijn (kortdurend) MUST vernietiging na de termijn rechtstreeks plaatsvinden zonder overdracht.

- GIVEN een zaak is succesvol overgedragen op 2026-01-15 en de procest-retentieperiode is 6 maanden
- WHEN het vernietigings-job draait op 2026-07-15
- THEN MUST de zaak-data en alle documenten worden vernietigd uit procest, MUST het ArchiefBewijs en een pointer naar het e-Depot archief-id worden bewaard als stub-record, en MUST de vernietiging in de OverdrachtAuditLog worden vastgelegd

- GIVEN een zaak heeft bewaartermijn "vernietigen na 1 jaar" volgens selectielijst
- WHEN de bewaartermijn verstrijkt
- THEN MUST de zaak-data en alle documenten worden vernietigd zonder overdracht naar e-Depot, MUST een vernietigings-bewijs worden gegenereerd, en MUST een notificatie aan DIV worden gestuurd voor controle

### REQ-ARCH-009: Selectielijst-aware bulk-acties

Het system MUST DIV-medewerkers in staat stellen om bulk-acties uit te voeren op groepen zaken die dezelfde bewaartermijn delen, voor periodieke overdracht-batches (bijv. kwartaal-batches).

- GIVEN er staan 250 zaken op status "gereed-voor-overdracht" voor selectielijst-categorie "subsidie-vastgesteld" met bestemming "RHC Utrecht e-Depot"
- WHEN de DIV-medewerker een batch-overdracht initieert
- THEN MUST het system een overzicht tonen, een batch-job creëren, de bundels parallel bouwen met een configureerbare concurrency-limit (default 4), per zaak een eigen OverdrachtTransactie aanmaken, en een batch-rapport produceren met succes/falen per zaak

- GIVEN een batch-overdracht is voltooid met 245 succesvol en 5 gefaald
- WHEN het batch-rapport wordt gegenereerd
- THEN MUST de 5 gefaalde zaken individueel worden gerapporteerd met foutcode en suggesties, en MUST de batch-job-stats in OverdrachtAuditLog worden vastgelegd

### REQ-ARCH-010: Archiefinspectie-export

Het system MUST een complete, audit-grade export kunnen produceren voor de provinciaal archief-inspectie of de externe accountant, met alle overdrachten over een tijdsperiode, hun bewijzen, en de bijbehorende audit-trail.

- GIVEN een archief-inspecteur vraagt een overzicht van alle overdrachten over kalenderjaar 2026
- WHEN de export wordt gegenereerd
- THEN MUST een ZIP worden geleverd met een CSV-overzicht (zaak-id, zaaktype, afsluiting-datum, overdracht-datum, archief-id, e-Depot), de individuele ArchiefBewijs-PDFs, en een samenvattende PDF met statistieken (totaal overgedragen, mislukt, in-behandeling)

- GIVEN de inspecteur wil de checksum-integriteit van een specifieke overdracht verifiëren
- WHEN deze de verificatie-functie aanroept met een archief-id
- THEN MUST het system de oorspronkelijke checksums uit de SipBundel ophalen, deze tonen, en een instructie genereren hoe het e-Depot deze checksums kan herbevestigen voor end-to-end audit

## Standards & Sources

De capability is gegrond op de Archiefwet 1995 (en de aankomende Archiefwet 2024 die in 2026 in werking zal treden) en het Archiefbesluit, de Archiefregeling 2010 (eisen aan duurzame opslag, formaten, metadata), de Selectielijst gemeenten en intergemeentelijke organen 2020 (en sectorale selectielijsten voor waterschappen, provincies, ministeries), TMLO 1.2.1 (Toepassingsprofiel Metadatering Lokale Overheden, Nationaal Archief) als legacy-profiel, MDTO 1.1 (Metagegevens voor Duurzaam Toegankelijke Overheidsinformatie, Nationaal Archief 2024) als de huidige standaard, BagIt File Packaging Format (RFC 8493) voor SIP-verpakking, ISO 14721 OAIS (Open Archival Information System) als referentie-model, ISO 19005 (PDF/A) voor langetermijn-render-format, en het Nederlandse Common Ground-paradigma voor de data-bij-de-bron-tot-archivering filosofie. Voor de overdracht-koppelingen worden de ToPX-standaard (Toepassingsprofiel XML voor overdracht naar e-Depot) en de KIA-werkgroep-richtlijnen voor e-Depot interoperabiliteit gevolgd.

## Cross-app integration

The capability depends on procest base (zaak-engine, document-store, audit-trail, behandelaar-model), docudesk voor de TMLO/MDTO-rendering pipeline en de PDF/A-conversie-services (docudesk levert de canonical metadata-extraction en format-conversion), en openregister voor de archive-trigger event-bus en de schema-registratie. Het roept openconnector aan als adapter-laag naar concrete e-Depot endpoints — elk e-Depot (RHC, gemeentearchief, De Ree, Picturae, Digital Taties) heeft een eigen koppelvlak dat als openconnector-source/-mapping wordt geconfigureerd. Het emit events op de openregister event-bus zodat een mydash management-dashboard de overdracht-pijplijn kan visualiseren en een nldesign burgerportal een statusoverzicht "Uw dossier is overgedragen aan archief X" kan tonen. Voor virus-scanning van bundels wordt geïntegreerd met de Nextcloud antivirus-app of een externe scanner via openconnector. Voor de digital signature van overdracht-bevestigingen wordt aangesloten op de Nextcloud collabora signing-functie of een externe trust-service (PKIoverheid). Voor reporting naar de provinciaal archief-inspectie wordt een dedicated export-endpoint geleverd dat door een opencatalogi-publicatieportal kan worden geconsumeerd.

## Target users

Primaire gebruikers zijn DIV-medewerkers (Documentaire Informatievoorziening) en archivarissen binnen gemeenten, provincies, waterschappen, en uitvoeringsorganisaties die verantwoordelijk zijn voor het naleven van de Archiefwet en het tijdig overdragen van dossiers aan e-Depots. Secundaire gebruikers zijn de provinciaal archief-inspecteurs (voor toezicht), externe accountants (voor audit van archivering-discipline), gemeente-secretarissen (als formele zorgdrager voor de gemeente-archieven), e-Depot beheerders bij Regionaal Historische Centra of commerciële aanbieders (als ontvangers van de bundels), en de Nationaal Archivaris (voor toezicht op metadata-conformiteit). Behandelaren in de oorspronkelijke procest-zaken krijgen de capability niet direct te zien maar profiteren van geautomatiseerde naleving zonder dat zij handmatige acties hoeven uit te voeren. Voor burgers is de capability onzichtbaar maar relevant — hun dossiers blijven na bewaartermijn raadpleegbaar bij het regionale archief, en het bewijs-van-overdracht garandeert dat dossiers niet verloren gaan in de transitie van actieve procest-omgeving naar permanent archief. Voor onderzoeksjournalisten en historici is de upstream-impact dat overheidsdossiers consistent en met complete metadata in e-Depots terechtkomen, wat onderzoek decennia later mogelijk maakt.

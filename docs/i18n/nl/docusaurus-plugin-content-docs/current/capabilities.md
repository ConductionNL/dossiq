---
id: capabilities
title: Wat dossiq kan
sidebar_label: Wat dossiq kan
sidebar_position: 2
description: Alle 160 capabilities van dossiq die gebouwd zijn, per thema, met het scherm waarop je ze ziet.
---

# Wat dossiq kan

Dossiq is het zaaksysteem voor Nextcloud. Deze pagina noemt de 160 capabilities die gebouwd zijn, in 7 thema's. Wat nog op de planning staat, staat hier niet.

Achter elke capability staat het scherm waarop je hem ziet. De link opent het ontwerp op [identity.conduction.nl/screens](https://identity.conduction.nl/screens/). Alle schermen bij elkaar vind je op [Schermen](./screens.md).

## In het kort

- **Nederlandse en Europese standaarden.** Zaken volgen de ZGW-API's (Zaken, Documenten, Catalogi, Besluiten) en de Notificaties API. Een zaaktype kan ook als CMMN-zaakplan lopen, met plan items, sentries en mijlpalen.
- **Flows per zaaktype.** Fasen, taken, regels, beslistabellen (DMN) en automatische acties stel je in per zaaktype. Een nieuwe procedure is inrichting, geen maatwerk.
- **In Nextcloud, niet ernaast.** Documenten zijn Nextcloud-bestanden, mail is Nextcloud Mail, rechten zijn Nextcloud-groepen. Je medewerkers werken in de omgeving die ze al kennen.
- **Gebouwd op Nextcloud Tables.** We bouwen dossiq op Nextcloud Tables, de tabellenlaag van Nextcloud. Een tabel uit Tables gebruik je in een zaak zonder hem te kopiëren. De deelstaat Sleeswijk-Holstein vervangt SharePoint door Nextcloud, met Tables als bouwsteen. Samen met Nextcloud brengen we Tables in lijn met het principe data bij de bron.
- **Archiefwaardig.** Bewaartermijn, vernietiging en overbrenging naar het e-Depot horen bij de zaak. Lees [Archivering](./archiving.md).

## Zaken behandelen

### Zaken aanmaken via elk kanaal

| Capability | Scherm |
|---|---|
| Zaak registreren in een dialoog (zaaktype, aanvrager, velden) | [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak) |
| Zaak aanmaken vanuit een contact of inwoner | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak), [Organisatie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisatie) |
| Wachtrij voor binnengekomen documenten (toewijzen of afwijzen) |  |
| Zaken aanmaken via de API |  |
| Geplande en terugkerende zaken | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakGerelateerd](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakGerelateerd) |
| Intake routeren naar afdeling of rol | [Teamwachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTeamwachtrij) |
| Zaak starten vanaf het dashboard | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [MijnWerk](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnWerk) |
| Dubbele zaken herkennen bij de intake | [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak) |

### Zaken dagelijks behandelen

| Capability | Scherm |
|---|---|
| Zaaknummer uit een masker of reeks | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Zaak toewijzen, oppakken en teruggeven | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [MijnTeam](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnTeam), [Teamwachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTeamwachtrij), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Zaak toewijzen aan een team of afdeling met rol | [Overdragen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOverdragen), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Bulkacties op de zakenlijst | [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Labels op zaken | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Zaak kopiëren | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakMeer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakMeer) |
| Deelzaken, gerelateerde zaken en hiërarchie | [ZaakGerelateerd](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakGerelateerd) |
| Afgesloten zaak heropenen | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Zaak verwijderen met controle | [ZaakMeer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakMeer) |
| Favorieten en recent geopend | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [MijnWerk](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnWerk) |
| Zaakpagina die live bijwerkt | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Ruwe zaakgegevens inzien voor beheerders (data, variabelen, logs) | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakMeer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakMeer) |
| Twee zaken samenvoegen | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Automatisch rappelleren terwijl een zaak op een ander wacht | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Bewerkslot op de zaak, met de naam van wie hem vasthoudt | [ZaakSamenwerken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakSamenwerken) |
| Een zaak delen met of overdragen aan een andere Nextcloud-omgeving | [ZaakSamenwerken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakSamenwerken) |

### Taken en werkvoorraad

| Capability | Scherm |
|---|---|
| Taken met een formulier | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak) |
| Vervaldatum op taken | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [ZaakTaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakTaken) |
| Taak toewijzen, oppakken, teruggeven of naar een team | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken) |
| Taken automatisch naar de behandelaar | [ZaakTaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakTaken) |
| Taken toewijzen aan externe partners |  |
| Persoonlijke wachtrij op urgentie en termijn, met een werklastoverzicht van het team | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [MijnTeam](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnTeam), [Teamwachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTeamwachtrij), [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij) |

### Termijnbewaking

| Capability | Scherm |
|---|---|
| Opschorten en hervatten met reden of termijn | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Wettelijke termijn en servicetermijn per zaaktype met streefdatum | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Te-laat- en urgentie-indicator op zaak en lijst | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Herinneringen op een zaak met een verantwoordelijke | [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij), [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Termijn verlengen of wijzigen met reden | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Termijnmeldingen per mail |  |
| Mijlpalen met geplande intervallen | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Timers in het proces |  |
| Opschorttermijn met automatisch hervatten | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Einddatum schuift op bij weekend of feestdag |  |
| Eén werkdagenkalender, op één plek beheerd | [InstellingenAlgemeen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingenAlgemeen) |
| Opschorten geeft de gepauzeerde tijd terug aan de termijn | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Escalatieladder met meerdere stappen bij een overschreden termijn |  |
| Een termijn eindigt op een dag, niet op een tijdstip | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |

### Notities, meldingen en de zaaktijdlijn

| Capability | Scherm |
|---|---|
| Notities op de zaak | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Eén communicatietijdlijn per zaak (notities, mail, berichten) | [ZaakContact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakContact), [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| Meldingen in de app |  |
| Interne of openbare zichtbaarheid per tijdlijnregel | [ZaakContact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakContact), [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |

### Betrokkenen en contacten op de zaak

| Capability | Scherm |
|---|---|
| Betrokkenen op de zaak met rollen | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Register van personen en organisaties | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [Contacten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContacten), [Organisatie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisatie), [Organisaties](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisaties) |
| Alles van een contact in één beeld (zaken, documenten, communicatie) | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [Organisatie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisatie) |
| Organisatieboom en medewerkersgids | [Afdelingen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAfdelingen) |
| Gemachtigde op een zaak | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Aanvrager als kaart in de kop van de zaak | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |

### Zoeken en opgeslagen lijsten

| Capability | Scherm |
|---|---|
| Uitgebreid zoeken met velden per zaaktype | [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Persoonlijke opgeslagen zoekopdrachten | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Zelf kolommen kiezen in lijsten | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Zoekresultaten gefilterd op toegangsrechten |  |
| Zoeken in eigen objecten | [Zoekresultaten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZoekresultaten) |
| Zoeken in taken | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken) |
| Lijsten exporteren naar CSV of XLSX | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |

### Kaartweergave met PDOK

| Capability | Scherm |
|---|---|
| Zaaklocatie (adres, coördinaten) op de kaart | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZakenKaart](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZakenKaart) |
| Pand of adres als betrokken object (BAG) | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Zoekresultaten op de kaart | [ZakenKaart](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZakenKaart) |
| Kaart- en geodiensten (PDOK, BAG, ArcGIS) | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZakenKaart](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZakenKaart) |

### Bezwaar en beroep

| Capability | Scherm |
|---|---|
| Bezwaar en beroep behandelen | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |

## Zaaktypen en flows

### Configureerbare zaaktypen

| Capability | Scherm |
|---|---|
| Getypeerd gegevensschema per zaaktype | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Zaaktypeversies met concept, publiceren en activeren | [Zaaktype](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktype), [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |
| Instelbare statussen met kleur en volgorde | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |
| Zaaktype of versie van een lopende zaak wijzigen | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Eigen objecten gekoppeld aan zaken | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakGerelateerd](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakGerelateerd) |
| Zaaktypen bewerken in de interface | [Zaaktype](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktype), [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht), [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Inrichting als code: zaaktypen importeren en exporteren | [Catalogus](https://identity.conduction.nl/screens/board.html?id=dossiq/DqCatalogus), [Zaaktypen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypen) |
| Eigen velden per zaaktype | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Keuzelijsten en codelijsten | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Objecttypen beheren | [Catalogus](https://identity.conduction.nl/screens/board.html?id=dossiq/DqCatalogus) |
| Inrichtingsfouten opsporen |  |
| Regels per zaaktype (voorwaarden en acties) | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |
| Moeder- en kindzaaktypen (overerving) | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht), [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Catalogus met mappen voor zaaktypen, attributen en sjablonen | [Catalogus](https://identity.conduction.nl/screens/board.html?id=dossiq/DqCatalogus) |

### Zaakpagina-indeling en formulieren per zaaktype

| Capability | Scherm |
|---|---|
| Instelbare lijstkolommen per zaaktype | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |

### Meertalige zaaktypen

| Capability | Scherm |
|---|---|
| Vertalingen beheren in de interface | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |

### Workflow-engine met voorwaarden en acties

| Capability | Scherm |
|---|---|
| Lineaire fasen per zaaktype | [Werkbord](https://identity.conduction.nl/screens/board.html?id=dossiq/DqWerkbord), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Checklist per fase | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Automatische acties bij een fase- of procesovergang (sjablonen, mails, gerelateerde zaken) |  |
| Deelprocessen en bouwblokken, te starten vanuit de zaak | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Regelengine (voorwaarden naar een veld- of actie-uitkomst) |  |
| Voortgangs- of fasewidget op de zaakpagina | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Velden vullen en datums berekenen met formules |  |
| Webhooks en event-outbox op zaakgebeurtenissen |  |

### Advies, resultaten en besluiten

| Capability | Scherm |
|---|---|
| Resultaat uit een instelbare lijst per zaaktype | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Beslistabellen (DMN) |  |
| Formeel besluit vastleggen | [Besluit](https://identity.conduction.nl/screens/board.html?id=dossiq/DqBesluit), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |
| Besluitbrief als stap in de flow | [DocumentGenereren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentGenereren), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |

## Documenten en archief

### Documenten op de zaak

| Capability | Scherm |
|---|---|
| Document als metadatarecord met bestanden | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Mappenstructuur binnen een zaak | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Bestandsversies bekijken, downloaden en terugzetten | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Documentmetadata (type, status, vertrouwelijkheid, richting, datums) | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Labels op documenten | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Samenvoegen tot pdf, kopiëren naar een andere zaak | [DocumentenBeheer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentenBeheer) |
| Volledige tekst van bestanden doorzoekbaar |  |
| Virusscan en integriteitscontrole | [DocumentenBeheer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentenBeheer) |
| Uploaden door te slepen | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Afzenders en ontvangers van documenten | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |

### Documenten bewerken, genereren en ondertekenen

| Capability | Scherm |
|---|---|
| Documenten beoordelen en goedkeuren | [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij), [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Document genereren uit een sjabloon met samenvoegvelden | [DocumentGenereren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentGenereren) |
| Digitaal ondertekenen | [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |
| Bibliotheek met documentsjablonen | [DocumentGenereren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentGenereren) |

### Archivering naar e-Depot

| Capability | Scherm |
|---|---|
| Archiefexport van afgesloten zaken (XML, SIP, TMLO/MDTO) | [Archiveren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqArchiveren), [ZaakArchivering](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakArchivering) |
| Het resultaat bepaalt waardering en bewaartermijn | [Archiveren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqArchiveren), [ZaakArchivering](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakArchivering) |
| Bewaartermijn, vernietigingsdatum en automatische vernietiging | [ZaakArchivering](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakArchivering) |

### Woo-verzoeken met lakken en publiceren

| Capability | Scherm |
|---|---|
| Documenten anonimiseren en lakken | [Lakken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqLakken) |
| Document publiceren naar portaal of website | [Publiceren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqPubliceren) |
| Woo-verzoek met beoordeling en lakken per document | [Besluit](https://identity.conduction.nl/screens/board.html?id=dossiq/DqBesluit), [Publiceren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqPubliceren), [WooVerzoeken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqWooVerzoeken) |

## Communicatie met inwoners

### Zaakgericht mailen

| Capability | Scherm |
|---|---|
| Mail uit een postvak wordt een zaak | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Mailen vanuit de zaak met sjablonen en zaakdocumenten | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Postvak van de organisatie, met toewijzen aan een zaak | [Postvak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqPostvak) |
| .msg- of .eml-bestanden in een zaak importeren | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Bibliotheek met mailsjablonen | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |

### Digitale post aan inwoners

| Capability | Scherm |
|---|---|
| Digitale post aan inwoners (Berichtenbox, Postex) | [BerichtVersturen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqBerichtVersturen) |

### Burgerportaal Mijn Zaken

| Capability | Scherm |
|---|---|
| Webformulier voor inwoners per zaaktype |  |
| Wijzigingsvoorstellen van collega's of inwoners in een wachtrij |  |
| Portaalrechten voor inwoners per zaak | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |

### Afspraken maken

| Capability | Scherm |
|---|---|
| Afspraken plannen | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [ZaakTaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakTaken) |

## Toegang en verantwoording

### Toegangsrechten op de zaak

| Capability | Scherm |
|---|---|
| Vertrouwelijkheidsniveau op de zaak | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Bijzondere persoonsgegevens achter een extra recht | [Contacten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContacten) |
| Velden verborgen, verplicht of alleen-lezen per rol en zaakstatus | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Applicatierollen |  |
| Toegang tot gegevens op voorwaarden (attributen en beleid) |  |
| Toegang met een einddatum | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Rechten per veld |  |
| Delegatie, mandaten en vervanging | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |
| Afwezigheid met een waarnemer die het werk ziet | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij) |
| Volgers op een zaak, los van de behandelaar | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |

### Zaakhistorie, audittrail en verwerkingenlogging

| Capability | Scherm |
|---|---|
| Versiegeschiedenis van zaakgegevens | [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| Audittijdlijn op de zaakpagina | [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| Audittrail inclusief lezen en inzien | [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| Verwerkingsregister (AVG) per zaaktype | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |

## Standaarden en koppelingen

### ZGW API-koppeling

| Capability | Scherm |
|---|---|
| ZGW-API's (Zaken, Documenten, Catalogi, Besluiten) |  |
| Notificaties API |  |
| Message broker of event streaming |  |
| Generiek raamwerk voor koppelingen |  |
| OpenAPI-documentatie |  |
| Openbare data-API met certificaten of API-sleutels |  |

### StUF-ZKN-koppeling

| Capability | Scherm |
|---|---|
| Notities gesynchroniseerd naar een extern zaakregister |  |
| StUF (BG, ZKN, DCR) |  |

### BRP-koppeling (Haal Centraal)

| Capability | Scherm |
|---|---|
| BRP en KvK bevragen | [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak) |
| Geheimhouding en adresafscherming | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [Contacten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContacten), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |

### DSO / Omgevingsloket

| Capability | Scherm |
|---|---|
| DSO en Omgevingswet |  |

## Sturing en inrichting

### Doorlooptijd-dashboards

| Capability | Scherm |
|---|---|
| Instelbare KPI-dashboards (teller, meter, grafiek) | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard) |
| Persoonlijk dashboard met zelfgekozen widgets | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [MijnWerk](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnWerk) |
| Procesanalyse (heatmap, doorlooptijden) | [Procesanalyse](https://identity.conduction.nl/screens/board.html?id=dossiq/DqProcesanalyse) |
| API voor statistieken en rapportage |  |

### Subsidies, uren en zaakkosten

| Capability | Scherm |
|---|---|
| Uren schrijven op een zaak | [DagAfsluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDagAfsluiten), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |

### Inrichting en app-instellingen

| Capability | Scherm |
|---|---|
| Huisstijl en thema | [InstellingenAlgemeen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingenAlgemeen) |
| Koppelingen instellen in de interface, met geheimen | [Instellingen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingen) |
| Omgevingsinstellingen (meldingsteksten, portaalteksten, CORS) | [InstellingenAlgemeen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingenAlgemeen) |
| Wizard voor de eerste inrichting, met voorbeeldgegevens | [Instellen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellen) |

### AI-assistentie

| Capability | Scherm |
|---|---|
| Kennisbank met zichtbaarheid per rol | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| AI-assistent die acties voorstelt en na bevestiging uitvoert | [ZaakAssistent](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakAssistent) |

## Verder lezen

- Bekijk [alle schermen](./screens.md).
- Lees hoe dossiq [archiveert](./archiving.md).
- Installeer dossiq met de [installatiegids](/docs/installation).

---
id: capabilities
title: What Dossiq can do
sidebar_label: What Dossiq can do
sidebar_position: 2
description: All 160 Dossiq capabilities that are built, by theme, with the screen that shows each one.
---

# What Dossiq can do

Dossiq is the case management system for Nextcloud. This page names the 160 capabilities that are built, in 7 themes. Planned work is not listed here.

Each capability names the screen that shows it. The link opens the design on [identity.conduction.nl/screens](https://identity.conduction.nl/screens/). You find every screen together on [Screens](./screens.md).

## In short

- **Dutch and European standards.** Cases follow the ZGW APIs (Zaken, Documenten, Catalogi, Besluiten) and the Notificaties API. A case type can also run as a CMMN case plan, with plan items, sentries and milestones.
- **Flows per case type.** You set phases, tasks, rules, decision tables (DMN) and automatic actions per case type. A new procedure is configuration, not custom code.
- **Inside Nextcloud, not beside it.** Documents are Nextcloud files, mail is Nextcloud Mail, rights are Nextcloud groups. Your staff work in the workspace they already know.
- **Built on Nextcloud Tables.** We build Dossiq on Nextcloud Tables, the tables layer of Nextcloud. You use a table from Tables in a case without copying it. The German state of Schleswig-Holstein replaces SharePoint with Nextcloud, with Tables as a building block. Together with Nextcloud we bring Tables in line with the principle of data at the source.
- **Fit for the archive.** Retention, destruction and transfer to the e-Depot belong to the case. Read [Archiving](./archiving.md).

## Handling cases

### Case intake from every channel

| Capability | Screen |
|---|---|
| Internal case registration dialog (case type, requestor, fields) | [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak) |
| Create case from a contact or citizen record | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak), [Organisatie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisatie) |
| Document intake queue (assign to case, reject) |  |
| API case creation for external systems |  |
| Scheduled or recurring planned cases | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakGerelateerd](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakGerelateerd) |
| Intake routing to department or role | [Teamwachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTeamwachtrij) |
| Start case from a dashboard action | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [MijnWerk](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnWerk) |
| Duplicate detection at intake | [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak) |

### Handling a case day to day

| Capability | Screen |
|---|---|
| Case number from a mask or sequence | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Assign case to a user, claim, unassign | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [MijnTeam](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnTeam), [Teamwachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTeamwachtrij), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Assign case to a team or department plus role | [Overdragen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOverdragen), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Bulk actions on a case list | [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Case tags or labels | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Copy case | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakMeer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakMeer) |
| Sub-cases, related cases, hierarchy | [ZaakGerelateerd](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakGerelateerd) |
| Reopen a closed case | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Delete case with guard | [ZaakMeer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakMeer) |
| Favourites and recent items | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [MijnWerk](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnWerk) |
| Live updates on the case page | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Raw case inspection for admins (data, variables, logs) | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakMeer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakMeer) |
| Merge two cases into one | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Automatic chasing while a case waits on someone else | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Edit lock on a case, with the holder named | [ZaakSamenwerken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakSamenwerken) |
| Share or transfer a case to another Nextcloud instance and follow its shared activity | [ZaakSamenwerken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakSamenwerken) |

### Tasks and work queues

| Capability | Screen |
|---|---|
| User tasks with a form | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak) |
| Task due date | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [ZaakTaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakTaken) |
| Task assign, claim, unassign, assign to team | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken) |
| Auto-assign tasks to the case handler | [ZaakTaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakTaken) |
| Task assignment to external partners |  |
| Work the personal case queue in order of urgency and deadline, with a team workload summary | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [MijnTeam](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnTeam), [Teamwachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTeamwachtrij), [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij) |

### Deadline monitoring

| Capability | Screen |
|---|---|
| Suspend and resume with reason or term | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Legal and service lead time per case type with target date | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Overdue or urgency indicator on case and list | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Reminders on a case with a responsible user | [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij), [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Extend or change the term with a reason | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Deadline notifications by e-mail |  |
| Milestones with planned intervals | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Timer events in the process |  |
| Suspension term with automatic resume | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Term end date moved off a weekend or public holiday |  |
| One working-day calendar, administered in one place | [InstellingenAlgemeen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingenAlgemeen) |
| Suspension gives the paused time back to the term | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Escalation ladder with more than one step when a term is breached |  |
| A term ends on a day, not at a moment | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |

### Notes, notifications and the case timeline

| Capability | Screen |
|---|---|
| Journal notes on a case | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Unified communication timeline per case (notes, mail, messages) | [ZaakContact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakContact), [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| In-app notifications (bell) |  |
| Internal or public visibility per timeline entry | [ZaakContact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakContact), [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |

### Parties and contacts on the case

| Capability | Screen |
|---|---|
| Parties on a case with roles | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Person and organisation contact registry | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [Contacten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContacten), [Organisatie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisatie), [Organisaties](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisaties) |
| Contact 360 view (cases, documents, communication) | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [Organisatie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqOrganisatie) |
| Internal organisation tree and employee directory | [Afdelingen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAfdelingen) |
| Authorised representative (gemachtigde) on a case | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Person card or primary party in the case header | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |

### Search and saved lists

| Capability | Screen |
|---|---|
| Advanced search with per-case-type fields | [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Personal saved searches | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| User column chooser on lists | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |
| Search results filtered by access rights |  |
| Search over custom objects | [Zoekresultaten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZoekresultaten) |
| Task search fields | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken) |
| Export list results to CSV or XLSX | [Taken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaken), [Zaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaken) |

### Map view with PDOK

| Capability | Screen |
|---|---|
| Case location (address, geo) with map | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZakenKaart](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZakenKaart) |
| Property or address object as a party (BAG) | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Map view of search results | [ZakenKaart](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZakenKaart) |
| Map and geo services (PDOK, BAG, ArcGIS) | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZakenKaart](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZakenKaart) |

### Objections and appeals

| Capability | Screen |
|---|---|
| Objections and appeals | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |

## Case types and flows

### Configurable case types

| Capability | Screen |
|---|---|
| Typed case data schema per case type | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Case type versioning with draft, publish, activate | [Zaaktype](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktype), [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |
| Configurable status vocabulary with colour and order | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |
| Change case type or version of a running case | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Custom objects linked to cases | [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen), [ZaakGerelateerd](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakGerelateerd) |
| Case type editor in the UI | [Zaaktype](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktype), [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht), [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Config as code, import and export of case types | [Catalogus](https://identity.conduction.nl/screens/board.html?id=dossiq/DqCatalogus), [Zaaktypen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypen) |
| Custom fields per case type | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Code lists or choice fields | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Custom object type management | [Catalogus](https://identity.conduction.nl/screens/board.html?id=dossiq/DqCatalogus) |
| Configuration issue detection |  |
| Rules per case type (conditions and actions) | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |
| Mother and child case types (inheritance) | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht), [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Catalog with folders for case types, attributes, templates | [Catalogus](https://identity.conduction.nl/screens/board.html?id=dossiq/DqCatalogus) |

### Case page layout and forms per case type

| Capability | Screen |
|---|---|
| Configurable list columns per case type | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |

### Multilingual case types

| Capability | Screen |
|---|---|
| Translation management in the UI | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |

### Workflow engine with guards and actions

| Capability | Screen |
|---|---|
| Linear phases per case type | [Werkbord](https://identity.conduction.nl/screens/board.html?id=dossiq/DqWerkbord), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Checklist items per phase | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Actions executed automatically on phase or process transition (templates, mails, related cases) |  |
| Sub-processes or building blocks startable from the case | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakDialogen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDialogen) |
| Rules engine (conditions to field or action outcomes) |  |
| Progress or phase widget on the case page | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| Formula-based field fill and date calculation |  |
| Event outbox or webhooks on case events |  |

### Advice, results and formal decisions

| Capability | Screen |
|---|---|
| Result or outcome from a configured list per case type | [VolgendeStap](https://identity.conduction.nl/screens/board.html?id=dossiq/DqVolgendeStap) |
| Decision tables (DMN) |  |
| Formal decision record (besluit) | [Besluit](https://identity.conduction.nl/screens/board.html?id=dossiq/DqBesluit), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |
| Decision letter generated as a workflow step | [DocumentGenereren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentGenereren), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |

## Documents and archive

### Documents on the case

| Capability | Screen |
|---|---|
| Document as a metadata record holding files | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Folder structure inside a case | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| File versions with view, download, restore | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Document metadata (type, status, confidentiality, direction, dates) | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Document labels or tags | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Merge to PDF, copy to another case | [DocumentenBeheer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentenBeheer) |
| Full-text indexing of file content |  |
| Virus scan and integrity check | [DocumentenBeheer](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentenBeheer) |
| Upload with drag and drop | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Document senders and recipients | [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |

### Editing, generating and signing documents

| Capability | Screen |
|---|---|
| Document review or approval workflow | [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij), [ZaakDocumenten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakDocumenten) |
| Generate document from a template with merge fields | [DocumentGenereren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentGenereren) |
| E-signing | [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |
| Document template library | [DocumentGenereren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDocumentGenereren) |

### Archiving to the e-Depot

| Capability | Screen |
|---|---|
| Archive export of closed cases (XML, SIP, TMLO/MDTO) | [Archiveren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqArchiveren), [ZaakArchivering](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakArchivering) |
| Result drives archival nomination and retention | [Archiveren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqArchiveren), [ZaakArchivering](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakArchivering) |
| Retention period, destruction date and automatic deletion | [ZaakArchivering](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakArchivering) |

### Woo requests with redaction and publication

| Capability | Screen |
|---|---|
| Anonymise or redact documents | [Lakken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqLakken) |
| Publish document to citizen portal or website | [Publiceren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqPubliceren) |
| Woo (freedom of information) request with per-document assessment and redaction | [Besluit](https://identity.conduction.nl/screens/board.html?id=dossiq/DqBesluit), [Publiceren](https://identity.conduction.nl/screens/board.html?id=dossiq/DqPubliceren), [WooVerzoeken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqWooVerzoeken) |

## Communication with citizens

### E-mail on the case

| Capability | Screen |
|---|---|
| E-mail intake from a mailbox | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Send e-mail from the case with templates and case attachments | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| Organisation-wide message inbox with assign to case | [Postvak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqPostvak) |
| Import .msg or .eml into a case | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |
| E-mail template library | [ZaakEmail](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakEmail) |

### Digital post to citizens

| Capability | Screen |
|---|---|
| Digital post to citizens (Berichtenbox, Postex) | [BerichtVersturen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqBerichtVersturen) |

### Citizen portal My Cases

| Capability | Screen |
|---|---|
| Citizen web form per case type |  |
| Change proposals from colleagues or citizens queued for acceptance |  |
| Citizen portal access rights per case | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |

### Appointments

| Capability | Screen |
|---|---|
| Appointment booking | [Taak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqTaak), [ZaakTaken](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakTaken) |

## Access and accountability

### Access control on the case

| Capability | Screen |
|---|---|
| Confidentiality level on the case | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Sensitive personal data behind an extra permission | [Contacten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContacten) |
| Fields hidden, required or read only by role and case state | [ZaaktypeVelden](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeVelden) |
| Application roles |  |
| Policy or attribute based access on data (conditions) |  |
| Time-limited access grants | [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |
| Field-level permissions |  |
| Delegation, mandates, substitutes | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij), [ZaakBesluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBesluiten) |
| Out of office with a stand-in who sees the work | [AanMijToegewezen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqAanMijToegewezen), [UwWachtrij](https://identity.conduction.nl/screens/board.html?id=dossiq/DqUwWachtrij) |
| Watchers on a case, separate from the assignee | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |

### Case history, audit trail and processing log

| Capability | Screen |
|---|---|
| Case data version history or snapshots | [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| Case audit timeline on the case page | [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| Audit trail including reads and views | [ZaakHistorie](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakHistorie) |
| GDPR register per case type | [ZaaktypeOverzicht](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaaktypeOverzicht) |

## Standards and links

### ZGW API link

| Capability | Screen |
|---|---|
| ZGW APIs (Zaken, Documenten, Catalogi, Besluiten) |  |
| Notificaties API |  |
| Message broker or event streaming |  |
| Generic plugin or connector framework |  |
| OpenAPI documentation |  |
| Public data API with certificates or API keys |  |

### StUF-ZKN link

| Capability | Screen |
|---|---|
| Notes synced to an external case register |  |
| StUF (BG, ZKN, DCR) |  |

### BRP link (Haal Centraal)

| Capability | Screen |
|---|---|
| National registry lookup (BRP, KvK) | [NieuweZaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqNieuweZaak) |
| Address protection or secrecy indication | [Contact](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContact), [Contacten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqContacten), [ZaakBetrokkenen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakBetrokkenen) |

### DSO / Omgevingsloket

| Capability | Screen |
|---|---|
| DSO or Omgevingswet |  |

## Steering and setup

### Lead-time dashboards

| Capability | Screen |
|---|---|
| Configurable KPI dashboards (count, gauge, chart) | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard) |
| Personal dashboard with user-chosen widgets | [Dashboard](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDashboard), [MijnWerk](https://identity.conduction.nl/screens/board.html?id=dossiq/DqMijnWerk) |
| Process analytics (heatmap, durations) | [Procesanalyse](https://identity.conduction.nl/screens/board.html?id=dossiq/DqProcesanalyse) |
| Statistics or reporting API |  |

### Grants, hours and case costs

| Capability | Screen |
|---|---|
| Time booked against a case | [DagAfsluiten](https://identity.conduction.nl/screens/board.html?id=dossiq/DqDagAfsluiten), [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |

### Setup and app settings

| Capability | Screen |
|---|---|
| Branding and theming | [InstellingenAlgemeen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingenAlgemeen) |
| Integration or plugin configuration UI with secrets | [Instellingen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingen) |
| Instance configuration UI (notification texts, portal texts, CORS) | [InstellingenAlgemeen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellingenAlgemeen) |
| Get guided through first-time setup, including loading demo data | [Instellen](https://identity.conduction.nl/screens/board.html?id=dossiq/DqInstellen) |

### AI assistance

| Capability | Screen |
|---|---|
| Knowledge base with per-role visibility | [Zaak](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaak) |
| AI assistant with executable actions | [ZaakAssistent](https://identity.conduction.nl/screens/board.html?id=dossiq/DqZaakAssistent) |

## Read on

- See [every screen](./screens.md).
- Read how Dossiq [archives](./archiving.md).
- Install Dossiq with the [installation guide](./installation.md).

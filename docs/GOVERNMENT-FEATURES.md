# Procest — Overheidsfunctionaliteiten

> Functiepagina voor Nederlandse overheidsorganisaties.
> Gebruik deze checklist om te toetsen aan uw Programma van Eisen.

**Product:** Procest
**Categorie:** Zaakgericht werken & case management
**Licentie:** AGPL (vrije open source)
**Leverancier:** Conduction B.V.
**Platform:** Nextcloud + Open Register (self-hosted / on-premise / cloud)

## Legenda

| Status | Betekenis |
|--------|-----------|
| Beschikbaar | Functionaliteit is beschikbaar in de huidige versie |
| Gepland (MVP) | Gepland voor eerste release |
| Gepland (V1) | Gepland voor versie 1.0 |
| Gepland (Enterprise) | Gepland voor enterprise-versie |
| Via platform | Functionaliteit wordt geleverd door Nextcloud / OpenRegister |
| Op aanvraag | Beschikbaar als maatwerk |
| N.v.t. | Niet van toepassing voor dit product |

---

## 1. Functionele eisen

### Zaakbeheer (Case Management)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-01 | Zaken aanmaken, bekijken, wijzigen, verwijderen | Gepland (MVP) | Volledige CRUD met levenscyclus |
| F-02 | Zaakoverzicht met zoeken, sorteren en filteren | Gepland (MVP) | Lijst- en kaartweergave |
| F-03 | Zaakdetailpagina met tijdlijn | Gepland (MVP) | Visuele status-voortgang |
| F-04 | Zaaktype-systeem (configureerbaar) | Gepland (MVP) | Zaaktypen bepalen gedrag, statussen, termijnen |
| F-05 | Statusverloop (levenscyclus per zaaktype) | Gepland (MVP) | Configureerbare statusvolgorde |
| F-06 | Afhandeltermijnen (automatisch berekend) | Gepland (MVP) | Countdown en overschrijdingsmelding |
| F-07 | Subzaken (ouder/kind-hiërarchie) | Gepland (V1) | Complexe zaakstructuren |
| F-08 | Zaaksjablonen | Gepland (V1) | Gestandaardiseerde zaakaanmaak |
| F-09 | Zaak kopiëren | Gepland (V1) | Efficiëntie bij vergelijkbare zaken |
| F-10 | Bulk zaakbewerkingen | Gepland (Enterprise) | Schaaloperaties |

### Taakbeheer

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-11 | Taken aanmaken, toewijzen, afronden | Gepland (MVP) | Gekoppeld aan zaken |
| F-12 | Taaklijst met statusfilters | Gepland (MVP) | Werkvoorraad-overzicht |
| F-13 | Taaktoewijzing aan medewerkers | Gepland (MVP) | Werklastverdeling |
| F-14 | Taken met deadlines en prioriteiten | Gepland (MVP) | Tijdbeheer |
| F-15 | Kanban-bord voor taken | Gepland (V1) | Visueel taakbeheer |
| F-16 | Taaksjablonen per zaaktype | Gepland (V1) | Gestandaardiseerde werkstromen |
| F-17 | Automatische taakcreatie bij statuswijziging | Gepland (Enterprise) | Workflow-automatisering |

### Rollen & Deelnemers

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-18 | Behandelaar toewijzen aan zaak | Gepland (MVP) | Basistoewijzing |
| F-19 | Roltypen (initiatiefnemer, behandelaar, adviseur) | Gepland (MVP) | CMMN-rolmodel |
| F-20 | Meerdere deelnemers per zaak | Gepland (V1) | Teamcollaboratie |
| F-21 | Rolgebaseerde rechten per zaak | Gepland (V1) | Toegangscontrole |
| F-22 | Externe deelnemers | Gepland (Enterprise) | Cross-organisatie zaken |

### Besluiten & Resultaten

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-23 | Zaakresultaat vastleggen | Gepland (MVP) | Zaakafronding |
| F-24 | Besluiten registreren met ingangsdatum/einddatum | Gepland (V1) | Formele besluitvorming |
| F-25 | Resultaattypen per zaaktype | Gepland (V1) | Met archiveringsregels |
| F-26 | DMN-besluitbomen | Gepland (Enterprise) | Geautomatiseerde besluitlogica |

### Werkvoorraad & Dashboard

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-27 | Persoonlijke werkvoorraad (mijn zaken, mijn taken) | Gepland (MVP) | Productiviteitsoverzicht |
| F-28 | Dashboard met aantallen en statusverdeling | Gepland (MVP) | Management-informatie |
| F-29 | Overschrijdingen markering | Gepland (MVP) | Proactief beheer |
| F-30 | Cross-app werkvoorraad (inclusief Pipelinq) | Gepland (V1) | Geïntegreerd werkbeheer |
| F-31 | SLA-compliance meter | Gepland (Enterprise) | Dienstverlening kwaliteit |
| F-32 | Werklastverdeling heatmap | Gepland (Enterprise) | Capaciteitsplanning |

---

## 2. Technische eisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| T-01 | On-premise / self-hosted installatie | Beschikbaar | Nextcloud-app, volledig on-premise |
| T-02 | Open source (broncode beschikbaar) | Beschikbaar | AGPL licentie, GitHub |
| T-03 | RESTful API | Via platform | OpenRegister REST API |
| T-04 | Event-driven architectuur | Via platform | OpenRegister events |
| T-05 | Schaalbaarheid | Via platform | OpenRegister + Solr |
| T-06 | Database-onafhankelijkheid | Via platform | PostgreSQL, MySQL, SQLite |
| T-07 | Containerisatie (Docker) | Beschikbaar | Docker Compose |
| T-08 | MCP (AI-integratie) | Via platform | OpenRegister MCP |

---

## 3. Beveiligingseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| B-01 | Rolgebaseerde toegangscontrole (RBAC) | Via platform | OpenRegister RBAC |
| B-02 | Volledige audit trail | Via platform | OpenRegister mutatie-historie |
| B-03 | BIO-compliance | Via platform | Nextcloud BIO-certificering |
| B-04 | 2FA | Via platform | Nextcloud 2FA |
| B-05 | SSO / SAML / LDAP | Via platform | Nextcloud SSO |
| B-06 | DigiD | Via platform | Via SAML-koppeling |
| B-07 | Versleuteling (rust + transit) | Via platform | Nextcloud encryption + TLS |
| B-08 | Vertrouwelijkheidsniveaus op zaken | Gepland (V1) | Zaaktype-gebonden vertrouwelijkheid |
| B-09 | Veld-niveau toegangscontrole | Gepland (Enterprise) | Gevoelige gegevens bescherming |

---

## 4. Privacyeisen (AVG/GDPR)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| P-01 | Recht op inzage | Gepland (V1) | Data-export per betrokkene |
| P-02 | Recht op vergetelheid | Gepland (V1) | Zaak- en persoonsgegevens verwijdering |
| P-03 | Recht op rectificatie | Via platform | Object wijzigen via OpenRegister |
| P-04 | Bewaartermijnen | Gepland (Enterprise) | Automatische vernietiging |
| P-05 | Data minimalisatie | Beschikbaar | Schema-gebaseerd — alleen gedefinieerde velden |

---

## 5. Toegankelijkheidseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| A-01 | WCAG 2.1 AA | Gepland (MVP) | Nextcloud-componenten zijn WCAG AA |
| A-02 | EN 301 549 | Gepland (MVP) | Via WCAG AA |
| A-03 | Toetsenbordnavigatie | Gepland (MVP) | Volledig toetsenbord-navigeerbaar |
| A-04 | Screenreader | Gepland (MVP) | ARIA-labels |
| A-05 | NL Design System | Gepland (V1) | Via NL Design app |
| A-06 | Meertalig (NL/EN) | Gepland (MVP) | Volledige vertaling |

---

## 6. Integratiestandaarden

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| I-01 | Common Ground architectuur | Beschikbaar | Laag 4 (proces) bovenop OpenRegister (laag 2) |
| I-02 | ZGW Zaken API | Gepland (V1) | Mapping naar VNG Zaken API standaard |
| I-03 | ZGW Besluiten API | Gepland (V1) | Mapping naar VNG Besluiten API standaard |
| I-04 | ZGW Catalogi API | Gepland (V1) | Zaaktype-catalogus volgens VNG standaard |
| I-05 | StUF-koppeling | Via app | OpenConnector biedt StUF-vertaling |
| I-06 | Pipelinq-brug (verzoek-naar-zaak) | Gepland (V1) | CRM-naar-zaak workflow |
| I-07 | Federatie (cross-organisatie) | Gepland (Enterprise) | Gefedereerde zaakafhandeling |
| I-08 | Webhook-ondersteuning | Gepland (Enterprise) | Event-driven integratie |
| I-09 | Nextcloud Flows automatisering | Gepland (Enterprise) | Low-code triggers |

---

## 7. Archivering

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| AR-01 | Archiefwet 2021 | Gepland (Enterprise) | Archiveringsbeheer |
| AR-02 | Selectielijsten | Gepland (Enterprise) | Bewaartermijnen per zaaktype-resultaat |
| AR-03 | Vernietigingslijsten | Gepland (Enterprise) | Geautomatiseerde vernietiging |
| AR-04 | Overdracht aan e-depot | Op aanvraag | Exportformaat voor archiefdiensten |
| AR-05 | TMLO/MDTO-metadata | Gepland (Enterprise) | Via OpenRegister archiverings-metadata |
| AR-06 | Zaakdossier bevriezing | Gepland (V1) | Dossier op niet-wijzigbaar na afsluiting |

---

## 8. Beheer en onderhoud

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| BO-01 | Nextcloud App Store | Beschikbaar | Installatie via App Store |
| BO-02 | Automatische updates | Beschikbaar | Via Nextcloud app-updater |
| BO-03 | Beheerderspaneel | Gepland (MVP) | Zaaktypen, statussen, rollen configureren |
| BO-04 | Documentatie | Beschikbaar | Gebruiker/beheerder/developer docs |
| BO-05 | Open source community | Beschikbaar | GitHub Issues |
| BO-06 | Professionele ondersteuning (SLA) | Op aanvraag | Via Conduction B.V. |

---

## 9. Platform-voordelen (via Nextcloud)

| Functionaliteit | Beschrijving |
|-----------------|-------------|
| Bestanden & dossiers | Zaakdossier via Nextcloud Files (WebDAV, versiebeheer) |
| Agenda & taken | Deadlines in agenda, taken via CalDAV |
| Chat per zaak | Nextcloud Talk room per zaak |
| Notificaties | Statuswijzigingen, toewijzingen, deadlines |
| Activiteitenlogboek | Zaakgebeurtenissen in Nextcloud Activity |
| Federatie | Zaken delen tussen organisaties |
| Mobiele apps | iOS/Android toegang tot zaken |
| AI-assistent | Zaaksamenvattingen, tekst-extractie |
| Office-integratie | Documenten bewerken in Collabora/OnlyOffice |

---

## 10. Onderscheidende kenmerken

| Kenmerk | Toelichting |
|---------|-------------|
| **Nextcloud-native** | Geen apart systeem — case management in uw bestaande samenwerkingsplatform |
| **Lichtgewicht** | Geen Java/Spring stack — draait als Nextcloud-app |
| **CRM + Zaak in één** | Pipelinq (CRM) → Procest (zaak) is een unieke geïntegreerde workflow |
| **NL Design System** | Overheidshuisstijl via design tokens |
| **Data-hergebruik** | Zaakdata op OpenRegister is herbruikbaar door andere apps |
| **Gefedereerd** | Cross-organisatie zaakafhandeling via Nextcloud federatie |
| **~50% infrastructuur gratis** | Taken, bestanden, notificaties, chat — al ingebouwd |

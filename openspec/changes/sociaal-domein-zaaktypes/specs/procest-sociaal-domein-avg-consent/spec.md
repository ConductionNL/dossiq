# Spec: AVG & Consent infrastructure (cross-cutting for sociaal-domein)

**Tier:** sociaal-domein  
**Statutory basis:** GDPR (Algemene Verordening Gegevensbescherming, AVG), artikel 6 (lawfulness), 9 (special categories), artikel 30 (processing register), AVG artikel 15–22 (subject rights)  
**Scope:** Mandatory framework for all three sociaal-domein zaaktypes (WMO, Jeugdwet, Participatiewet)

## Scope

The sociaal-domein zaaktypes all process **special-category data** (AVG artikel 9): medical data, information about family circumstances, financial hardship, sometimes ethnic origin, religious belief, criminal history. Dutch law (UAVG artikel 23) permits public authorities to process this data under specific exemptions for social work and public health, but processing is only lawful if:

1. **Lawful basis exists** (AVG art. 6: typically 6.1.c "contract" or 6.1.e "public task")
2. **Legal exemption recorded** (AVG art. 9.2: e.g., 9.2.h "health/social care")
3. **Purpose is documented** (AVG art. 30 processing register)
4. **Retention schedule is set** (GDPR art. 5(1)(e) storage limitation; Dutch selectielijst provides schedules)
5. **Access is controlled** (Art. 32: technical & organizational measures; in our case, wijkteam-only, FG-audit override)
6. **Consent is obtained where needed** (Art. 7, esp. for sharing with third parties)
7. **Audit trail is maintained** (Art. 32: logs of access, processing, incidents)

This spec defines the **mandatory AvgClassificatie block** (embedded in every sociaal-domein zaak), the **Toestemming entity** (for consent-tracking on data-sharing), the **audit-logging framework**, and **access guards** that must be hardcoded into all zaak-read queries.

It is **not** a standalone zaaktype; it is a framework that all three sociaal-domein zaaktypes inherit.

## Entities

### AvgClassificatie (value type, not a separate entity)

Embedded in every WmoZaak, JeugdwetZaak, ParticipatiewetZaak at creation. It is **mandatory** and must be filled before save is allowed.

| Field | Type | Required | Description |
|---|---|---|---|
| categorieen | array (enum) | Y | Which AVG art. 9 categories are in this zaak? Choices: `medisch`, `gezinssituatie`, `financieel`, `justitieel`, `etnisch`, `religieus`, `politieke-overtuiging`. Usually 1–2 categories; rarely more. |
| bijzonderePersoonsgegevens | boolean | Y | Auto-flag: if any categorieen is selected, this is automatically `true`. Used for system audit. |
| rechtvaardiging | enum | Y | Legal AVG art. 9.2 exemption: `artikel-9-2-b` (employment law), `artikel-9-2-c` (data already public), `artikel-9-2-e` (vital interest), `artikel-9-2-h` (health/social care), `artikel-9-2-i` (vital public interest). Most sociaal-domein cases are 9.2.h or 9.2.i. |
| rechtvaardigingToelichting | string | Y | Plain-Dutch explanation why this exemption applies (for FG audit). Example: "Verwerking noodzakelijk voor medische beoordeling indicatiestelling WMO conform artikel 2.3.5 Wmo 2015." |
| bewaarTermijnJaren | integer | Y | Statutory retention schedule: WMO=15, Jeugdwet=20, Participatiewet=10. Sourced from selectielijst gemeenten 2020. |
| vernietigingDatum | date | Y | Auto-calculated: zaak-closure-date + bewaarTermijnJaren. Example: closed 2026-03-15 → vernietigingsDatum 2041-03-15. |
| toegangsBeperking | enum | Y | Access control mode: `alleen-behandelaar` (only assigned caseworker), `alleen-behandelaar-en-wijkteam` (caseworker + team), `alleen-werk-en-inkomentteam` (Participatiewet: specific team only). |
| anonimiseringBijDelen | boolean | Y | Should PII be auto-masked on export to external parties (unless toestemming exists)? Usually `true` for all sociaal-domein cases. |
| exportBeperking | enum | Y | Export limits: `geen-bulk-export` (no CSV downloads of sensitive data), `geen-export-zonder-toestemming` (can only export if consent recorded), `geen-export` (never export, only internal use). |

### Toestemming (consent for data-sharing)

Separate entity, linked to zaak. Created when a citizen/parent explicitly agrees to share their data with an external party for a specific purpose.

| Field | Type | Required | Description |
|---|---|---|---|
| zaakId | reference | Y | WmoZaak, JeugdwetZaak, or ParticipatiewetZaak |
| verleendDoorBsn | string | Y | Who gave consent? BSN of citizen (adult or parent/guardian) |
| verleendDoorNaam | string | Y | Display name of the consenting person |
| verleendDatum | date | Y | Date consent was recorded |
| geldigTot | date | N | Expiry date (optional; if omitted, indefinite until revoked) |
| intrekkingMogelijk | boolean | Y | Can the citizen revoke this consent? (Almost always `true` per AVG art. 7.3) |
| ingetrokken | boolean | N | Has it been revoked? |
| scope | object | Y | What exactly was consented to: `tePartijen` (list of organizations), `tegegevens` (list of specific data subsets), `tedoel` (purpose), `ingetrokken` (revoked?) |
| tePartijen | array | Y | Named external organizations: "Jeugdzorg West", "Basisschool De Vlinder", "GGD Noord", etc. |
| tegegevens | array | Y | Specific data subsets: "gezinsplan-doelen", "evaluatie-momenten", "MDO-samenvatting", "indicatiestelling", "vermogenstoets", etc. Not a blanket "all data" consent. |
| tedoel | string | Y | Purpose/context: "Afstemming jeugdhulp en schoolsituatie", "Voortgang reintegratie met UWV", etc. |
| vastgelegdViaKanaal | enum | Y | How was consent recorded? `gesprek-caseworker` (documented in case notes), `schriftelijk` (signed form), `digitaal` (online consent portal), `huisbezoek-gespreksverslag` (documented in home-visit minutes), etc. |
| bewijsBestandId | reference | N | Nextcloud file ID of proof (scanned consent form, audio recording, gespreksverslag excerpt, etc.) |

### AuditLog (framework, not a separate entity — logged per-read)

Implicit logging framework. Every read-action on a zaak with bijzondere persoonsgegevens creates a log entry (stored in openregister's immutable auditTrail or a dedicated sociaal-domein auditLog table).

| Field | Type | Logged | Description |
|---|---|---|---|
| zaakId | string | Y | Which case was accessed |
| medewerkerId | string | Y | Who accessed it (Nextcloud user UID) |
| organisatie | enum | Y | Where are they from? `gemeente` (internal), `externe-aanbieder` (named partner org), `overheid-ander` (other gov agency) |
| actie | enum | Y | What did they do? `read` (view), `export` (download), `share` (sent data externally), `delete` (requested destruction) |
| tijdstip | datetime | Y | When (ISO 8601, precise to second) |
| ipAdres | string | Y | Source IP (for anomaly detection, audit trail completeness) |
| geraadpleegdeVelden | array | Y | Which specific fields were accessed: `ondersteuningsvraag`, `indicatiestelling`, `vermogenstoets`, `gezinsplan`, etc. |
| autorisatieGrond | enum | Y | Why were they allowed to read? `roltoewijzing` (role on case), `wijkteam-membership` (team affiliation), `fg-audit-override` (special FG access), `subject-access-request` (citizen data request), `openconnector-sharing` (automated partner integration) |
| resultaat | enum | Y | Did the read succeed? `succes` (data returned), `geweigerd-geen-toegang` (blocked by access guard), `geweigerd-fg-audit` (FG-audit mode: metadata only), `geanonimiseerd` (returned anonymized due to lack of toestemming) |

### AvgIncident (optional, for DPA breach reporting)

If a data breach occurs (unauthorized access, loss, etc.), it must be recorded and potentially reported to the Autoriteit Persoonsgegevens (AP) within 72 hours (GDPR art. 33). This entity (optional per implementation) documents incidents.

| Field | Type | Description |
|---|---|---|
| incidentDatum | date | Date incident discovered |
| oorzaak | string | Root cause (e.g., "laptop diefstal", "email verstuurd naar verkeerd adres", "ongeautoriseerde toegang") |
| gegevensImpact | string | Which personal data was compromised? Which zaakken? Which citizens? |
| meldingAp | boolean | Was it reported to the Autoriteit Persoonsgegevens? |
| meldingDatum | date | If reported, when? |
| meldingReferentie | string | AP reference number |
| remediatingActions | array | Steps taken to prevent recurrence |

## Requirements

### REQ-AVG-001: Mandatory AvgClassificatie block at zaak creation

Every sociaal-domein zaak MUST declare its special-category data scope before creation is allowed.

**GIVEN** a WMO-consulent creates a new zaak  
**WHEN** they attempt to save without an `avgClassificatie` block  
**THEN** the system MUST reject the save with a validation error: "AVG-classificatie is verplicht. Vul in welke gegevenscategorieën in deze zaak worden verwerkt."

**GIVEN** the consulent fills in `categorieen = ["medisch"]`  
**WHEN** they save  
**THEN** the system MUST auto-populate:
- `bijzonderePersoonsgegevens = true` (flag for audit)
- `bewaarTermijnJaren` to the zaaktype default (15 for WMO, 20 for Jeugdwet, 10 for Participatiewet)
- `vernietigingsDatum` = (zaak closure date) + bewaarTermijnJaren
- Prompt consulent to select a `rechtvaardiging` (AVG 9.2 exemption) and provide `rechtvaardigingToelichting`

### REQ-AVG-002: Wijkteam-only access control with data-driven guards

Access to zaak content MUST be enforced at query time, not role-level alone.

**GIVEN** a zaak has `wijkteam = wijkteam-zuid` and `toegangsBeperking = alleen-behandelaar-en-wijkteam`  
**WHEN** a staff member from `wijkteam-noord` queries the zaak  
**THEN** the query-layer MUST:
- Check `user.wijkteam` against `zaak.wijkteam` (data-driven)
- If no match, return only: `zaak.zaakNumber`, `zaak.status`, `zaak.behandelaarId`, `zaak.aanvraagDatum`, `zaak.deadlineDate`
- Block access to all content fields: `ondersteuningsvraag`, `indicatiestelling`, `gezinsplan`, `vermogenstoets`, etc.
- Log the access attempt with `resultaat = geweigerd-geen-toegang`

**GIVEN** a staff member is marked as `tweedeBehandelaarId` on the zaak  
**WHEN** they query it  
**THEN** the query-layer MUST grant full access regardless of wijkteam membership.

**GIVEN** a functionaris gegevensbescherming queries a zaak with intent="audit"  
**WHEN** the query is made  
**THEN** the query-layer MUST:
- Return metadata + `auditLog` (list of all previous access)
- Block all zaak content fields (unless the FG explicitly escalates to full read)
- Log with `autorisatieGrond = fg-audit-override` and `resultaat = fg-audit-mode-metadata-only`

### REQ-AVG-003: Automatic anonymization on export without recorded consent

If data is exported (via API, openconnector, reporting) to an external party without a toestemming record, PII MUST be auto-masked.

**GIVEN** a zaak export is triggered (e.g., API call from openconnector to share with zorgaanbieder)  
**WHEN** the system checks for toestemming record(s) for that external party and finds none  
**THEN** the system MUST invoke `pii-detection-masking` (from openregister) to:
- Replace BSN with pseudonym (e.g., "zaak-id-client-001")
- Replace geboortedatum with age-group only ("60–65 jaar")
- Replace exact amounts with ranges (e.g., "€1000–1500/maand" instead of "€1234")
- Replace clinical diagnoses with functional impact summaries ("mobiliteitsbeperking" instead of "hernia discalis L4–L5")
- Replace family names with roles ("ouders", "familielid A", "familielid B")
- Replace named organizations with generic labels ("huida zorgaanbieder", "huidige school")

**GIVEN** a toestemming record DOES exist for the target organization  
**WHEN** the export is triggered  
**THEN** the system MUST send fully identified data + log the export with `autorisatieGrond = toestemming` and the toestemming reference.

### REQ-AVG-004: Toestemming tracking with revocation support

Whenever external parties need to see zaak content, explicit family/citizen consent must be recorded and revocable.

**GIVEN** a jeugdconsulent wants to share a gezinsplan with a school during an MDO  
**WHEN** the consulent prepares to share  
**THEN** the system MUST first check: is there a toestemming record for `tePartij = "school"`?

**GIVEN** no toestemming exists  
**WHEN** the consulent proceeds anyway  
**THEN** the system MUST:
- Display a warning: "Geen toestemming voor gegevensdeling met [school]. Gegevens worden geanonimiseerd."
- Log the share with `resultaat = geanonimiseerd`

**GIVEN** a toestemming record exists but the citizen later revokes it  
**WHEN** they click "Intrekken" (revoke) in their consent panel  
**THEN** the system MUST:
- Set `toestemming.ingetrokken = true`
- Log the revocation
- For future exports to that party, treat as if no toestemming exists (auto-anonymize)
- Create a notification task for the caseworker: "Toestemming ingetrokken voor [party]; review what data is currently shared and plan follow-up contact"

### REQ-AVG-005: Comprehensive audit logging of all data access

Every read-action on special-category data MUST be logged.

**GIVEN** a WMO-consulent opens a zaak with `categorieen = ["medisch"]`  
**WHEN** the zaak is displayed  
**THEN** the system MUST immediately create an audit-log entry with:
- `zaakId`, `medewerkerId`, `organisatie=gemeente`, `actie=read`, `tijdstip` (precise timestamp), `ipAdres`
- `geraadpleegdeVelden`: which specific fields were accessed (ondersteuningsvraag, indicatiestelling, etc.)
- `autorisatieGrond=roltoewijzing` (they are the assigned caseworker)
- `resultaat=succes` (read allowed)

**GIVEN** an externe zorgaanbieder is given read-access to a gezinsplan via openconnector (automated API, under toestemming)  
**WHEN** they access the data  
**THEN** the system MUST log:
- `zaakId`, `medewerkerId=null` (external party has no gemeente UID), `organisatie=Jeugdzorg-West`, `actie=read`, `tijdstip`, `ipAdres`
- `geraadpleegdeVelden`: ["gezinsplan", "evaluatie-momenten"] (as per toestemming.tegegevens)
- `autorisatieGrond=openconnector-sharing`
- `resultaat=succes`

### REQ-AVG-006: Statutory retention with deadline-driven destruction proposals

Every zaak's destruction deadline MUST be tracked and reviewed before actual deletion.

**GIVEN** a WmoZaak is closed on 2026-03-15 with `bewaarTermijnJaren=15`  
**WHEN** the zaak is saved  
**THEN** the system MUST set `vernietigingsDatum=2041-03-15`.

**GIVEN** it is now 2041-02-20 (within 30 days of destruction deadline)  
**WHEN** a daily batch job runs  
**THEN** the system MUST:
- Generate a `vernietigingsvoorstel` task assigned to the gemeente archivaris
- Notify: "Zaak [number] (gesloten 2026-03-15, retentie 15 jaar) kan op 2041-03-15 worden vernietigd. Goedkeuring archivaris vereist."
- The archivaris reviews and approves (or requests an uitzonderingsgrond for extended retention)

**GIVEN** the archivaris approves destruction  
**WHEN** the deadline date passes  
**THEN** the system MUST:
- Flag the zaak as `archiveStatus=destroyed` (or actually delete, depending on gemeente policy)
- Log the destruction with `actie=delete` + timestamp + archivaris approval reference

### REQ-AVG-007: Subject-access-request (burgerrecht) support

Citizens have the right (AVG art. 15) to request a copy of all data held about them. The system MUST support generating these reports.

**GIVEN** a citizen submits a subject-access-request (SAR) to the gemeente  
**WHEN** the FG processes the request  
**THEN** the system MUST be able to:
- Query all zaakken (WmoZaak, JeugdwetZaak, ParticipatiewetZaak) for that BSN
- Retrieve all related entities (Indicatiestelling, Gezinsplan, ReIntegratieTraject, MdoOverleg, Toestemming)
- Retrieve all attached documents (from case.files)
- Retrieve the complete auditLog (who accessed the data and when)
- Generate a report PDF in plain Dutch, organized chronologically and by category
- Mark all documents/log entries with "SAR 2026-05-15 [SAR ref number]" for tracking

### REQ-AVG-008: Data breach (incident) reporting support

If a breach occurs (unauthorized access, loss, etc.), it must be documented and potentially reported to the Autoriteit Persoonsgegevens (AP) within 72 hours.

**GIVEN** a data breach is discovered (e.g., laptop containing unencrypted zaakken data is stolen)  
**WHEN** the incident is logged in the system  
**THEN** the system MUST:
- Create an AvgIncident record with: `incidentDatum`, `oorzaak`, `gegevensImpact` (which zaakken/citizens affected)
- Calculate whether GDPR art. 33 notification to AP is required (risk assessment: encryption status, data scope, number of affected citizens)
- If notification required: flag `meldingAp=true` and create a task for DPA to complete notification within 72 hours
- Generate a breach-impact summary for gemeente leadership (# citizens affected, data categories, etc.)

## Design notes

### Why embed AvgClassificatie in zaak (not separate)?

The classification MUST be filled at zaak creation because:
- Legally: the gemeente must know *at the moment of data collection* whether it has a lawful basis (GDPR art. 5(2) transparency)
- Operationally: retention duration depends on the data type (15 vs. 20 vs. 10 years); this must be known at closure
- Auditing: when data-sharing consent is requested, the system must already know what categories are in the case

Storing it as a separate entity would allow zaakken to exist without classification, which is a legal risk.

### Why wijkteam-based access, not just RBAC?

Standard RBAC (role-based access control) allows all staff with the "view case content" role to see all cases. For sociaal-domein, this is too permissive:

- A WMO-consulent in wijkteam-zuid should see their team's cases, not another team's (data protection by organizational structure)
- A functionaris gegevensbescherming needs special FG-audit mode (metadata + auditLog, no content) for privacy self-audits
- An external partner (zorgaanbieder) should see *only* the specific fields they have consent for

Data-driven guards (checking zaak.wijkteam at query time) enforce this.

### Why auto-anonymization on export?

Sociaal-domein data is high-risk. Whenever it leaves the gemeente's direct control (exported for statistical analysis, shared with external partner), it must be protected. Auto-anonymization:

- Reduces re-identification risk if the data is later compromised
- Supports open data / statistical reporting (gemeente can publish figures without exposing individuals)
- Gives citizens control: if they revoke toestemming, future shares are automatically anonymized

### Why immutable auditLog?

Every access must be permanently logged (not editable, deletable, or hideable) because:
- Subject-access-requests require a complete history: "who has seen my data?"
- FG-audits require proof that only authorized staff accessed sensitive files
- Breach investigations require a forensic trail
- GDPR art. 32(4) requires technical measures for accountability

Immutability is enforced at the database/register layer.

## Integration points

- **openregister:** Provides RBAC, immutable auditTrail, retention scheduling, `pii-detection-masking`
- **openconnector:** Consumes toestemming + AvgClassificatie to decide whether to anonymize outbound data
- **opengateway/FG panel:** FG can query audit logs, generate SAR reports, assess incidents
- **mydash:** Wijkteam dashboard filters cases by wijkteam membership (data-driven access)
- **procest notifications:** Destruction-deadline reminders, toestemming-revocation alerts, breach alerts

## Regulatory references

- **GDPR (AVG):** Art. 5 (principles), 6 (lawfulness), 9 (special categories), 15–22 (subject rights), 30 (processing register), 32 (security)
- **Dutch Data Protection Act (UAVG):** Art. 23 (processing for public tasks), contextual rules for gemeenten
- **Selectielijst gemeenten 2020:** Mandatory retention schedules for different zaaktype categories (WMO 15 yr, Jeugdwet 20 yr, etc.)
- **Handboek Functionaris Gegevensbescherming Gemeenten (VNG):** Audit guidelines, privacy-by-design principles
- **Convenant Gegevensuitwisseling Sociaal Domein (VNG):** Best practices for inter-agency data-sharing in sociaal domein
- **NEN 7510 / NEN 7512 / NEN 7513:** Information security standards in healthcare/social services (reference for access control design)


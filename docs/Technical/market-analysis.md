# Market analysis

This page positions dossiq against the systems a municipality or an SMB would otherwise buy. It was first written before any competitor had been installed, and it read like it: every landscape table was a set of claims from feature pages. Since September 2026 the numbers on this page are measured. Twenty-eight columns stand in the parity ledger, twenty-four of them installed and driven, and every score below is a `yes` count on the 180 domain-neutral rows, as of ledger v7 plus batch 11 (2026-09-13). The method, the counting rule, the statutory split and the full ranking are on [competitor parity, September 2026](../research/competitor-parity-2026-09.md); the links behind every cell are on [competitor sources](../research/competitor-sources.md).

## Summary

Case management is coordination: tracking work, assigning it, meeting deadlines, managing documents and recording decisions. Nextcloud already provides tasks, files, chat, calendar and activity, and dossiq orchestrates those rather than rebuilding them. That is still the positioning, and the driving confirmed the part of it that can be measured: Deck, a kanban board on the same host, passes seven of the 23 document rows through one fact, that an attachment is a file in Files, and dossiq gets six of those on the same host for the same price.

What the driving refuted is the claim that nothing else occupies the space. OpenCase, a Danish municipal case system on Nextcloud, scores 58 of 180. Two forks of a German service desk, OTOBO and Znuny, and the project module of a Belgian ERP, Odoo, score 78, 73 and 78 against dossiq's 71. Frappe Helpdesk passes us by a single row, 72. dossiq is seventh of twenty-four driven columns on the comparable half, behind xxllnc Zaken at 125 and GZAC at 82 in its own family. Where dossiq leads is where the law is: 6 of 7 decision rows, 8 of 10 deadline rows, and 13 statutory `yes` answers that no non-Dutch system scores at all.

## What changed since July

The claims this page made before the driving, and what the corpus found. Rows cite `procest/…` files in `ConductionNL/market-intelligence`, or the gap register at `procest/_gaps/`.

| the page said | what was measured | replaced by |
|---|---|---|
| "No case management solution exists in Nextcloud." | OpenCase 1.1.0 (Lamotech) is a municipal case system on Nextcloud, driven in round 2: 62 yes of 206, 58 of 180. Deck scores 37 of 180 (`_round4/compare/eighteen-system-tally.md`). | The Nextcloud ecosystem table below, with both columns. |
| "Nextcloud Deck: Kanban board for tasks, not case management." | Measured at 37 of 180, level with Kanboard, and the only non-Dutch column to pass seven document rows (`nextcloud-deck/round4/open-core.md`). A card is not a case, which is eleven honest `no` answers. | The same table. |
| "Jira Service Management: developer-friendly ITSM, not case management." | Rated from its documentation at 83 of 180, above every driven non-Dutch column, graded `documented, not driven` and printed as an upper bound (`_round4/compare/findings-batch8.md`). | The issue and ticket family table. |
| Redmine as the open-source comparison a buyer will name. | A buyer comparing dossiq to "Redmine" is quite likely being shown Easy Redmine 16.0, rated from documents at 86 of 180. Its core is Redmine under GPL-2.0; everything Easy wrote sits beside it under a commercial licence the cloud buyer may not even read (`_round4/compare/open-core-batch10.md`). | The issue and ticket family table, and the eleventh open-core shape. |
| "Valtimo/GZAC: proprietary core (Ritense)." | The monorepo is public under EUPL-1.2 and was driven: 86 of 206, 82 of 180. Open core only partly: two closed-published npm packages in the release build (`valtimo/round2/`). | The Dutch government table. |
| "OpenZaak: API-only, no end-user UI." | OpenZaak itself was not driven. Its municipal frontend, Dimpact ZAC, was read: 69 yes, 61 partial, 76 no on 206, and it owns no data, so 26 of its 76 misses cite an external component (`zac/round3/`). | The Dutch government table, ZAC as a column with its caveat. |
| "No CRM-to-case flow: no competitor has native request-to-case conversion with a built-in CRM." | Row 1.3, create a case from a contact record: xxllnc Zaken `yes`, dossiq `partial`, owner dossiq (`_gaps/gap-register.md`). Odoo ties the top of the non-Dutch corpus from a platform whose CRM sits in the same tree. | Withdrawn. The row is a dossiq gap. |
| "Federated cross-org cases: no case system has this. Only Dossiq can share case data across organizations." | Never measured as a matrix row. | Withdrawn as a superlative; kept below as a design claim, marked unmeasured. |
| "Calendar-native deadlines" as an advantage. | The deadline engine is the largest measured defect: 30.4% of the terms dossiq stores land on a Saturday, Sunday or Dutch holiday, and nothing rolls them to the next working day as the Algemene termijnenwet requires (`_round4/compare/deadline-engines.md`). OpenProject has the best engine in the corpus. | The risks table, first row. |
| "Talk rooms per case: no BPM engine has this." | Not measured. What round 2 did measure: no competitor composes another product into the case page, which dossiq does with mail, appointments, decisions and hours as leaf tabs (`_round2/compare/M3-integrations.md`). | Replaced by the measured sentence. |
| "Heavyweight deployment: BPM engines require Java/Spring stacks." | The four non-Dutch systems above dossiq are Perl (OTOBO, Znuny), Python (Odoo) and Python on a low-code framework (Frappe Helpdesk). Deployment weight decided nothing in the ranking. | Dropped from the gap table. |
| "~40-50% infrastructure free." | An unmeasured percentage. The measured version is Deck's seven document rows through Files, six of which dossiq gets on the same host (`_round4/compare/fourteen-system-tally.md`). | The measured sentence. |
| Risk: "Feature gap vs enterprise BPM: high." | Camunda and Flowable were never driven. The measured risk is elsewhere: two help-desk forks and an ERP's project module outscore dossiq on the comparable half, and dossiq is the only `no` in the ledger on watchers (13.18). | The risks table. |
| Enterprise SaaS prices per user per month. | Unverified marketing figures. ServiceNow is `docs only` in the ledger and was never rated; Monday.com, Power Automate and Kissflow are not in the ledger at all. | Removed. |

Two July workup claims the register also corrected: "Zaaktype versioning" was marked shipped, and the matrix reads a version chain on `workflowTemplate` only (row 2.3, narrowed to S by the re-read); "Delegation / vervanging & waarneming" was marked shipped, and the registration exists while the list half has no call site (row 13.17). Both are in the [re-read](../research/competitor-gap-re-read-2026-09-13.md) and the [OpenSpec umbrella](https://github.com/ConductionNL/dossiq/pull/2643).

## 1. Competitive landscape

Scores are `yes` of the 180 domain-neutral rows, with the combined 206-row figure in brackets where the corpus prints it. `driven` means installed, seeded with Dutch municipal cases and driven in a browser; `documented` means rated from documentation and printed as an upper bound; `not driven` means the system is landscape only and carries no score.

### Nextcloud ecosystem

| system | class | 180 | open core | what was found |
|---|---|---|---|---|
| OpenCase 1.1.0 (Lamotech) | driven | 58 (62 of 206) | yes: a separate non-public enterprise edition holds AI, digital post, CPR and CVR | A Danish municipal case system on Nextcloud, and the only case system on dossiq's host. Strong on documents (13 of 23) and parties (7 of 13); cannot list overdue cases. |
| Nextcloud Deck 1.18.4 | driven | 37 | no, and not a shape: nothing is withheld | Seven document rows through Files, versions, expiring shares, lock, trash, preview and file manager. No field, number, party, term or result on a card. |
| Nextcloud Tasks, Nextcloud Forms | not driven | | | CalDAV task items and an intake form builder. No case lifecycle, roles or decisions. Unmeasured. |

### Dutch government (zaakgericht werken)

Round 2 drove four Dutch systems against the 206-row matrix (`_round2/compare/M1-functionality.md`); round 3 read Dimpact ZAC against the same rows. The 180-row figures are from `_round4/compare/twenty-four-system-tally.md`.

| system | class | 180 | 206 (yes / partial / no) | open core | what was found |
|---|---|---|---|---|---|
| xxllnc Zaken (Zaaksysteem) | driven | **125** | 136 / 40 / 30 | not established | The top of the corpus. Documents 18 of 23, parties 11 of 13, search 10 of 12, intake 9 of 13. Three UI generations in one product; fifteen clicks to create a case. |
| GZAC / Valtimo 13.4.1 | driven | 82 | 86 / 55 / 65 | partly: two closed-published npm packages | Configuration 18 of 24 and tasks 14 of 19 on a BPMN engine (Operaton). An Admin group with 13 children; twelve clicks to create a case; cannot list overdue cases. |
| dossiq | driven | 71 | 84 / 87 / 35 | no: 0 entitlement hits | Decisions 6 of 7 and deadlines 8 of 10 lead every column; documents 7 of 23 and search 4 of 12 do not. Four clicks to create a case. |
| OpenCase 1.1.0 | driven | 58 | 62 / 46 / 98 | yes, see above | See the Nextcloud table. |
| Dimpact ZAC 1.0.316 | read, not driven | | 69 / 61 / 76 | not established | A case component over Open Zaak. It owns no data: the case, its documents and its retention live in Open Zaak, so 26 of its 76 misses cite an external component and the column reads low for a structural reason. |
| OpenZaak, Rx.Mission, Decos JOIN, Mozard, PinkRoccade iZaaksuite, Atabix, Visma Circle | not driven | | | Round 1 scouting material exists in the corpus per vendor. OpenZaak is the API component ZAC fronts; the others are commercial and were never installed. |

What round 2 concluded in words (`_round2/compare/M1-functionality.md`, "Where all three rivals have it and we do not"): the set of rows every Dutch rival passes and dossiq fails was seven at the reading and reduces to at most two, probably one, once stale cells are corrected: a one-click claim on a case (row 2.4), which all three give a handler and dossiq still makes them get by editing a field. The [re-read](../research/competitor-gap-re-read-2026-09-13.md) confirms 2.4 as a gap and opens `case-claim-action`.

Where dossiq is ahead of all three, measured in the round 2 files (`data.strengths` in the ledger): a deadline countdown and an overdue list on every case, where OpenCase and GZAC cannot list overdue cases at all; configuration in the settings gear rather than the menu; one index-and-detail pattern for every collection; case creation in four clicks against fifteen and twelve; a cross-type case index with a folder sidebar; and leaf tabs from other apps rendered inside the case.

### Issue and ticket systems, the third family

Set by Ruben on 2026-09-12: issue trackers, help desks and boards are a third competitor family, closer to dossiq in daily use than a BPM engine. Twenty were installed and driven and four rated from documents (`_round4/compare/README.md`). The open-core column names the shape from the twelve the round found; the result page explains each.

| system | family | licence | class | 180 | open core |
|---|---|---|---|---|---|
| OTOBO 11.0.17 | ticket | GPL-3.0 | driven | **78** | no: 45 free packages, none priced; four core capabilities ship switched off |
| Odoo 19.0 Community | project management | LGPL-3.0 | driven | **78** | yes, by absence with a label: 21 `to_buy` records and 45 upgrade badges for a private Enterprise tree |
| Znuny 7.3.6 | ticket | AGPL-3.0 and GPL | driven | **73** | no: 37 vendor packages, none priced |
| Frappe Helpdesk 1.30.1 | ticket | AGPL-3.0 | driven | **72** | no: AGPL across both repositories, zero hits for a key or a paywall; a thin app on a thick framework |
| Request Tracker 5.0.10 | ticket | GPL-2.0 | driven | 65 | no: nothing withheld, the extensions on CPAN under the same licence, the vendor selling hours and hosting |
| iTop 3.2.3 | ITSM and CMDB | AGPL-3.0 | driven | 58 | yes, as a catalogue: 33 closed extensions on the store, none in the tree |
| GLPI 11.0.8 | ITSM help desk | GPL-3.0 | driven | 56 | no |
| osTicket 1.18.4 | ticket | GPL-2.0 | driven | 55 | no; eight free capabilities in a separate, stale repository |
| Zammad 7.1.3 | help desk | AGPL-3.0 | driven | 53 | no |
| OpenProject 16.6.10 | issue and project | GPL-3.0 | driven | 46 | yes: 32 features gated at runtime, four degrading silently |
| Redmine 7.0.1 | issue | GPL-2.0 | driven | 43 | no; plugins and a proprietary fork sold beside it |
| Nextcloud Deck 1.18.4 | kanban | AGPL-3.0 | driven | 37 | no, not a shape |
| Kanboard 1.2.54 | kanban | MIT | driven | 37 | no: 163 third-party plugins, none priced in the directory |
| GitLab CE 19.3.2 | forge issues | MIT | driven | 36 | yes, by subtraction: the paid code removed at build time, 1,616 empty seams |
| Vikunja 2.6.0 | task | AGPL-3.0 | driven | 35 | yes, a gate that hides: three paid features in the AGPL tree answering 404 by design |
| Taiga 6.10.2 | agile project management | MPL-2.0 | driven | 32 | no, and not a shape: zero hits, the vendor sells hosting of this code. Telemetry is on by default and sends the instance's own URL nightly |
| Forgejo 16.0.4 | forge issues | GPL-3.0 | driven | 23 | no; a closed derivative is unavailable by design |
| Gitea 1.27.3 | forge issues | MIT | driven | 23 | no gate anywhere, and the twelfth shape: the steward sells Gitea Enterprise and Gitea Cloud, with no trace of either in the tree |
| Plane Community 1.4.2 | issue | AGPL-3.0 | driven | 22 | yes: the paid features are absent, only the seams remain |
| FreeScout 1.8.240 | ticket | AGPL-3.0 | driven | 22 | yes: 72 of 75 modules priced, the paywall through the free half |
| Easy Redmine 16.0 | issue and project | GPL-2.0 core, ESCLv2.0 layer | documented | 86, upper bound | yes, the eleventh shape: a source-available proprietary layer on a GPL core, sold as open source; 22 cells name the Platform plan and 9 an add-on |
| Jira Service Management Cloud | ITSM | proprietary | documented | 83, upper bound | yes, by plan: a plan table, 16 cells Premium, 8 Enterprise |
| YouTrack 2026.2 | issue | proprietary | documented | 78, upper bound | no: nothing withheld above ten users and three agents |
| Jira Software Data Center 11.3 | issue | proprietary | documented | 64, upper bound | no above the user tier; the service half is a second licence, and sales to new customers ended on 2026-03-30 |

Gitea and Forgejo are one product for every question in the matrix: 23 yes and 48 partial each, and 0 of 206 rows differing. Batch 12 is running, Huly and Tuleap, and closes the undriven set.

Three things the family says as a whole. A product built around a team's own work has no party, no document record, no decision and no term engine, which is 53 rows between four sections; the help desks do better than the boards because a help desk is built around somebody outside the organisation. Every non-Dutch column scores 0 of 7 on decisions, driven and documented alike. And copyleft proves nothing about what you can deploy: most of these trees are GPL, LGPL or AGPL, and among them sits every open-core shape a grep has found.

### BPM engines and case management platforms

None of these was driven. They stay on the page as landscape because they are what a tender names, and they carry no score.

| system | positioning | why it is not a column |
|---|---|---|
| Camunda 8 | process orchestration, BPMN and DMN | A process engine, not a case system; the rows it would score are section 3 and 11. Corpus scouting under `camunda/`. |
| Operaton | the open-source fork of Camunda 7 | The engine under GZAC, already measured through it. |
| Flowable | BPM with CMMN 1.1 | Not installed. Corpus scouting under `flowable/`. |
| Bonita, jBPM, ProcessMaker | low-code BPM | Not installed. |
| Appian, Pega | commercial low-code platforms | Closed, no trial without a sales process. |
| ArkCase, CaseFabric, Alfresco | case and content management | ArkCase was named for the first time in round 4 and is the next candidate outside the three families; Alfresco is archived. |

### Enterprise SaaS

Cloud only. ServiceNow and the ITSM products in the last row are `docs only` in the ledger, included for landscape and never as evidence; Monday.com, Power Automate and Kissflow are not in the ledger and stay here only because a tender names them.

| system | positioning | why not, for a municipality |
|---|---|---|
| ServiceNow | ITSM market leader | SaaS only, no self-hosting, no Dutch statutory rows. |
| Monday.com | work management | SaaS only, no case model. |
| Microsoft Power Automate | low-code flows on M365 | M365 dependency; data sovereignty is the buyer's question, not a product row. |
| Kissflow | workflow builder | SaaS only. |
| Zendesk, Freshservice, TOPdesk, HaloITSM, InvGate, TeamDynamix, ALVAO | ITSM and help desk | SaaS or closed; on the ledger's candidate list, unrated. |

## 2. Feature matrix

The tiering below (MVP, V1, Enterprise) was written before the driving and is kept as the product's own map of its scope. It is not a comparison. The build order that the comparison produced is the gap register's [five to build first](../research/competitor-parity-2026-09.md#the-five-to-build-first) and the OpenSpec umbrella.

### Case management

| Feature | Tier | Justification |
|---------|------|---------------|
| Case CRUD with lifecycle | **MVP** | Core entity |
| Case list with search, sort, filters | **MVP** | Navigation |
| Case detail view with timeline | **MVP** | Critical UX pattern |
| Status timeline visualization on case detail | **MVP** | Visual progress showing passed/current/future statuses |
| Case deadline countdown (days remaining / days overdue) | **MVP** | At-a-glance urgency indicator |
| Quick status change from case list view | **MVP** | Common pattern: change status without opening detail |
| Case type system (configurable) | **V1** | Flexible case definitions |
| Sub-cases (parent/child hierarchy) | **V1** | Complex case structures |
| Document completion checklist (case detail) | **V1** | Shows which required documents are present vs missing |
| Property completion indicator | **V1** | Percentage of required custom fields filled |
| Days in current status indicator | **V1** | Shows how long a case has been in current phase |
| Case templates | **V1** | Standardized case creation |
| Case cloning | **V1** | Efficiency for similar cases |
| Configurable status workflows per type | **Enterprise** | Organization-specific lifecycles |
| CMMN runtime (sentries, entry/exit criteria) | **Enterprise** | Advanced case automation |
| Bulk case operations | **Enterprise** | Scale operations |

### Task management

| Feature | Tier | Justification |
|---------|------|---------------|
| Task CRUD linked to cases | **MVP** | Core work tracking |
| Task list with status filters | **MVP** | Workflow overview |
| Task assignment to users | **MVP** | Workload distribution |
| Task due dates and priorities | **MVP** | Time management |
| Task checklist (sub-items) | **V1** | Detailed work breakdown |
| Task dependencies (blocked by) | **V1** | Sequencing work |
| Kanban board view for tasks | **V1** | Visual task management |
| Task templates per case type | **V1** | Standardized workflows |
| Automated task creation on status change | **Enterprise** | Workflow automation |
| Workload dashboard (tasks per user) | **Enterprise** | Management visibility |

### Status and lifecycle

| Feature | Tier | Justification |
|---------|------|---------------|
| Status tracking (current phase) | **MVP** | Core lifecycle |
| Status history (audit trail) | **MVP** | Accountability |
| Configurable status types | **V1** | Organization-specific phases |
| Status change notifications | **V1** | Immediate feedback |
| Status-based access control | **Enterprise** | Phase-dependent permissions |
| SLA tracking (time in status) | **Enterprise** | Service quality |

### Roles and participants

| Feature | Tier | Justification |
|---------|------|---------------|
| Assign handler to case | **MVP** | Basic assignment |
| Role types (initiator, handler, advisor) | **MVP** | CMMN role model |
| Multiple participants per case | **V1** | Team collaboration |
| Role-based permissions per case | **V1** | Access control |
| Automatic role assignment rules | **Enterprise** | Scale operations |
| External participant support | **Enterprise** | Cross-organization cases |

### Results and decisions

| Feature | Tier | Justification |
|---------|------|---------------|
| Case result recording | **MVP** | Case closure |
| Decision CRUD linked to cases | **V1** | Formal decision tracking |
| Decision with effective/expiry dates | **V1** | Legal validity periods |
| Result types (configurable) | **V1** | Classification |
| Decision templates | **Enterprise** | Standardized decisions |
| DMN decision tables | **Enterprise** | Automated decision logic |

### Case type system

| Feature | Tier | Justification |
|---------|------|---------------|
| Case type CRUD (admin) | **MVP** | Core behavioral configuration |
| Case type controls allowed statuses | **MVP** | Status lifecycle per type |
| Case type controls processing deadline | **MVP** | Automatic deadline calculation |
| Case type draft/published lifecycle | **MVP** | Safe configuration changes |
| Case type validity periods (validFrom/validUntil) | **MVP** | Version management |
| Case type controls allowed roles | **V1** | Role restriction per type |
| Case type controls result types (with archival rules) | **V1** | Outcome classification |
| Case type custom property definitions | **V1** | Organization-specific fields |
| Case type required documents per status | **V1** | Compliance controls |
| Case type decision type definitions | **V1** | Decision classification |
| Case type confidentiality defaults | **V1** | Security defaults |
| Case type suspension/extension rules | **V1** | Deadline management |
| Case type sub-case type restrictions | **Enterprise** | Hierarchical control |
| Case type versioning chains | **Enterprise** | Auditable type evolution |
| Case type import/export | **Enterprise** | Share types across instances |

### My work (werkvoorraad)

| Feature | Tier | Justification |
|---------|------|---------------|
| Personal workload view (my cases, my tasks) | **MVP** | Productivity essential |
| Sort by priority and due date/deadline | **MVP** | Task prioritization |
| Filter by entity type (cases, tasks) | **MVP** | Focused views |
| Overdue item highlighting | **MVP** | Proactive management |
| Cross-app workload (include Pipelinq leads/requests) | **V1** | Unified work queue |
| Workload analytics (items per user) | **Enterprise** | Management visibility |

### Admin settings

| Feature | Tier | Justification |
|---------|------|---------------|
| Nextcloud admin settings page | **MVP** | App configuration |
| Case type management UI | **MVP** | Core configuration |
| Status type management per case type | **MVP** | Lifecycle configuration |
| Default case type selection | **MVP** | Out-of-box experience |
| Result type management per case type | **V1** | Outcome configuration |
| Role type management per case type | **V1** | Role configuration |
| Property definition management | **V1** | Custom field configuration |
| Document type management | **V1** | Document requirement configuration |
| Decision type management | **V1** | Decision configuration |
| Confidentiality level visibility | **Enterprise** | Security customization |

### Communication and collaboration

| Feature | Tier | Justification |
|---------|------|---------------|
| Internal notes on cases (ICommentsManager) | **MVP** | Collaboration basics |
| Shared case views (multi-user access) | **MVP** | Team case management |
| Talk integration (per-case chat, IBroker) | **V1** | Real-time discussion |
| Calendar integration (deadlines, IManager) | **V1** | Deadline visibility |
| Activity stream (case events, IManager) | **V1** | Unified timeline |
| Notifications (assignment, status, deadline) | **V1** | Immediate feedback |
| User mentions in notes | **V1** | Team collaboration |
| Email notifications on case updates | **V1** | External communication |
| Email templates per case type | **Enterprise** | Standardized correspondence |

### Document management

| Feature | Tier | Justification |
|---------|------|---------------|
| File attachments on cases (IRootFolder) | **V1** | Document management |
| Shared folder per case (Files) | **V1** | Case dossier |
| Document categorization | **V1** | Classification |
| Document versioning (via Nextcloud) | **V1** | Audit trail |
| Document templates per case type | **Enterprise** | Standardized documents |
| Digital signature integration | **Enterprise** | Legal validity |

### Reporting and analytics

| Feature | Tier | Justification |
|---------|------|---------------|
| Dashboard with case counts and status overview | **MVP** | At-a-glance visibility |
| Case status distribution chart | **MVP** | Visual overview |
| List/table export (CSV) | **V1** | Data portability |
| KPI dashboard (avg processing time, open cases) | **V1** | Management visibility |
| Case type breakdown chart | **V1** | Distribution of open cases by type |
| Average processing time per case type | **V1** | Performance metric per type |
| Overdue case alerts | **V1** | Proactive management |
| SLA compliance meter (% cases meeting deadline) | **Enterprise** | Service quality tracking |
| Case type performance comparison | **Enterprise** | Compare avg time, completion rates |
| Handler workload heatmap | **Enterprise** | Visualize case distribution across handlers |
| Custom report builder | **Enterprise** | Flexible analytics |
| Trend analysis (case volume over time) | **Enterprise** | Strategic planning |

### Security and compliance

| Feature | Tier | Justification |
|---------|------|---------------|
| RBAC via OpenRegister | **MVP** | Access control |
| Full audit trail (who changed what, when) | **MVP** | Accountability |
| WCAG AA compliance | **MVP** | Government requirement |
| Confidentiality levels on cases | **V1** | Sensitive case handling |
| GDPR data export (right of access) | **V1** | EU compliance |
| GDPR data deletion (right to erasure) | **V1** | EU compliance |
| NL Design System theming | **V1** | Government visual compliance |
| Data retention policies | **Enterprise** | Compliance automation |
| Archival management (archiefwet) | **Enterprise** | Dutch archival law |
| Field-level access control | **Enterprise** | Sensitive data protection |

### Integration

| Feature | Tier | Justification |
|---------|------|---------------|
| Pipelinq bridge (request-to-case) | **V1** | CRM-to-case workflow |
| ZGW Zaken API mapping | **V1** | Dutch gov interop |
| ZGW Besluiten API mapping | **V1** | Dutch decision interop |
| ZGW Catalogi API mapping | **V1** | Dutch type catalog interop |
| External REST API | **V1** | OpenRegister provides this |
| Nextcloud Flows automation | **Enterprise** | Low-code triggers |
| Webhook support | **Enterprise** | External integration |
| Federated case sharing | **Enterprise** | Cross-organization cases |

### Customization

| Feature | Tier | Justification |
|---------|------|---------------|
| Configurable list columns | **V1** | UI flexibility |
| Custom fields per case type (OpenRegister schema) | **V1** | Organization-specific needs |
| Saved views/filters | **V1** | User productivity |
| Custom dashboards | **Enterprise** | Personalized views |
| Public intake form (citizen-facing) | **Enterprise** | External case submission |
| Workflow designer (visual) | **Enterprise** | Admin-configured automation |

## 3. Gap analysis

Every line here names a row or a file. The full per-area table is on the [result page](../research/competitor-parity-2026-09.md#the-shape-per-area).

### What competitors do well, measured

- **xxllnc Zaken** tops every area a Dutch buyer asks about first: documents 18 of 23, parties 11 of 13, search 10 of 12, intake 9 of 13.
- **GZAC** wins configuration, 18 of 24, and tasks, 14 of 19, on its BPMN engine.
- **OTOBO and Znuny** ship a process engine, an ACL engine, custom fields, nine working calendars, a generic interface and a customer portal, and score 8 of 12 on search and 7 of 10 on reporting. None of their vendor packages is priced.
- **Odoo** reaches 78 without a case number, a term engine or a document model, from the platform under its project module: one chatter, one automation engine, one record-rule layer, one form builder and typed properties per project.
- **OpenProject** has the best deadline engine in the corpus: a working-day calculator that returned a non-working day 0 times in 1,675 computations, and the only recompute of existing deadlines when the calendar changes, with the reason written to the history.
- **Redmine** still wins field permissions by role and case state outright, and recomputes a dependent's dates when a predecessor slips.
- **Deck** passes seven document rows because its attachment is a file in Files.

### What they lack, measured

| gap | evidence |
|---|---|
| Decisions | 0 of 7 in every non-Dutch column, twenty driven and four documented. The nearest object is OpenProject's `MeetingOutcome` with a `decision` kind and no approver, quorum, number, effective date or publication. |
| Dutch statutory rows | 26 rows, twenty-four non-Dutch systems, 624 cells, zero `yes`. Every catalogue searched by name: 0 apps for ZGW, and none of the seven names on Easy8's 1,434 pages. |
| Documents | The weakest area of the whole non-Dutch corpus. Znuny and GitLab share two `yes` answers over 46 document rows; Kanboard and Vikunja the same; Frappe Helpdesk, Request Tracker, Gitea and Taiga score one each of 23. |
| Easter | Nothing in twenty-four non-Dutch columns computes it. Frappe Helpdesk and Request Tracker agreed to the minute on a Dutch year and both landed a 2027 term on Tweede Paasdag. dossiq computes it, in `lib/Service/WorkingDayCalculator.php`. |
| The edition you can deploy | Pending row 11.27. Twelve open-core shapes across the corpus; dossiq passes because there is no paid half. |
| Overdue lists | OpenCase and GZAC cannot list overdue cases at all. |

### Where dossiq is behind, measured

| row | what | who passes |
|---|---|---|
| 13.18 | Watchers on a case, distinct from the assignee | Eight of eight non-Dutch systems in the register's reading; dossiq the only `no` in the ledger. |
| 6.15 | Internal versus public per timeline entry | Seven of eight; the citizen portal cannot show a timeline until every entry says which side of the counter it is on. |
| 8.11, 8.12, 8.17 | An administered working calendar, the Atw roll, recompute on change | 30.4% of stored terms land on a non-working day. |
| 2.4 | One-click claim on a case | All three Dutch rivals. |
| Q10.14 | A refused write answers with a status | 47 `catch (\Throwable)` sites in 37 files under `lib/Service/` return `null` or `[]`. |
| 13.17 | Substituted work reaches My work | `fetchSubstitutedWork()` has no call site. |
| 13.23 | A right on a parent case applies to its sub-cases | Vikunja, measured three levels down; `CaseAccessGuard.php` never reads `parentCase`. |
| 6.20 | Outbound webhooks are signed | Vikunja; dossiq verifies inbound signatures and signs nothing outbound. |
| 3.22 | A stage holds a capacity and refuses work past it | Vikunja with a 412; no Dutch system in the corpus. Taiga stores a WIP limit and enforces it only in a CSS class. |
| 8.22 | Every write path to a date field agrees, and one date is shown | Taiga, whose `due_date` is a date and not a timestamp. Nine dossiq controllers can set a case date and the only normaliser is private to one caller. |
| sections 4, 9, 3, 11 | Documents 7 of 23, search 4 of 12, tasks 6 of 19, configuration 9 of 24 | xxllnc, GZAC, OTOBO, Odoo. |

The gap register puts these and the rest into 158 gaps with one owner each, 32 on dossiq and 68 on openregister; the [result page](../research/competitor-parity-2026-09.md#the-gap-register-counted-by-owner) has the counts by owner, and the umbrella the dossiq changes, [33 at first](https://github.com/ConductionNL/dossiq/pull/2643) and [five more](https://github.com/ConductionNL/dossiq/pull/2684) for the gaps the rebuilt register still listed.

### Nextcloud-native advantages, measured and unmeasured

| capability | standing |
|---|---|
| Leaf tabs from other apps inside the case page (mail, appointments, decisions, hours) | Measured in round 2: no competitor composes another product into the case page. |
| Document capabilities through Files (versions, expiring shares, lock, trash, preview, file manager) | Measured through Deck: six rows dossiq gets on the same host; row 4.23 says which already follow. |
| Case creation in four clicks | Measured: fifteen in xxllnc Zaken, twelve in GZAC. |
| The calendar as an OpenRegister schema, with Easter computed | Measured against twenty-four non-Dutch columns. |
| Federated cross-organisation cases | Design claim, not a matrix row. Unmeasured. |
| Talk room per case, air-gapped deployment, data reuse across apps on OpenRegister | Design claims. Unmeasured. |

## 4. Strategic positioning

### Positioning statement

**dossiq is case management that lives where your team already works.** Built into Nextcloud, it turns the workspace you have into a case system, with files, calendar, chat and activity already connected.

### Differentiation

1. **The law, measured.** 13 statutory `yes` answers, 6 of 7 decision rows and 8 of 10 deadline rows that no help desk, board or forge scores. That is the half of the matrix a Dutch buyer cannot do without, and the half the comparable score leaves out on purpose.
2. **Platform leverage.** Documents, tabs from other apps, and the file manager come from the host; Deck proved the document rows do, and dossiq gets them for the same price.
3. **Nothing withheld.** No paid edition, no key, no gate: pending row 11.27 passes on a grep with zero hits, which two of the three systems above dossiq outside the Dutch family cannot say without a footnote.

### Target segments

| segment | why dossiq | what they would otherwise use, measured where it was |
|---|---|---|
| Small municipalities | Simple, affordable, NL-compliant | Spreadsheets and shared drives, or xxllnc Zaken at 125 of 180 |
| Government teams | ZGW-ready, sovereign, NL Design | Open Zaak with ZAC, which owns no data of its own |
| SMB operations | Lightweight, on the workspace they have | Odoo at 78, OTOBO or Znuny at 78 and 73, Frappe Helpdesk at 72 |
| NGOs and nonprofits | Free, self-hosted, collaboration first | Kanboard or Vikunja at 37 and 35 |

### Risks, measured

| risk | evidence | mitigation |
|---|---|---|
| Statutory terms land on non-working days | 30.4% of stored terms, `_round4/compare/deadline-engines.md`; the Atw roll is absent | First of the five to build: the administered working calendar with the roll and recompute, `terms-on-the-engine-calendar` and openregister `working-calendar-admin` |
| Non-Dutch products outscore dossiq on the comparable half | OTOBO 78, Odoo 78, Znuny 73 and Frappe Helpdesk 72 against 71; Easy Redmine 86 and JSM 83 as documented ceilings | The gap register: 158 gaps, 103 of them size S, 141 carried by a spec or an open change |
| Rows dossiq alone fails | 13.18 watchers, 6.15 internal versus public | Second and third of the five to build |
| Refusals that look like absence | 47 catch-and-return-null sites | `refusals-carry-a-status`, fourth of the five |
| Schemas registered ahead of the feature | 27 schemas with no surface anywhere on the tree | `no-schema-without-a-surface`, a structural test |
| OpenRegister dependency | 68 of 158 gaps are owned by openregister | The [openregister umbrella](https://github.com/ConductionNL/openregister/pull/3688) carries them; dossiq keeps a half on each |
| Documents are the largest area gap against the Dutch rivals | 7 of 23 against xxllnc's 18 and OpenCase's 13 | `documents-on-the-case` and the Files rows Deck already passes on the same host |

## 5. Recommended feature set

The MVP, V1 and Enterprise lists below are the product's own scope map, written before the driving and kept for continuity. For what to build next, read the [five to build first](../research/competitor-parity-2026-09.md#the-five-to-build-first).

### MVP (27 features)

Replace spreadsheets and informal case tracking for small teams. Case types control behavior from day one.

**Case management**
1. Case CRUD with lifecycle
2. Case list with search, sort, filters
3. Case detail view with timeline
4. Status timeline visualization on case detail
5. Case deadline countdown (days remaining / overdue)
6. Quick status change from case list view
7. Case result recording

**Case type system**
8. Case type CRUD (admin)
9. Case type controls allowed statuses (ordered)
10. Case type controls processing deadline (auto-calculated)
11. Case type draft/published lifecycle
12. Case type validity periods

**Task management**
13. Task CRUD linked to cases
14. Task list with status filters
15. Task assignment to users
16. Task due dates and priorities

**Roles and status**
17. Case handler assignment (initiator, handler roles)
18. Status tracking with history

**My work and dashboard**
19. My Work view (personal workload: my cases, my tasks)
20. Overdue item highlighting
21. Dashboard with counts and status distribution

**Admin settings**
22. Nextcloud admin settings page
23. Case type management UI
24. Status type management per case type
25. Default case type selection

**Platform**
26. RBAC via OpenRegister
27. Full audit trail, WCAG AA, English/Dutch localization

### V1 (34 additional features)

Compete with Open Zaak plus a frontend for government teams.

**Case type extensions**
28. Case type controls allowed roles
29. Case type controls result types (with archival rules)
30. Case type custom property definitions
31. Case type required documents per status
32. Case type decision type definitions
33. Case type confidentiality defaults
34. Case type suspension/extension rules

**Case management**
35. Sub-cases (parent/child)
36. Document completion checklist (required vs present)
37. Property completion indicator (% required fields filled)
38. Days in current status indicator
39. Case templates
40. Confidentiality levels on cases

**Task management**
41. Task checklist and dependencies
42. Kanban board for tasks
43. Task templates per case type

**Decisions**
44. Decision CRUD with effective/expiry dates

**Admin settings**
45. Result type management per case type
46. Role type management per case type
47. Property/document/decision type management

**Reporting**
48. Case type breakdown chart (dashboard)
49. Average processing time per case type

**Collaboration**
50. Talk integration (per-case chat)
51. Calendar integration (deadlines in calendar)
52. Activity stream publishing
53. Status change notifications
54. File attachments and shared folders

**Integration**
55. Pipelinq bridge (request-to-case)
56. ZGW API mapping (Zaken, Besluiten, Catalogi)
57. Cross-app My Work (include Pipelinq leads/requests)

**Compliance and UX**
58. GDPR export + deletion
59. NL Design System theming
60. Saved views/filters
61. Configurable list columns

### Enterprise (21 additional features)

Large municipalities, multi-organization, and compliance-heavy deployments.

62. Federated case sharing
63. Case type sub-case type restrictions
64. Case type versioning chains
65. Case type import/export
66. CMMN runtime (sentries, criteria)
67. Nextcloud Flows automation
68. Automated task creation on status change
69. SLA compliance meter (% cases meeting deadline)
70. Case type performance comparison
71. Handler workload heatmap
72. Workload analytics (items per user)
73. DMN decision tables
74. Archival management (archiefwet)
75. Data retention policies
76. Field-level access control
77. Webhook support
78. Public intake form
79. Document templates
80. Bulk case operations
81. Workflow designer (visual)
82. Custom report builder

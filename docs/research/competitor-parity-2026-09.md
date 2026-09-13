# Competitor parity, September 2026

This page is the result of the competitor parity programme, as of ledger v7 plus batch 11 (2026-09-13). Twenty-eight columns stand in the matrix: dossiq, three other Dutch case systems, twenty non-Dutch systems that were installed and driven, and four products rated from their documentation. Every number below comes from a corpus file, named by path. The record is `procest/_ledger/parity-ledger.html` on `development` in `ConductionNL/market-intelligence`; the corpus paths on this page are relative to that repository. The sources per system, with the links a reader needs to check a cell, are on [competitor sources](competitor-sources.md).

Batches 9 and 10 are folded into ledger v7: Request Tracker 5.0.10 and Frappe Helpdesk 1.30.1 driven, Jira Software Data Center 11.3 and Easy Redmine 16.0 rated from documents. Batch 11 is merged in the corpus and not in the ledger yet: Gitea 1.27.3 and Taiga 6.10.2, both driven, counted in `procest/_round4/compare/twenty-four-system-tally.md`. Batch 12 is running, Huly and Tuleap, and adds two rows to the driven table below. Where a count depends on the batch set, the text says which set it means and names the count.

What followed from the results is written elsewhere and cited here:

- the re-read of the stale rows, [competitor gap re-read 2026-09-13](competitor-gap-re-read-2026-09-13.md), which closed 12 of the 36 rows the register marked stale
- dossiq's OpenSpec umbrella, `openspec/changes/competitor-parity-2026-09/proposal.md`, merged as [dossiq#2643](https://github.com/ConductionNL/dossiq/pull/2643), 33 changes
- openregister's umbrella, merged as [openregister#3688](https://github.com/ConductionNL/openregister/pull/3688), 26 changes

## Method

### Installed and driven, or rated from documents

Every system carries one of four evidence classes (`data.family.classes` in `procest/_ledger/parity-ledger.html`).

| class | what it means |
|---|---|
| driven | Installed, seeded with Dutch municipal cases, and driven in a browser. Every rating cites a file path with a line number, a screen, or a computation the live engine returned. The only class that may produce a new matrix row. |
| documented | Closed source and not driven. Rated from the API reference, the documentation, the public tracker and the price pages, every cell quoting the page it rests on. Printed under the label `documented, not driven` wherever its number appears, never in a driven count or a driven ranking. An upper bound: every driven batch found something the vendor's page did not say. |
| trial | Self-hostable only under a paid or time-limited licence. Can reach the driven standard at a cost. |
| docs only | Cloud only. Included for landscape, never as evidence. |

Twenty non-Dutch systems are driven and four are documented: JSM Cloud, YouTrack 2026.2, Jira Software Data Center 11.3 and Easy Redmine 16.0. Batch 12 adds two more driven columns when it lands.

### The evidence convention

The method paragraph of the ledger's handover (`data.handover.method`) is the rule set every batch worked under. The parts that decide a cell:

- Never rate from a feature page. A rating cites a path with a line, a screen, or a number an engine returned.
- Grep for the route, not the model. A model in the source is not a capability; Plane's epics answer 404 while `is_epic` sits in the tree.
- Propose a row only if at least one corpus system scores `yes` on it. A row nobody passes measures the standard rather than the products.
- Recount every tally by script. Batch 1 mistyped six of thirteen totals and batch 2 seven of twenty-six, so `procest/_round4/tools/corpus-tally.py` counts and ranks every column, and `count-column.py` fails when its own tally lines disagree.
- Check a comparative claim (first, only, best) against the published files before writing it. Three such claims failed in batch 3, two more in batch 7.
- A product you configured is doing what you told it. Batch 7's own automatic action fired on the task a recurrence created and corrupted a measurement until it was removed.

### The counting rule: 225 = 206 + 19

The ledger holds **225 rows**: the original 206-row matrix from round 2 (`procest/_round2/compare/M1-functionality.md`), plus the **19** of round 3's 21 proposals that were promoted after two were dropped as restatements (`procest/_round4/compare/promoted-rows-batch3.md`). A proposal is not a row until it is promoted, so the rows batches 1 to 11 proposed, 11 + 5 + 3 + 3 + 3 + 3 + 5 + 5 + 3 + 2 + 2, are **45 pending**. The ledger counts 43 through batch 10 (`data.family.counting`); batch 11 adds 8.22 and 13.24 in `procest/_round4/compare/proposed-rows-batch11.md`. Adding 206 + 21 + 11 double counts the promoted nineteen. Say which artefact you mean: the corpus M1 file is 206, the ledger is 225.

Proposals are kept out of the matrix for a reason the round 2 file states: a proposed row has a verdict for the system that produced it and nothing for the systems nobody re-read, and filling those blanks would be a fabricated reading. Pending proposals carry a `Q` prefix in the gap register because their ids collide with promoted ids (Q2.27 is identifier uniqueness, 2.27 is the edit lock).

### The statutory split

Round 3 estimated that roughly forty rows named Dutch statutory concepts. Round 4 replaced the estimate with a test and published the list: **26 rows** (`procest/_round4/compare/statutory-rows.md`). A row is statutory when it names a Dutch statutory instrument, a national register, a government authentication scheme, a government interoperability standard, or a Dutch government software vendor. Everything else is domain neutral, even when Dutch municipalities are the reason it matters: retention, e-signing, payments, SSO and citizen portals are ordinary product capabilities a Dutch buyer happens to need.

The test cuts both ways. Classifying a row as statutory removes it from the denominator, which raises a competitor's headline rather than lowering it. The headline is therefore the **domain-neutral 180**, and the 26 are reported separately.

## The ranking on the domain-neutral 180

Counted and ranked by `procest/_round4/tools/corpus-tally.py`, printed in `procest/_round4/compare/twenty-four-system-tally.md`, which supersedes `eighteen-system-tally.md` and `twenty-two-system-tally.md`. The script sorts on `yes` only and keeps file order on a tie, so OTOBO prints above Odoo and nothing in the method ranks on `partial`. It prints two rankings, so a documented cell is never read as a driven one.

### Driven columns, twenty-four as of ledger v7 plus batch 11

| rank | system | yes | partial | family |
|---|---|---|---|---|
| 1 | xxllnc Zaken | 125 | 31 | Dutch case systems |
| 2 | GZAC/Valtimo | 82 | 46 | Dutch case systems |
| 3 | OTOBO 11.0 | 78 | 45 | help desks and ITSM |
| 4 | Odoo 19.0 | 78 | 51 | project tools and boards |
| 5 | Znuny 7.3 | 73 | 45 | help desks and ITSM |
| 6 | Frappe Helpdesk 1.30 | 72 | 58 | help desks and ITSM |
| 7 | **dossiq** | **71** | **77** | Dutch case systems |
| 8 | Request Tracker 5.0 | 65 | 54 | help desks and ITSM |
| 9 | iTop 3.2 | 58 | 49 | help desks and ITSM |
| 10 | OpenCase | 58 | 38 | Dutch case systems (Danish, on Nextcloud) |
| 11 | GLPI 11 | 56 | 66 | help desks and ITSM |
| 12 | osTicket 1.18 | 55 | 43 | help desks and ITSM |
| 13 | Zammad 7 | 53 | 57 | help desks and ITSM |
| 14 | OpenProject 16 | 46 | 64 | project tools and boards |
| 15 | Redmine 7 | 43 | 51 | forges |
| 16 | Deck 1.18 | 37 | 37 | project tools and boards |
| 17 | Kanboard 1.2 | 37 | 39 | project tools and boards |
| 18 | GitLab CE 19.3 | 36 | 56 | forges |
| 19 | Vikunja 2.6 | 35 | 26 | project tools and boards |
| 20 | Taiga 6.10.2 | 32 | 52 | project tools and boards |
| 21 | Forgejo 16 | 23 | 47 | forges |
| 22 | Gitea 1.27.3 | 23 | 47 | forges |
| 23 | Plane 1.4 | 22 | 49 | project tools and boards |
| 24 | FreeScout 1.8 | 22 | 31 | help desks and ITSM |

Two rows of this table are one product. Gitea and Forgejo answer all 206 matrix rows identically, 23 yes and 48 partial each, and a script comparing the two published columns reports 0 rows differing (`procest/gitea/round4/gitea-vs-forgejo.md`). Where the fork separated is six subsystems no matrix row reaches.

Batch 12 adds Huly and Tuleap to this table when it lands.

### Documented columns, four as of ledger v7, graded `documented, not driven`

| system | yes | partial | where it would sit among all columns |
|---|---|---|---|
| Easy Redmine 16.0 | 86 | 62 | second of twenty-eight, above GZAC |
| JSM Cloud | 83 | 53 | third of twenty-eight, above GZAC |
| YouTrack 2026.2 | 78 | 44 | seventh of twenty-eight, level with OTOBO and Odoo |
| Jira Software DC 11.3 | 64 | 55 | twelfth of twenty-eight, one below Request Tracker |

All four are upper bounds. OTOBO's `Ticket::Service` shipped switched off and ignored a configured SLA; a documentation page would not have said so (`procest/_round4/compare/open-core-batch8.md`). Easy Redmine's 86 is the highest documented number in the corpus and the least verifiable, for a second reason: the paid half has no public tree and there is no public tracker to check a `no` against (`procest/_round4/compare/findings-batch10.md`). Jira Software Data Center carries a third caveat that is not about evidence at all. Sales to new customers ended on 2026-03-30 and the products reach end of life on 2029-03-28, so a municipality reading that column cannot buy what it describes.

### The full ranking, twenty-eight columns

With the documented columns admitted as upper bounds, the same script prints: xxllnc Zaken 125, Easy Redmine 86 (documented), JSM Cloud 83 (documented), GZAC 82, OTOBO 78, Odoo 78, YouTrack 78 (documented), Znuny 73, Frappe Helpdesk 72, **dossiq 71**, Request Tracker 65, Jira Software DC 64 (documented), iTop 58, OpenCase 58, GLPI 56, osTicket 55, Zammad 53, OpenProject 46, Redmine 43, Deck 37, Kanboard 37, GitLab CE 36, Vikunja 35, Taiga 32, Forgejo 23, Gitea 23, Plane 22, FreeScout 22. dossiq is seventh of twenty-four driven columns and tenth of twenty-eight.

### The families

Driven columns only, from `procest/_round4/compare/twenty-four-system-tally.md` and the family table in `twenty-two-system-tally.md`:

| family | driven systems | best domain-neutral `yes` |
|---|---|---|
| Dutch case systems | xxllnc Zaken, GZAC, dossiq, OpenCase | 125 |
| help desks and ITSM | OTOBO 78, Znuny 73, Frappe Helpdesk 72, Request Tracker 65, iTop 58, GLPI 56, osTicket 55, Zammad 53, FreeScout 22 | 78 |
| project tools and boards | Odoo 78, OpenProject 46, Deck 37, Kanboard 37, Vikunja 35, Taiga 32, Plane 22 | 78 |
| forges | Redmine 43, GitLab CE 36, Forgejo 23, Gitea 23 | 43 |

## Where dossiq stands, and what moved it

**dossiq scores 71 of 180.** The combined 84 of 206 that earlier pages quoted includes thirteen `yes` answers on statutory rows no non-Dutch system can score, so it measures the jurisdiction as much as the product. On the move to 180 every competitor loses nothing, because none of them scored those rows anyway. The gap to GLPI narrows from 28 rows to 15 (`data.family.comparable`).

What moved the position, batch by batch (`data.family.leadLost`):

- **Batch 4, Znuny 7.3.6 at 73.** The first time in four rounds that a non-Dutch product beat dossiq on the comparable half. Not a help desk that grew: a process engine, an ACL engine, custom fields, nine working calendars, a generic interface and a customer portal, with none of its 37 vendor packages priced.
- **Batch 5, OTOBO 11.0.17 at 78.** The other OTRS fork. The same product as Znuny in 881 of its 1,132 modules, identical on 231 of 247 rows; the 16 rows that differ are what each vendor built after the 2019 split, and 13 went OTOBO's way (`procest/_round4/compare/otobo-vs-znuny.md`).
- **Batch 6, Odoo 19.0 Community at 78.** Ties OTOBO from the platform under its project module: one chatter, one automation engine, one record-rule layer, one form builder and typed properties, with no case number, no term engine and no document model.
- **Batch 7 changed nothing above dossiq.** Kanboard 37 and Vikunja 35 landed fifteenth and seventeenth.
- **Batch 8 put two documented ceilings beside the driven top.** JSM Cloud 83 and YouTrack 78, never driven.
- **Batch 9, Frappe Helpdesk 1.30.1 at 72.** One row above dossiq, the fourth driven system to pass us and the first on the profile closest to our own: a term engine, mail intake, a customer identity, a status vocabulary with a customer label and refusals on configuration in use, all from one tree. A third of its column is the Frappe framework's desk at `/app`, which its own UI does not draw. Request Tracker 5.0.10 reads 65, eighth.
- **Batch 10 raised the documented ceiling.** Easy Redmine 16.0 reads 86 and Jira Software Data Center 11.3 reads 64, neither driven. A buyer comparing dossiq to "Redmine" is quite likely being shown Easy Redmine.
- **Batch 11 changed nothing above dossiq.** Taiga 6.10.2 landed twentieth at 32 and Gitea 1.27.3 twenty-second at 23, row for row with Forgejo, which forked from it.

Two of the six driven columns above dossiq are Dutch case systems, two are forks of one German service desk, one is a Belgian ERP's project module and one is a help desk on an Indian low-code framework. The only two systems above GZAC on the comparable half are xxllnc Zaken and products nobody in this corpus has run.

### The shape per area

`yes` answers per area, dossiq beside the strongest columns the tallies print per area. dossiq's column is the 206-row reading of 2026-09-07 with the 2026-09-08 corrections (`procest/_round2/compare/M1-functionality.md`, tally per area); the re-read of 2026-09-13 closed twelve more rows after this count and is cited on the [re-read page](competitor-gap-re-read-2026-09-13.md). Non-Dutch columns are from `twelve-system-tally.md`, `fourteen-system-tally.md`, `eighteen-system-tally.md` and `twenty-two-system-tally.md` in `procest/_round4/compare/`.

| area | rows | dossiq | xxllnc | GZAC | OpenCase | OTOBO | Odoo | Znuny | Helpdesk | Deck |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 Intake | 13 | 4 | 9 | 3 | 7 | 7 | 7 | 7 | 6 | 2 |
| 2 Case core | 22 | 9 | 16 | 13 | 7 | 10 | 11 | 10 | 11 | 7 |
| 3 Tasks and phases | 19 | 6 | 11 | 14 | 2 | 10 | 8 | 9 | 6 | 3 |
| 4 Documents | 23 | 7 | 18 | 5 | 13 | 2 | 3 | 1 | 1 | 7 |
| 5 Parties and contacts | 13 | 5 | 11 | 1 | 7 | 4 | 3 | 3 | 4 | 0 |
| 6 Communication | 14 | 4 | 10 | 2 | 3 | 6 | 7 | 7 | 7 | 2 |
| 7 Decisions | 7 | **6** | 3 | 1 | 1 | 0 | 0 | 0 | 0 | 0 |
| 8 Deadlines | 10 | **8** | 7 | 4 | 2 | 5 | 5 | 5 | 4 | 2 |
| 9 Search | 12 | 4 | 10 | 5 | 5 | 8 | 8 | 7 | 7 | 3 |
| 10 Reporting | 10 | 5 | 7 | 5 | 1 | 7 | 6 | 7 | 6 | 1 |
| 11 Configuration | 24 | 9 | 13 | 18 | 3 | 10 | 11 | 11 | 10 | 4 |
| 12 Integrations | 23 | **11** | 10 | 9 | 4 | 4 | 3 | 2 | 3 | 2 |
| 13 Access and privacy | 16 | 6 | 11 | 6 | 7 | 5 | 6 | 4 | **7** | 4 |

Frappe Helpdesk's 7 in section 13 is the highest of the twenty driven non-Dutch columns, on customer identity, per-customer visibility, two-factor with a login log, and API tokens per user. Request Tracker's 8 in section 9 is TicketSQL, a query language with saved searches, charts and dashboards, level with OTOBO and Odoo (`procest/_round4/compare/twenty-two-system-tally.md`).

Three sentences the tallies repeat:

- **Decisions are where every non-Dutch system collapses.** Twenty driven non-Dutch columns score 0 of 7, and so do the four documented ones. The nearest object in the whole family is OpenProject's `MeetingOutcome`, whose `kind` enum has a `decision` value and no approver, quorum, number, effective date or publication (`procest/_round4/compare/findings.md`).
- **Documents are where the whole non-Dutch corpus is weakest.** Znuny and GitLab have 46 document rows between them and two `yes` answers; Kanboard and Vikunja the same; Frappe Helpdesk, Request Tracker, Gitea and Taiga score one each of 23. The one non-Dutch column that does well there, Deck at 7 of 23, does so through its host: an attachment is a file in Files, so versions, a share with an expiry, a lock, a trash, a preview and a file manager come free (`procest/_round4/compare/eighteen-system-tally.md`).
- **Section 12 flatters dossiq.** Ten of its 23 rows are statutory (12.1 to 12.6, 12.8, 12.9, 12.11 and 12.16 in the list below), so dossiq's lead there is partly the jurisdiction.

## The 26 statutory rows and the zero-yes proof

The list, from `procest/_round4/compare/statutory-rows.md`:

| row | capability | why statutory |
|---|---|---|
| 4.20 | Archive export of closed cases (XML, SIP, TMLO/MDTO) | TMLO and MDTO are Dutch archival metadata standards |
| 5.3 | National registry lookup (BRP, KvK) | names BRP and KvK |
| 5.5 | Address protection or secrecy indication | geheimhouding is a BRP concept |
| 5.8 | Authorised representative (gemachtigde) on a case | Awb 2:1 |
| 5.10 | Property or address object as a party (BAG) | names BAG |
| 5.11 | Contact import and change subscriptions from registries | the registries meant are BRP and KvK |
| 5.13 | External register views embedded in the app | the registers meant are the national ones |
| 6.6 | Digital post to citizens (Berichtenbox) | names Berichtenbox |
| 6.14 | Notes synced to an external case register | the register meant is a zaaksysteem |
| 7.3 | Formal decision record (besluit) | besluit is an Awb concept with legal consequences |
| 7.4 | Decision letter generated as a workflow step | bekendmaking, Awb 3:41 and 3:45 |
| 7.5 | Woo request with per-document assessment and redaction | names the Wet open overheid (Woo) |
| 7.6 | Objection handling surface | bezwaar, Awb 6 and 7 |
| 7.7 | Result drives archival nomination and retention | Archiefwet selectielijst |
| 11.22 | AVG register fields per case type | verwerkingsregister, AVG article 30 |
| 12.1 | ZGW APIs (Zaken, Documenten, Catalogi, Besluiten) | names ZGW |
| 12.2 | Notificaties API | a ZGW component |
| 12.3 | Objecten and Objecttypen API | a ZGW component |
| 12.4 | StUF (BG, ZKN, DCR) | names StUF |
| 12.5 | DSO or Omgevingswet | names the Omgevingswet |
| 12.6 | National person and company registries | BRP and Handelsregister |
| 12.8 | Citizen authentication (DigiD, eHerkenning, eIDAS) | names DigiD and eHerkenning |
| 12.9 | Digital post or berichtenbox | names berichtenbox |
| 12.11 | External document generation service (SmartDocuments, Xential) | both are Dutch government software vendors |
| 12.16 | Map and geo services (PDOK, BAG, ArcGIS) | names PDOK and BAG |
| 13.11 | AVG register per case type | as 11.22 |

One row sits on the line. 5.11 is `partial` for several systems because LDAP synchronisation and SCIM provisioning do import people and keep them in step with a directory, which is a registry subscription of staff rather than citizens. Moving it changes no headline, and the file flags it rather than picking the flattering side.

**The proof.** Twenty-four non-Dutch systems, twenty driven and four documented, read against all 26 rows: **624 cells, zero `yes`** (520 driven, 104 documented). The two halves are stated apart because they are different kinds of evidence. Every `partial` in the statutory column is the generic half of a Dutch question, most of them 5.11, a directory that creates users; the count per system is in the tally's statutory column. Every catalogue was searched by name before a statutory `no` was written, and the substring hits are the finding. The Atlassian Marketplace filtered to Data Center returns 9 apps for DigiD, all calendars and planning, 3 for eHerkenning, all Gherkin editors, and 0 for eIDAS, ZGW, zaakgericht, StUF and SmartDocuments, where the unfiltered Cloud search of batch 8 returned 76, 7 and 0. The JetBrains Marketplace returns 0, 0 and 0 and corrects DigiD to "digit". None of the seven names occurs anywhere in Easy8's 1,434 pages (`data.family.statutoryProof`, measured in `procest/_round4/compare/twenty-two-system-tally.md` and `twenty-four-system-tally.md`).

That is why the 180 is the headline. Counting the 26 in a product comparison measures the jurisdiction, and the 26 still matter enormously to the buyer.

## Twelve open-core shapes

The matrix has 206 rows and none of them asked whether the capability just scored is in the build you can install. Round 4 checked every system against its source tree rather than its licence page, and found twelve shapes a missing capability takes (`data.handover.openCore` holds eleven, with the per-batch reading in `procest/_round4/compare/open-core-batch*.md`; the twelfth is batch 11's and not in the ledger yet). Each shape is named after the system that defined it.

| # | shape | defined by | what was read |
|---|---|---|---|
| 1 | Gates nothing | GLPI, Zammad, Redmine, Forgejo, osTicket, Znuny, OTOBO, Deck, Kanboard, Request Tracker, Frappe Helpdesk | zero entitlement hits over the tree; Znuny's 37 and OTOBO's 45 vendor packages none priced; Kanboard's 163-plugin directory has no price field; Request Tracker's ten hits are the GPL header, a dashboard subscription and the vendor's address, and Frappe Helpdesk returns zero over both repositories |
| 2 | Gates at runtime, some features degrading silently | OpenProject | 32 features behind `EnterpriseToken.allows_to?`, the GPL source present and refusing to run; four degrade silently, including internal comments becoming invisible to everyone |
| 3 | Omits the paid half entirely | Plane | no entitlement machinery; the paid features are absent and only the seams remain, such as `useBulkOperationStatus = () => false` |
| 4 | Prices modules with the paywall through the free half | FreeScout | 72 of 75 modules priced, 539.73 dollars for the set, 33 matrix rows gated |
| 5 | Removes the paid code at build time | GitLab CE | 1,616 no-op seams in 1,599 files; 22 rows settled by a 404 or 400 from the running instance |
| 6 | Sells a separate non-public edition beside an AGPL core | OpenCase | the enterprise edition (AI, digital post, CPR and CVR) gated on `enterprise_version` |
| 7 | Sells a catalogue of closed extensions beside a complete core | iTop | 103 extensions on the store, 70 AGPL, 33 under the Combodo Software License, none in the tree and none gated by it |
| 8 | Keeps the paid half in a private repository and labels part of it inside the free product | Odoo | 21 `to_buy` records under OEEL-1 and 45 upgrade badges; the edition boundary last moved in 2019 by the git history |
| 9 | Draws the paid half as a column in a plan table | JSM Cloud | no code to read; 16 cells Premium, 8 Enterprise, 5 Atlassian Guard, 2 Data Center; incident, change and problem moved to Premium on 2024-10-16 |
| 10 | Keeps the paid code in the tree and answers 404 for it by design | Vikunja | three features behind a key checked daily against the vendor; `RequireFeature` serves 404 "so gated routes are indistinguishable from unregistered ones"; with a key the check reports user counts to the vendor |
| 11 | Sells a source-available proprietary layer on a GPL core, as open source | Easy Redmine 16.0 (documented) | a GPL-2.0 Redmine core with everything Easy wrote beside it under the Easy8 Commercial License: the on-premises buyer may download that tree, the cloud buyer "has no right to obtain the source code to the Elements, nor to view it", neither may pass it on, and a change carries a notification duty to the vendor. Inside the tree a plan table decides what runs, 22 matrix cells naming Platform and 9 an add-on, under a product page reading "100% open source" |
| 12 | The steward of the open core sells a closed product built on it | Gitea 1.27.3 | MIT on 3,010 of 3,013 Go files and every open-core grep at zero, while Gitea Ltd sells Gitea Enterprise and Gitea Cloud. No gate, no seam, no plan table, no store, no 404: the tree holds no evidence that the closed product exists, and it is not lying, because every capability in it works |

Three systems are not shapes. Deck withholds nothing for money, and its score is set by which host surfaces it plugs into. Taiga is the same verdict reached the same way: MPL-2.0 across 931 Python files, zero hits to every open-core grep, and a vendor selling hosting of that code. YouTrack is shape one and closed: nothing withheld above ten users and three agents, and what a buyer does not get is the source, which the shapes do not measure.

What the twelfth shape adds is a limit on the method itself. For every system in eleven batches the tree told you where the boundary was, through a gate, a seam, an empty module, a 404 or a label. Gitea's boundary is in a roadmap, which is not a file, so a buyer cannot check it now and cannot check it next year either. What is withheld there is not a feature but the option to withhold one. Forgejo is the control that makes it legible: the same code, the same empty greps, and a licence under which a closed derivative is unavailable to anyone, its own vendor included.

What the corpus says about the question: copyleft proves nothing, since most of these trees are GPL, LGPL or AGPL and among them sits every shape a grep has found; a grep finds a gate and never a catalogue, a roadmap or a private repository, so read the store, the Apps page and the git history as well; and a documented column cannot find a silent switch, which is why a closed product's number is an upper bound. The open-core question and the phone-home question have also come apart. Gitea and Forgejo read a version file weekly and disclose nothing; Taiga sends its instance's own URL, a persistent identifier and 46 measured properties to the vendor every night, with telemetry on by default. Nothing is gated in any of the three. Pending row Q12.26 asks the question and no row in the 225 does. dossiq passes pending row 11.27, the capability is in the edition you can deploy, because there is no paid half: `grep -rniE "licen[cs]e_key|entitlement|enterprise_feature|allows_to" lib/ src/` returns 0 hits (`data.family.proposals`).

## The working-day findings

Round 4 asked the same question of every system: when a product says a term ends on a date, what counted? The files are `procest/_round4/compare/deadline-engines.md` and `deadline-engines-batch2.md` to `deadline-engines-batch11.md`.

**dossiq's own engine, and its five copies.** Round 3 found dossiq counting working days in five private code copies that disagreed with each other, three of the five computing the moving feasts (`deadline-engines.md`, the comparison table; `data.dossiqLessons`). The ledger records that the five were consolidated since; the re-read confirms one `lib/Service/WorkingDayCalculator.php` on the current tree, with Easter hand-rolled rather than taken from `easter_date()`, and no time zone statement in `TermijnService.php` (`competitor-gap-re-read-2026-09-13.md`, rows Q12.25 and Q8.19).

**Three in ten stored terms land on a day the Algemene termijnenwet (Atw) moves.** dossiq counts Awb terms in calendar days, which is correct: the terms it tracks are expressed in weeks or days, and 8 weeks is stored as 56 days. What dossiq does not do is roll the end date to the next working day, which the Atw requires. OpenProject's calendar-day calculator is arithmetically the same thing dossiq does, and it was run against a real Dutch holiday table: across every start date from 1 January to 31 October 2026 at the five term lengths dossiq stores (14, 28, 42, 56 and 84 days), the engine returned a Saturday, Sunday or Dutch holiday in **462 of 1,520 computations, 30.4%**. Its working-day calculator returned one 0 times in 1,675 (`deadline-engines.md`; batch 2 confirmed the figure independently in `deadline-engines-batch2.md`). That number is not about OpenProject. It is the size of a defect in dossiq, obtained by running someone else's engine in dossiq's mode.

**The lesson is a combination nobody ships.** The counting mode and the end-date rule are two separate decisions. OpenProject has a per-item choice of counting mode and applies the roll only in the mode that never needs it. dossiq needs to count in calendar days and then roll, which needs the one administered holiday calendar that promoted row 8.12 asks for.

**The engines, side by side.** From the batch files, the systems that have a calendar at all:

| | working calendar | holidays are data | recomputes when the calendar changes | pause credited back | Easter |
|---|---|---|---|---|---|
| GLPI 11 | many, `Calendar` + `Calendar_Holiday` | yes | no | not measured | no |
| Zammad 7 | one per SLA | yes, with iCal feed | no | not measured | no |
| OpenProject 16 | one, instance wide | yes, `non_working_days` | **yes**, with the reason written to the history | no | no |
| Redmine 7 | the work week only | no, and no place to put one | no, measured; a dependent's dates follow a predecessor instead | no | no |
| osTicket 1.18 | business-hours schedules per department, several combinable | yes, in holiday schedules | no, measured | not measured | no; the grammar repeats the American moving feasts, not the Dutch ones |
| Znuny 7.3, OTOBO 11 | nine, in the core | month and day only | yes, `EscalationIndexRebuild` | Znuny as a free package, OTOBO in the core and shipped off | no; Goede Vrijdag 2027 returned as a working day |
| iTop 3.2 | none in the core, free extension | in the extension | no | **yes, core, on by default** | no |
| Odoo 19 | one per company, and nothing plans with it | yes, typed by hand | no | no | no; Goede Vrijdag 2027 returned as a working day |
| Plane, Deck, Kanboard, Vikunja | none | none | n/a | no | no; Kanboard and Vikunja count weekends and turn 31 August plus a month into 1 October |
| Frappe Helpdesk 1.30 | one per SLA, administered | yes, every date typed by hand | **yes**, saving the holiday list recomputes every open ticket that links it | **yes**, by status category, measured | no |
| Request Tracker 5.0 | named schedules, administered as JSON in the config editor | yes, `MM-DD` recurring or `YYYY-MM-DD` once | no; a save reached one of five web workers until a restart | **yes**, by config | no |
| Gitea 1.27, Taiga 6.10 | none | none | n/a | no | no |
| Jira Software DC (documented) | a board setting for charts, read by no due date | Monday to Friday for a rule | not documented | not documented | no |
| Easy Redmine (documented) | one, with the country's holidays imported from an ICS feed | yes, from the feed | not documented | documented, inside the SLA hours | no |
| JSM Cloud (documented) | per service space | dates by hand; Data Center imports ICS | documented for an SLA edit | documented | no, on 0 of 1,184 pages |
| YouTrack (documented) | per helpdesk project, hours only | **none** | not documented | documented | no, on 0 of 619 pages |

The driven rows are the batch tables in `deadline-engines-batch2.md` (GLPI to Redmine), `deadline-engines-batch3.md` (osTicket), `deadline-engines-batch5.md` (Znuny, OTOBO, iTop), the section 8 table in `deadline-engines-batch6.md` (pauses, Odoo, Deck), `deadline-engines-batch7.md` (Kanboard, Vikunja), `deadline-engines-batch9.md` (Frappe Helpdesk, Request Tracker) and `deadline-engines-batch11.md` (Gitea, Taiga).

**Two engines that agree with each other still disagree with the law.** Frappe Helpdesk counts in 527 lines of Python and Request Tracker in a CPAN module wrapped in 258 lines of Perl. Different people, different products, different decades. Run on one Dutch calendar over the 2026 and 2027 feasts, they returned the same date to the minute on every one of the eight shared questions (`deadline-engines-batch9.md`). They also both landed a 2027 term on Tweede Paasdag, because Easter 2027 was typed nowhere and neither grammar has a rule beyond a fixed month and day. Frappe types every date every year; RT recurs a fixed date and types the movable ones. Two traps sit beside that: a fresh Frappe site counts in `Asia/Kolkata` until an administrator sets the zone, and an RT schedule saved through the config editor reached one of five web workers until a restart.

**One date field, three write paths, three instants.** Gitea stores `issue.due_date` as one column, and the three API endpoints that write it disagree by 24 hours for the same submitted date: create keeps the raw instant, edit makes it end of day in the caller's zone, and the dedicated deadline endpoint makes it end of day in the instance's zone. Only the third reads the administered setting. A user sees it on one screen: the issue timeline reads "modified the due date ... to January 31, 2028" and the sidebar twelve centimetres away reads "February 1, 2028", for the same stored value (`deadline-engines-batch11.md`). Taiga cannot have the bug, because its `due_date` is a date and not a timestamp, and it is the only `yes` on the new row.

**That new row indicts dossiq, and it is an audit instruction rather than a competitive one.** Proposed row 8.22 asks whether every write path to a date field agrees, and dossiq rates `no` from the source. **Nine controllers can set a date on a case**, `TermijnController`, `DeadlineReportingController`, `ZrcController`, `DwangsomController`, `ComplaintController`, `ConsultationController`, `AdviceController`, `WOOAssessmentController` and `ContactMomentController`. Ten services carry a deadline in their name, beside `WorkingDayCalculator` and `SlaCalculator`. The only normaliser in the tree is private, `normaliseDate()` at `lib/Service/Doorlooptijd/CaseEnricher.php:252`, reachable by one caller. Nothing asserts that the nine writers agree and no test compares them (`procest/_round4/compare/proposed-rows-batch11.md`). Ten batches measured a term engine by finding where a date is stored and reading the arithmetic once. Gitea is the proof that the unit of measurement is the writer, not the field.

**Nothing in twenty-four non-Dutch columns computes Easter.** Znuny on a Dutch calendar moved 16 working hours from 2 April 2026 12:00 to 7 April 15:00, skipping Goede Vrijdag and Tweede Paasdag, and then returned Goede Vrijdag 2027 as a working day, because its grammar has no rule beyond a fixed month and day. OTOBO gives identical numbers on identical code. Odoo's Dutch calendar skipped the 2026 feasts typed by hand and failed 2027 the same way. Frappe Helpdesk and Request Tracker landed a 2027 term on Tweede Paasdag. The word occurs on 0 of the 2,896 pages batch 8 read. dossiq computes it, in `lib/Service/WorkingDayCalculator.php` with `easterSunday()` at line 253 and `holidays(int $year)` at 219, and has the calendar as an OpenRegister schema (`data.family.leadLost`; `deadline-engines-batch8.md`, `deadline-engines-batch9.md`, `deadline-engines-batch11.md`).

**Two ideas worth taking.** OpenProject recomputes existing deadlines when the calendar changes and writes the reason into the history as `Journal::CausedByWorkingDayChanges`; that is pending row 8.17. Redmine recomputes a dependent's dates when a predecessor slips; that is pending row Q3.21. Neither does both. dossiq does neither. Both are in the umbrella as `terms-on-the-engine-calendar` and `dependent-term-follows-predecessor`.

## What the family round says about dossiq

Section 08 of the ledger, `data.dossiqLessons`, holds fifteen findings as of v7. The ledger's own closing line: start with the deadline rule, then wire the enum labels, then read each Throwable site with proposed row 10.14 open beside it.

| finding | what was measured |
|---|---|
| Three in ten statutory deadlines land on a day the law says must move | 462 of 1,520 computations, 30.4%, in `deadline-engines.md`. Promoted rows 8.11 and 8.17 carry the fix. |
| Enum labels exist on one property and nowhere else | 230 enum declarations across `lib/Settings/dossiq_register.json` (116) and `lib/Settings/register.d/` (114), one carrying `x-enum-labels`, recounted 2026-09-13. |
| Sites that turn a failure into an absence | `catch (\Throwable)` followed by `return null` under `lib/Service/`: the ledger counts 24; the re-read counts 47 sites in 37 files among 276 catches. Redmine's 204, osTicket's 302, Znuny's 200 with an error object, OTOBO, iTop and Odoo's RPC surfaces are the same defect in six costumes. Proposed row Q10.14. |
| Cells that read schema only | Batch 4 counted eight cells in the published matrix where dossiq's evidence is a registered schema with no surface; the re-read finds 27 schemas with no surface anywhere, an upper bound for the structural test in `no-schema-without-a-surface`. Personal-data storage registered ahead of the feature that uses it is a verwerkingsregister question. |
| dossiq computes Easter and none of twenty-four competitors does | see the working-day findings above. |
| Two OTRS forks, an ERP and a help desk are ahead on the comparable half | OTOBO 78, Odoo 78, Znuny 73 and Frappe Helpdesk 72 against 71. |
| Two engines that agree with each other still disagree with ours | Batch 9. Frappe Helpdesk's `calc_time` and Request Tracker's `RT::SLA` returned the same date to the minute on every shared question of a Dutch year, and both put a 2027 term on Tweede Paasdag. dossiq's five calendar rows would not. |
| The plan boundary sits on the row the buyer needs most | Batch 10. Easy Redmine's HelpDesk, and with it every SLA row in the column, is the Platform plan; the CRM, Assets, the Knowledge Base and branding are priced add-ons. "100% open source" and an SLA on the feature page are two true sentences about two different purchases. |
| Seven document rows a kanban board passes on our own host | Deck scores `yes` on seven of 23 document rows, six of them Nextcloud's: versions, a share with an expiry, a lock, a trash, a preview and a file manager. dossiq runs on the same host; the rest are one design decision away (`procest/nextcloud-deck/round4/open-core.md`). |
| A reply with a rewritten subject line is a lost letter | `InboundEmailJob.php` links a reply by the `[ZAAK-…]` subject tag and reads neither `In-Reply-To` nor `References`; Odoo reads the headers first (`mail_thread.py:1185-1193`). Proposed row 6.18. |
| A right on a parent case stops at the child | `lib/Service/CaseAccessGuard.php` never reads `parentCase`; Vikunja resolves access through the parent chain, measured three levels down. Proposed row 13.23. |
| A paid feature can answer 404 | Vikunja's gate; read the licence package before recommending an AGPL product. |
| The citizen reads our state machine | JSM maps the internal status to a customer's name per request type; a ZGW `statustype` has a `statustekst` for exactly this reason. Proposed row 6.19, opened as `citizen-status-labels`. |
| One row where dossiq beats OpenProject outright | Pending row 11.27, the capability is in the edition you can deploy. |
| The cheapest fix the round surfaced | Pending row 8.18, an administrator runs the term engine against a date of their choosing. dossiq scores `no`; osTicket was measured passing it live. |

Batch 11 adds a sixteenth that the ledger has not folded in: nine controllers can set a date on a case and the only normaliser in the tree is private, which is proposed row 8.22 above.

## The gap register, counted by owner

`procest/_gaps/gap-register.md`, generated by `procest/_gaps/tools/build-gap-register.py` and rebuilt on the evening of 2026-09-13 from the 206-row matrix, the 19 promoted rows and the 43 pending proposals in the ledger and the batch files. A row is a gap when dossiq's rating is not `yes`. Every gap has one owner, the app that holds the logic, and dossiq keeps a half on every row it does not own: a leaf placement, a schema declaration, a store call or an event (`procest/_gaps/ownership-rules.md`).

**268 rows read, 158 gaps**: 110 from the matrix, 16 from the promoted rows, 32 from the pending proposals. 100 are `partial`, 58 are `no`. 11 of the 26 statutory rows are gaps.

| owner | gaps | S | M | L | carried | not carried |
|---|---|---|---|---|---|---|
| openregister | 68 | 51 | 16 | 1 | 64 | 4 |
| dossiq | 32 | 26 | 5 | 1 | 30 | 2 |
| integriq | 15 | 5 | 7 | 3 | 11 | 4 |
| nextcloud (the platform) | 13 | 13 | 0 | 0 | 9 | 4 |
| buildiq | 6 | 0 | 4 | 2 | 6 | 0 |
| portaliq | 6 | 0 | 6 | 0 | 5 | 1 |
| nextcloud-vue | 5 | 3 | 2 | 0 | 4 | 1 |
| pipelinq | 3 | 1 | 2 | 0 | 3 | 0 |
| filinq | 3 | 0 | 3 | 0 | 3 | 0 |
| humaniq | 2 | 2 | 0 | 0 | 2 | 0 |
| shillinq | 2 | 0 | 2 | 0 | 2 | 0 |
| decidiq | 1 | 1 | 0 | 0 | 1 | 0 |
| thematiq | 1 | 1 | 0 | 0 | 1 | 0 |
| hermiq | 1 | 0 | 1 | 0 | 0 | 1 |
| **total** | **158** | **103** | **48** | **7** | **141** | **17** |

Sizes: S is a placement, a declaration or one action, under a day; M a change with a handful of tasks; L a new mechanism or a certification track. Carried means a spec, an open change, or a change the OpenSpec phase opened, whose scope contains the row. 141 gaps have one and 17 do not. Seventy-one changes carry seventy of the rows: dossiq 33, openregister 25, filinq 3, portaliq 3, buildiq 2, shillinq 2, nextcloud-vue 1, pipelinq 1, integriq 1. Of the 17 not carried, eight needed a change opened and nine are deliberate no's, platform facts to write down, or a proposal waiting for a driven `yes`. opencatalogi, planninq and keepiq carry no gap.

Why the two versions differ, and the arithmetic is simple. 258 rows became 268, because batch 7 reached the ledger with five proposals and batches 9 and 10 added five the ledger has not folded in. 163 gaps became 158, because the dossiq re-read closed twelve rows and seven of the ten new proposals rate dossiq below `yes`. The [re-read of 2026-09-13](competitor-gap-re-read-2026-09-13.md) read all 40 rows the register flags, the 36 marked stale plus 4 the archived changes had moved, against `828da9a69`: 12 closed, 24 stay, 4 stay narrowed.

The eight uncovered gaps were opened the same evening, in a sweep of eleven changes. Five are dossiq's, `status-capacity-limit` (Q3.22), `intake-says-when-the-term-starts` (Q8.21), `archived-cases-leave-the-lenses` (Q2.33), `deelzaken-inherit-the-parent-grants` (Q13.23) and `cases-views-are-places` (Q9.16), merged as [dossiq#2684](https://github.com/ConductionNL/dossiq/pull/2684) and taking the umbrella to 38 changes. Six are the owners': openregister `object-archive-state` and `rbac-inherits-to-children`, integriq `signed-outbound-webhooks` and `objecten-api-facade`, portaliq `embedded-intake-form`, nextcloud-vue `saved-view-as-a-place`. The two umbrellas are what the register became: [dossiq#2643](https://github.com/ConductionNL/dossiq/pull/2643) and [openregister#3688](https://github.com/ConductionNL/openregister/pull/3688).

## The five to build first

The register's own order (`procest/_gaps/README.md`), which the dossiq umbrella adopts as the head of its build order.

1. **The working calendar, administered, with the Atw roll and recompute.** Rows 8.12, 8.11, Q8.17, Q8.19 and Q8.20 on openregister, Q8.16 on dossiq. Statutory correctness, not a feature: 30.4% of the terms dossiq stores land on a day the Atw moves. Five openregister changes carry it, `working-calendar-admin`, `end-date-roll-on-the-calendar`, `calendar-time-zone`, `calendar-change-recomputes-timers` and `term-engine-diagnostic`, and two dossiq ones, `terms-on-the-engine-calendar` and `counting-mode-per-term`.
2. **Watchers on a case.** Row 13.18, openregister, S. Eight of eight non-Dutch systems in the register's reading pass it and dossiq is the only `no` in the ledger; batch 7 added Vikunja as a passer and Kanboard as a `no`. In the umbrella as `case-followers` over openregister's `object-watchers`.
3. **Internal versus public per timeline entry.** Row 6.15, openregister, S. Seven of eight pass, dossiq fails, and the citizen portal cannot show a case timeline until every entry says which side of the counter it belongs on. In the umbrella as `timeline-entries-default-internal`, after `citizen-status-labels`.
4. **Refusals carry a status.** Row Q10.14, dossiq, M. The fail-open shape gate 13 exists to catch, and no test asserts the status code beside the state. In the umbrella as `refusals-carry-a-status`, with the re-read's counts: 47 sites in 37 files, 93 of 106 controller suites asserting a status.
5. **Substituted work reaches My work.** Row 13.17, dossiq, S. The spec `handler-vervanging-waarneming` requires it and `fetchSubstitutedWork()` has no call site, so a case assigned to someone on leave is invisible until it breaches. In the umbrella as `substituted-work-reaches-my-work`, with humaniq's leave as the absence signal.

The ledger's own first pick, the schema-only registrations (Q11.31), is sixth: right, a privacy argument, and larger than the eight cells the matrix named once the re-read counted 27.

## Where to check a cell

Every system's code, documentation, API and issue tracker links, and the corpus directory that holds its column, are on [competitor sources](competitor-sources.md), generated from the ledger's sources register. The comparison pages that seeded the candidate set are listed there too. For the numbers on this page, the files to open are `procest/_round4/compare/twenty-four-system-tally.md` (the ranking), `statutory-rows.md` (the 26), `open-core-batch10.md` and `open-core-batch11.md` (the eleventh and twelfth shapes), `deadline-engines-batch9.md` and `deadline-engines-batch11.md` (the two engines that agree, and the field with three writers), `deadline-engines.md` (the 30.4%) and `procest/_gaps/README.md` (the counts by owner and the five), all in `ConductionNL/market-intelligence` on `development`.

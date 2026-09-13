# Competitor parity, September 2026

This page is the result of the competitor parity programme, as of ledger v6 (2026-09-13). Twenty-two systems sit in the matrix beside dossiq: four Dutch case systems, sixteen non-Dutch systems that were installed and driven, and two closed products rated from their documentation. Every number below comes from a corpus file, named by path. The record is `procest/_ledger/parity-ledger.html` on `development` in `ConductionNL/market-intelligence`; the corpus paths on this page are relative to that repository. The sources per system, with the links a reader needs to check a cell, are on [competitor sources](competitor-sources.md).

Two batches are still running: batch 9 drives Request Tracker and Frappe Helpdesk, batch 10 rates Jira Data Center and Easy8 (formerly Easy Redmine) from documents. Each adds a row to one of the two ranking tables below, and nothing else on this page moves when they land. Where a count depends on the batch set, the text says "as of ledger v6" and names the count.

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

As of ledger v6, sixteen non-Dutch systems are driven and two are documented (JSM Cloud, YouTrack 2026.2). Batch 9 adds two driven rows and batch 10 two documented rows.

### The evidence convention

The method paragraph of the ledger's handover (`data.handover.method`) is the rule set every batch worked under. The parts that decide a cell:

- Never rate from a feature page. A rating cites a path with a line, a screen, or a number an engine returned.
- Grep for the route, not the model. A model in the source is not a capability; Plane's epics answer 404 while `is_epic` sits in the tree.
- Propose a row only if at least one corpus system scores `yes` on it. A row nobody passes measures the standard rather than the products.
- Recount every tally by script. Batch 1 mistyped six of thirteen totals and batch 2 seven of twenty-six, so `procest/_round4/tools/corpus-tally.py` counts and ranks every column, and `count-column.py` fails when its own tally lines disagree.
- Check a comparative claim (first, only, best) against the published files before writing it. Three such claims failed in batch 3, two more in batch 7.
- A product you configured is doing what you told it. Batch 7's own automatic action fired on the task a recurrence created and corrupted a measurement until it was removed.

### The counting rule: 225 = 206 + 19

The ledger holds **225 rows**: the original 206-row matrix from round 2 (`procest/_round2/compare/M1-functionality.md`), plus the **19** of round 3's 21 proposals that were promoted after two were dropped as restatements (`procest/_round4/compare/promoted-rows-batch3.md`). A proposal is not a row until it is promoted, so the rows batches 1 to 8 proposed, 11 + 5 + 3 + 3 + 3 + 3 + 5 + 5, are **38 pending** as of ledger v6 (`data.family.counting`). Adding 206 + 21 + 11 double counts the promoted nineteen. Say which artefact you mean: the corpus M1 file is 206, the ledger is 225.

Proposals are kept out of the matrix for a reason the round 2 file states: a proposed row has a verdict for the system that produced it and nothing for the systems nobody re-read, and filling those blanks would be a fabricated reading. Pending proposals carry a `Q` prefix in the gap register because their ids collide with promoted ids (Q2.27 is identifier uniqueness, 2.27 is the edit lock).

### The statutory split

Round 3 estimated that roughly forty rows named Dutch statutory concepts. Round 4 replaced the estimate with a test and published the list: **26 rows** (`procest/_round4/compare/statutory-rows.md`). A row is statutory when it names a Dutch statutory instrument, a national register, a government authentication scheme, a government interoperability standard, or a Dutch government software vendor. Everything else is domain neutral, even when Dutch municipalities are the reason it matters: retention, e-signing, payments, SSO and citizen portals are ordinary product capabilities a Dutch buyer happens to need.

The test cuts both ways. Classifying a row as statutory removes it from the denominator, which raises a competitor's headline rather than lowering it. The headline is therefore the **domain-neutral 180**, and the 26 are reported separately.

## The ranking on the domain-neutral 180

Counted and ranked by `procest/_round4/tools/corpus-tally.py`, printed in `procest/_round4/compare/eighteen-system-tally.md`. The script sorts on `yes` only and keeps file order on a tie, so OTOBO prints above Odoo and nothing in the method ranks on `partial`. It prints two rankings, so a documented cell is never read as a driven one.

### Driven columns, twenty as of ledger v6

| rank | system | yes | partial | family |
|---|---|---|---|---|
| 1 | xxllnc Zaken | 125 | 31 | Dutch case systems |
| 2 | GZAC/Valtimo | 82 | 46 | Dutch case systems |
| 3 | OTOBO 11.0 | 78 | 45 | help desks and ITSM |
| 4 | Odoo 19.0 | 78 | 51 | project tools and boards |
| 5 | Znuny 7.3 | 73 | 45 | help desks and ITSM |
| 6 | **dossiq** | **71** | **77** | Dutch case systems |
| 7 | iTop 3.2 | 58 | 49 | help desks and ITSM |
| 8 | OpenCase | 58 | 38 | Dutch case systems (Danish, on Nextcloud) |
| 9 | GLPI 11 | 56 | 66 | help desks and ITSM |
| 10 | osTicket 1.18 | 55 | 43 | help desks and ITSM |
| 11 | Zammad 7 | 53 | 57 | help desks and ITSM |
| 12 | OpenProject 16 | 46 | 64 | project tools and boards |
| 13 | Redmine 7 | 43 | 51 | forges |
| 14 | Deck 1.18 | 37 | 37 | project tools and boards |
| 15 | Kanboard 1.2 | 37 | 39 | project tools and boards |
| 16 | GitLab CE 19.3 | 36 | 56 | forges |
| 17 | Vikunja 2.6 | 35 | 26 | project tools and boards |
| 18 | Forgejo 16 | 23 | 47 | forges |
| 19 | Plane 1.4 | 22 | 49 | project tools and boards |
| 20 | FreeScout 1.8 | 22 | 31 | help desks and ITSM |

Batch 9 adds Request Tracker and Frappe Helpdesk to this table when it lands.

### Documented columns, two as of ledger v6, graded `documented, not driven`

| system | yes | partial | where it would sit among all columns |
|---|---|---|---|
| JSM Cloud | 83 | 53 | second of twenty-two, above GZAC |
| YouTrack 2026.2 | 78 | 44 | sixth of twenty-two, level with OTOBO and Odoo |

Both numbers are upper bounds. OTOBO's `Ticket::Service` shipped switched off and ignored a configured SLA; a documentation page would not have said so (`procest/_round4/compare/open-core-batch8.md`). Batch 10 adds Jira Data Center and Easy8 to this table.

### The full ranking, twenty-two columns

With the documented columns admitted as upper bounds, the same script prints: xxllnc Zaken 125, JSM Cloud 83 (documented), GZAC 82, OTOBO 78, Odoo 78, YouTrack 78 (documented), Znuny 73, **dossiq 71**, iTop 58, OpenCase 58, GLPI 56, osTicket 55, Zammad 53, OpenProject 46, Redmine 43, Deck 37, Kanboard 37, GitLab CE 36, Vikunja 35, Forgejo 23, Plane 22, FreeScout 22. dossiq is sixth of twenty driven columns and eighth of twenty-two.

### The families

From `procest/_round4/compare/eighteen-system-tally.md`:

| family | driven systems | best domain-neutral `yes` |
|---|---|---|
| Dutch case systems | xxllnc Zaken, GZAC, dossiq, OpenCase | 125 |
| help desks and ITSM | OTOBO 78, Znuny 73, iTop 58, GLPI 56, osTicket 55, Zammad 53, FreeScout 22 | 78 |
| project tools and boards | Odoo 78, OpenProject 46, Deck 37, Kanboard 37, Vikunja 35, Plane 22 | 78 |
| forges | Redmine 43, GitLab CE 36, Forgejo 23 | 43 |

## Where dossiq stands, and what moved it

**dossiq scores 71 of 180.** The combined 84 of 206 that earlier pages quoted includes thirteen `yes` answers on statutory rows no non-Dutch system can score, so it measures the jurisdiction as much as the product. On the move to 180 every competitor loses nothing, because none of them scored those rows anyway. The gap to GLPI narrows from 28 rows to 15 (`data.family.comparable`).

What moved the position, batch by batch (`data.family.leadLost`):

- **Batch 4, Znuny 7.3.6 at 73.** The first time in four rounds that a non-Dutch product beat dossiq on the comparable half. Not a help desk that grew: a process engine, an ACL engine, custom fields, nine working calendars, a generic interface and a customer portal, with none of its 37 vendor packages priced.
- **Batch 5, OTOBO 11.0.17 at 78.** The other OTRS fork. The same product as Znuny in 881 of its 1,132 modules, identical on 231 of 247 rows; the 16 rows that differ are what each vendor built after the 2019 split, and 13 went OTOBO's way (`procest/_round4/compare/otobo-vs-znuny.md`).
- **Batch 6, Odoo 19.0 Community at 78.** Ties OTOBO from the platform under its project module: one chatter, one automation engine, one record-rule layer, one form builder and typed properties, with no case number, no term engine and no document model.
- **Batch 7 changed nothing above dossiq.** Kanboard 37 and Vikunja 35 landed fifteenth and seventeenth.
- **Batch 8 put two documented ceilings beside the driven top.** JSM Cloud 83 and YouTrack 78, never driven.

Two of the five driven columns above dossiq are Dutch case systems, two are forks of one German service desk, and one is a Belgian ERP's project module. The only two systems above GZAC on the comparable half are xxllnc Zaken and a closed product nobody in this corpus has run.

### The shape per area

`yes` answers per area, dossiq beside the strongest columns the tallies print per area. dossiq's column is the 206-row reading of 2026-09-07 with the 2026-09-08 corrections (`procest/_round2/compare/M1-functionality.md`, tally per area); the re-read of 2026-09-13 closed twelve more rows after this count and is cited on the [re-read page](competitor-gap-re-read-2026-09-13.md). Non-Dutch columns are from `twelve-system-tally.md`, `fourteen-system-tally.md` and `eighteen-system-tally.md` in `procest/_round4/compare/`.

| area | rows | dossiq | xxllnc | GZAC | OpenCase | OTOBO | Odoo | Znuny | Deck |
|---|---|---|---|---|---|---|---|---|---|
| 1 Intake | 13 | 4 | 9 | 3 | 7 | 7 | 7 | 7 | 2 |
| 2 Case core | 22 | 9 | 16 | 13 | 7 | 10 | 11 | 10 | 7 |
| 3 Tasks and phases | 19 | 6 | 11 | 14 | 2 | 10 | 8 | 9 | 3 |
| 4 Documents | 23 | 7 | 18 | 5 | 13 | 2 | 3 | 1 | 7 |
| 5 Parties and contacts | 13 | 5 | 11 | 1 | 7 | 4 | 3 | 3 | 0 |
| 6 Communication | 14 | 4 | 10 | 2 | 3 | 6 | 7 | 7 | 2 |
| 7 Decisions | 7 | **6** | 3 | 1 | 1 | 0 | 0 | 0 | 0 |
| 8 Deadlines | 10 | **8** | 7 | 4 | 2 | 5 | 5 | 5 | 2 |
| 9 Search | 12 | 4 | 10 | 5 | 5 | 8 | 8 | 7 | 3 |
| 10 Reporting | 10 | 5 | 7 | 5 | 1 | 7 | 6 | 7 | 1 |
| 11 Configuration | 24 | 9 | 13 | 18 | 3 | 10 | 11 | 11 | 4 |
| 12 Integrations | 23 | **11** | 10 | 9 | 4 | 4 | 3 | 2 | 2 |
| 13 Access and privacy | 16 | 6 | 11 | 6 | 7 | 5 | 6 | 4 | 4 |

Three sentences the tallies repeat:

- **Decisions are where every non-Dutch system collapses.** Sixteen driven non-Dutch columns score 0 of 7. The nearest object in the whole family is OpenProject's `MeetingOutcome`, whose `kind` enum has a `decision` value and no approver, quorum, number, effective date or publication (`procest/_round4/compare/findings.md`).
- **Documents are where the whole non-Dutch corpus is weakest.** Znuny and GitLab have 46 document rows between them and two `yes` answers; Kanboard and Vikunja the same. The one non-Dutch column that does well there, Deck at 7 of 23, does so through its host: an attachment is a file in Files, so versions, a share with an expiry, a lock, a trash, a preview and a file manager come free (`procest/_round4/compare/eighteen-system-tally.md`).
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

**The proof.** Eighteen non-Dutch systems, sixteen driven and two documented, read against all 26 rows: **468 cells, zero `yes`** (416 driven, 52 documented). Every `partial` is the generic half of a Dutch question: OTOBO, iTop and JSM hold three each, YouTrack two, Odoo, Deck, Kanboard and Vikunja one each on 5.11. Both closed marketplaces were searched by name before any statutory `no` was written: the Atlassian Marketplace returns 76 apps for DigiD, all Digi, Digital or DigitalOcean, 7 for eHerkenning, all Gherkin editors, and 0 for ZGW; the JetBrains Marketplace returns 0, 0 and 0 and corrects DigiD to "digit" (`data.family.statutoryProof`, measured in `procest/_round4/compare/eighteen-system-tally.md`).

That is why the 180 is the headline. Counting the 26 in a product comparison measures the jurisdiction, and the 26 still matter enormously to the buyer.

## Ten open-core shapes

The matrix has 206 rows and none of them asked whether the capability just scored is in the build you can install. Round 4 checked every system against its source tree rather than its licence page, and found ten shapes a missing capability takes (`data.handover.openCore`, with the per-batch reading in `procest/_round4/compare/open-core-batch*.md`). Each shape is named after the system that defined it.

| # | shape | defined by | what was read |
|---|---|---|---|
| 1 | Gates nothing | GLPI, Zammad, Redmine, Forgejo, osTicket, Znuny, OTOBO, Deck, Kanboard | zero entitlement hits over the tree; Znuny's 37 and OTOBO's 45 vendor packages none priced; Kanboard's 163-plugin directory has no price field |
| 2 | Gates at runtime, some features degrading silently | OpenProject | 32 features behind `EnterpriseToken.allows_to?`, the GPL source present and refusing to run; four degrade silently, including internal comments becoming invisible to everyone |
| 3 | Omits the paid half entirely | Plane | no entitlement machinery; the paid features are absent and only the seams remain, such as `useBulkOperationStatus = () => false` |
| 4 | Prices modules with the paywall through the free half | FreeScout | 72 of 75 modules priced, 539.73 dollars for the set, 33 matrix rows gated |
| 5 | Removes the paid code at build time | GitLab CE | 1,616 no-op seams in 1,599 files; 22 rows settled by a 404 or 400 from the running instance |
| 6 | Sells a separate non-public edition beside an AGPL core | OpenCase | the enterprise edition (AI, digital post, CPR and CVR) gated on `enterprise_version` |
| 7 | Sells a catalogue of closed extensions beside a complete core | iTop | 103 extensions on the store, 70 AGPL, 33 under the Combodo Software License, none in the tree and none gated by it |
| 8 | Keeps the paid half in a private repository and labels part of it inside the free product | Odoo | 21 `to_buy` records under OEEL-1 and 45 upgrade badges; the edition boundary last moved in 2019 by the git history |
| 9 | Draws the paid half as a column in a plan table | JSM Cloud | no code to read; 16 cells Premium, 8 Enterprise, 5 Atlassian Guard, 2 Data Center; incident, change and problem moved to Premium on 2024-10-16 |
| 10 | Keeps the paid code in the tree and answers 404 for it by design | Vikunja | three features behind a key checked daily against the vendor; `RequireFeature` serves 404 "so gated routes are indistinguishable from unregistered ones"; with a key the check reports user counts to the vendor |

Two systems are not shapes. Deck withholds nothing for money, and its score is set by which host surfaces it plugs into. YouTrack is shape one and closed: nothing withheld above ten users and three agents, and what a buyer does not get is the source, which the shapes do not measure.

What sixteen driven systems say about the question: copyleft proves nothing, since thirteen of the sixteen are GPL, LGPL or AGPL and among them is every shape found in a tree; a grep finds a gate and never a catalogue or a private repository, so read the store, the Apps page and the git history as well; and a documented column cannot find a silent switch, which is why a closed product's number is an upper bound. dossiq passes pending row 11.27, the capability is in the edition you can deploy, because there is no paid half: `grep -rniE "licen[cs]e_key|entitlement|enterprise_feature|allows_to" lib/ src/` returns 0 hits (`data.family.proposals`).

## The working-day findings

Round 4 asked the same question of every system: when a product says a term ends on a date, what counted? The files are `procest/_round4/compare/deadline-engines.md` and `deadline-engines-batch2.md` to `deadline-engines-batch8.md`.

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
| JSM Cloud (documented) | per service space | dates by hand; Data Center imports ICS | documented for an SLA edit | documented | no, on 0 of 1,184 pages |
| YouTrack (documented) | per helpdesk project, hours only | **none** | not documented | documented | no, on 0 of 619 pages |

The driven rows are the batch tables in `deadline-engines-batch2.md` (GLPI to Redmine), `deadline-engines-batch3.md` (osTicket), `deadline-engines-batch5.md` (Znuny, OTOBO, iTop), the section 8 table in `deadline-engines-batch6.md` (pauses, Odoo, Deck) and `deadline-engines-batch7.md` (Kanboard, Vikunja).

**Nothing in eighteen systems computes Easter.** Znuny on a Dutch calendar moved 16 working hours from 2 April 2026 12:00 to 7 April 15:00, skipping Goede Vrijdag and Tweede Paasdag, and then returned Goede Vrijdag 2027 as a working day, because its grammar has no rule beyond a fixed month and day. OTOBO gives identical numbers on identical code. Odoo's Dutch calendar skipped the 2026 feasts typed by hand and failed 2027 the same way. The word occurs on 0 of the 2,896 pages batch 8 read. dossiq computes it, and has the calendar as an OpenRegister schema (`data.family.leadLost`; `deadline-engines-batch8.md`).

**Two ideas worth taking.** OpenProject recomputes existing deadlines when the calendar changes and writes the reason into the history as `Journal::CausedByWorkingDayChanges`; that is pending row 8.17. Redmine recomputes a dependent's dates when a predecessor slips; that is pending row Q3.21. Neither does both. dossiq does neither. Both are in the umbrella as `terms-on-the-engine-calendar` and `dependent-term-follows-predecessor`.

## What the family round says about dossiq

Section 08 of the ledger, `data.dossiqLessons`, holds thirteen findings as of v6. The ledger's own closing line: start with the deadline rule, then wire the enum labels, then read each Throwable site with proposed row 10.14 open beside it.

| finding | what was measured |
|---|---|
| Three in ten statutory deadlines land on a day the law says must move | 462 of 1,520 computations, 30.4%, in `deadline-engines.md`. Promoted rows 8.11 and 8.17 carry the fix. |
| Enum labels exist on one property and nowhere else | 230 enum declarations across `lib/Settings/dossiq_register.json` (116) and `lib/Settings/register.d/` (114), one carrying `x-enum-labels`, recounted 2026-09-13. |
| Sites that turn a failure into an absence | `catch (\Throwable)` followed by `return null` under `lib/Service/`: the ledger counts 24; the re-read counts 47 sites in 37 files among 276 catches. Redmine's 204, osTicket's 302, Znuny's 200 with an error object, OTOBO, iTop and Odoo's RPC surfaces are the same defect in six costumes. Proposed row Q10.14. |
| Cells that read schema only | Batch 4 counted eight cells in the published matrix where dossiq's evidence is a registered schema with no surface; the re-read finds 27 schemas with no surface anywhere, an upper bound for the structural test in `no-schema-without-a-surface`. Personal-data storage registered ahead of the feature that uses it is a verwerkingsregister question. |
| dossiq computes Easter and none of eighteen competitors does | see the working-day findings above. |
| Two OTRS forks and an ERP are ahead on the comparable half | OTOBO 78, Odoo 78, Znuny 73 against 71. |
| Seven document rows a kanban board passes on our own host | Deck scores `yes` on seven of 23 document rows, six of them Nextcloud's: versions, a share with an expiry, a lock, a trash, a preview and a file manager. dossiq runs on the same host; the rest are one design decision away (`procest/nextcloud-deck/round4/open-core.md`). |
| A reply with a rewritten subject line is a lost letter | `InboundEmailJob.php` links a reply by the `[ZAAK-…]` subject tag and reads neither `In-Reply-To` nor `References`; Odoo reads the headers first (`mail_thread.py:1185-1193`). Proposed row 6.18. |
| A right on a parent case stops at the child | `lib/Service/CaseAccessGuard.php` never reads `parentCase`; Vikunja resolves access through the parent chain, measured three levels down. Proposed row 13.23. |
| A paid feature can answer 404 | Vikunja's gate; read the licence package before recommending an AGPL product. |
| The citizen reads our state machine | JSM maps the internal status to a customer's name per request type; a ZGW `statustype` has a `statustekst` for exactly this reason. Proposed row 6.19, opened as `citizen-status-labels`. |
| One row where dossiq beats OpenProject outright | Pending row 11.27, the capability is in the edition you can deploy. |
| The cheapest fix the round surfaced | Pending row 8.18, an administrator runs the term engine against a date of their choosing. dossiq scores `no`; osTicket was measured passing it live. |

## The gap register, counted by owner

`procest/_gaps/gap-register.md`, generated by `procest/_gaps/tools/build-gap-register.py` on 2026-09-13 from the 206-row matrix, the 19 promoted rows and the 33 pending proposals in the ledger and batch files at that time. A row is a gap when dossiq's rating is not `yes`. Every gap has one owner, the app that holds the logic, and dossiq keeps a half on every row it does not own: a leaf placement, a schema declaration, a store call or an event (`procest/_gaps/ownership-rules.md`).

**258 rows read, 163 gaps**: 122 from the matrix, 16 from the promoted rows, 25 from the pending proposals. 104 are `partial`, 59 are `no`. 13 of the 26 statutory rows are gaps.

| owner | gaps | S | M | L | covered | uncovered |
|---|---|---|---|---|---|---|
| dossiq | 38 | 30 | 7 | 1 | 20 | 18 |
| openregister | 70 | 54 | 15 | 1 | 45 | 25 |
| integriq | 14 | 5 | 6 | 3 | 11 | 3 |
| nextcloud (the platform) | 13 | 13 | 0 | 0 | 8 | 5 |
| buildiq | 6 | 0 | 4 | 2 | 3 | 3 |
| portaliq | 5 | 0 | 5 | 0 | 2 | 3 |
| nextcloud-vue | 4 | 3 | 1 | 0 | 3 | 1 |
| pipelinq | 3 | 1 | 2 | 0 | 2 | 1 |
| filinq | 3 | 0 | 3 | 0 | 0 | 3 |
| humaniq | 2 | 2 | 0 | 0 | 2 | 0 |
| shillinq | 2 | 0 | 2 | 0 | 0 | 2 |
| decidiq | 1 | 1 | 0 | 0 | 1 | 0 |
| thematiq | 1 | 1 | 0 | 0 | 1 | 0 |
| hermiq | 1 | 0 | 1 | 0 | 0 | 1 |
| **total** | **163** | **110** | **46** | **7** | **98** | **65** |

Sizes: S is a placement, a declaration or one action, under a day; M a change with a handful of tasks; L a new mechanism or a certification track. Covered means a spec or an open change whose scope contains the row, checked by opening it. Of the 98 covered, 86 are closed outright by that artefact and 12 still need a dossiq-side change for dossiq's half. Of the 65 uncovered, 56 need a new change and 9 are deliberate no's or platform facts to write down. 68 gaps need a change opened, under 65 distinct slugs. opencatalogi, planninq and keepiq carry no gap.

The register was read from a matrix older than the tree: seventeen round 2 changes were archived after the 2026-09-07 reading. The [re-read of 2026-09-13](competitor-gap-re-read-2026-09-13.md) read all 36 rows the register marks stale against `828da9a69`: 12 closed, 22 stay, 4 stay narrowed, 1 new. The two umbrellas are what the register became: [dossiq#2643](https://github.com/ConductionNL/dossiq/pull/2643) opens 33 dossiq changes (27 S, 5 M, 1 L) and [openregister#3688](https://github.com/ConductionNL/openregister/pull/3688) the openregister half.

## The five to build first

The register's own order (`procest/_gaps/README.md`), which the dossiq umbrella adopts as the head of its build order.

1. **The working calendar, administered, with the Atw roll and recompute.** Rows 8.12, 8.11 and 8.17 on openregister, 8.16 on dossiq. Statutory correctness, not a feature: 30.4% of the terms dossiq stores land on a day the Atw moves. In the umbrella as `terms-on-the-engine-calendar` and `counting-mode-per-term`, consuming openregister's `working-calendar-admin`, `end-date-roll-on-the-calendar` and `calendar-time-zone`.
2. **Watchers on a case.** Row 13.18, openregister, S. Eight of eight non-Dutch systems in the register's reading pass it and dossiq is the only `no` in the ledger; batch 7 added Vikunja as a passer and Kanboard as a `no`. In the umbrella as `case-followers` over openregister's `object-watchers`.
3. **Internal versus public per timeline entry.** Row 6.15, openregister, S. Seven of eight pass, dossiq fails, and the citizen portal cannot show a case timeline until every entry says which side of the counter it belongs on. In the umbrella as `timeline-entries-default-internal`, after `citizen-status-labels`.
4. **Refusals carry a status.** Row Q10.14, dossiq, M. The fail-open shape gate 13 exists to catch, and no test asserts the status code beside the state. In the umbrella as `refusals-carry-a-status`, with the re-read's counts: 47 sites in 37 files, 93 of 106 controller suites asserting a status.
5. **Substituted work reaches My work.** Row 13.17, dossiq, S. The spec `handler-vervanging-waarneming` requires it and `fetchSubstitutedWork()` has no call site, so a case assigned to someone on leave is invisible until it breaches. In the umbrella as `substituted-work-reaches-my-work`, with humaniq's leave as the absence signal.

The ledger's own first pick, the schema-only registrations (Q11.31), is sixth: right, a privacy argument, and larger than the eight cells the matrix named once the re-read counted 27.

## Where to check a cell

Every system's code, documentation, API and issue tracker links, and the corpus directory that holds its column, are on [competitor sources](competitor-sources.md), generated from the ledger's sources register. The comparison pages that seeded the candidate set are listed there too. For the numbers on this page, the files to open are `procest/_round4/compare/eighteen-system-tally.md` (the ranking), `statutory-rows.md` (the 26), `open-core-batch7.md` and `open-core-batch8.md` (the shapes), `deadline-engines.md` (the 30.4%) and `procest/_gaps/README.md` (the counts by owner and the five), all in `ConductionNL/market-intelligence` on `development`.

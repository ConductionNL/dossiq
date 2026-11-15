# Competitor discovery sweep, September 2026

The [parity page](competitor-parity-2026-09.md) measures dossiq against a list we wrote. This page asks the opposite question: what do thirty-six other systems have that our list never thought to ask.

Thirty-six product surfaces were walked, item by item. Every item became a candidate or was dropped against a row the corpus already holds. What is left is, by construction, what nobody has specified yet: **636 consolidated candidates in 71 capability clusters**, of which dossiq fails 434.

Ruben took all twenty-two decisions on 2026-09-14, and six of the answers differ from the recommendation. The candidates were re-rated against them, and **618 of the 636 are now carried by an OpenSpec change** you can open in the repository that owns it, and 70 of the 71 clusters have one.

The record is `procest/_ledger/parity-ledger.html` (`data.discovery`, `data.decisions.openspec` and `data.register`) on `development` in `ConductionNL/market-intelligence`, at ledger v10.1. The long versions are `procest/_round4/discovery/found-and-lacking.md` (the two-page executive view), `build-plan.md` (the 71 clusters), `decisions.md` (the 22 decisions with what was taken), `casetype-configurability.md` (the depth study) and `candidates.md` with `candidates.json` (the 636). Which change carries which candidate is in `procest/_gaps/gap-register.md` and `.json`, fourth version at v4.1. Every corpus path on this page is relative to that repository.

## Method

A lane is one file per system. It walks the product surface item by item, one row each, and rates every item against dossiq's tree read on `development`. Thirty-six lanes were written: `procest/_round4/discovery/<system>.md`.

A candidate is not a matrix row. It is a question the sweep found worth asking. Decision D6 below sets the bar it has to clear to become one.

The consolidation runs by script, never by hand. `procest/_round4/tools/consolidate-discovery.py` drops a candidate against the corpus, merges the duplicates and issues the ids. `build-clusters.py` groups all 636 and refuses to print when a candidate sits in no cluster or in two.

### What the sweep cannot tell you

Six limits, each one measured rather than assumed.

**A single reader has a noise floor of about 11 percent.** Batch 11 graded its own first pass against the already published Forgejo column, and 11 percent of the cells moved. Every count here that rests on one reader carries that floor. A difference of one or two is not a difference.

**A documented system shows what its vendor documents.** 143 of the 636 candidates have no driven passer at all. Ten lanes were read and never installed: YouTrack, Easy Redmine, Jira Data Center, Jira Service Management, and the six Dutch scouted systems Mozard, Atabix, Decos JOIN, Rx.Mission, Visma Circle and PinkRoccade. A documented passer is an upper bound, never a measurement.

**xxllnc was read from source the corpus had never quoted.** The corpus records "39 attribute types" and names the file without listing them. `versioned_casetype.py` is 2,950 lines, EUPL-1.2 and public, so it was fetched over the GitLab API. Every xxllnc claim naming a class or an enum value is quoted from it. The vendor wiki refuses every API call and there is no public zaaktypebeheer manual, so no administrator documentation was available.

**Tuleap closed its source tree while the column was being read.** The readable tree the sources register names no longer exists. That column came out of `/usr/share/tuleap` inside the container instead. A link in a register is evidence on the day it was fetched, and not after.

**A plugin store has no bottom.** Where a capability ships as an extension, the lane rated the product as installed. GLPI's marketplace, the Znuny and OTOBO package managers and osTicket's plugins can each add more than the lane saw. Read a `no` on such a system as "not in the box", not as "cannot".

**A candidate is dropped only against the corpus.** The corpus was the 225 rows plus 48 pending proposals when the sweep ran. A match against dossiq's own extra 104 rows was written into the note and the candidate kept, because dropping against them would have put the capability in neither list. That happened once: eleven lane candidates were withdrawn that way and are back in this list. Decision D1 settled it by moving the 104 into the queue, so the corpus is now 225 rows plus 146 pending proposals and the two documents agree.

## The counts

Every figure comes from `python3 procest/_round4/tools/build-clusters.py summary`.

| step | count |
|---|---|
| lane files, one per system | 36 |
| surface items, summed over the 34 lanes that state one | **2,010** |
| candidate-bearing rows the lanes raised | 1,117 |
| distinct raw candidate ids | **1,008** |
| dropped against the corpus (119 rows, 17 pending) | 136 |
| merged away into 201 groups | 338 |
| **consolidated candidates** | **636** |
| **capability clusters** | **71** |
| **matrix holes** | **47** |
| **carried by an OpenSpec change** | **618** |

The Jira Data Center and Easy Redmine lanes state no surface-item count, because both were read from documents.

A matrix hole is a `must` for a gemeente, with two or more driven passers, and no row in the corpus to hold it. There are 47, and they are the sharpest evidence that the 225 rows ask the wrong questions in places.

### The 636, by area and by relevance

| area | candidates | | relevance | candidates |
|---|---|---|---|---|
| configuration | 106 | | **must** | **184** |
| access and privacy | 87 | | should | 305 |
| communication | 68 | | could | 147 |
| integrations | 50 | | not | 0 |
| intake | 48 | | | |
| case core | 46 | | **evidence** | |
| search | 44 | | two or more driven passers | 147 |
| documents | 43 | | exactly one driven passer | 346 |
| tasks and phases | 39 | | no driven passer at all | 143 |
| reporting | 32 | | | |
| decisions | 28 | | **marked a matrix hole** | 47 |
| deadlines | 24 | | revivals admitted by D5 | 5 |
| parties and contacts | 21 | | non-row findings | 19 |

**434 of the 636 rate dossiq `no`.** 176 are `partial` and 26 are `yes`.

The `not` bucket is empty because decision D17 emptied it. Twenty candidates had been rated `not` by the lane that found them, on a municipal argument. The product serves a broad market including MKB, so each of the twenty was re-rated `could` or higher with a one-clause reason, and sixteen carry `market: mkb` where the municipal reason still stands. Five parked candidates came back under D5, which revived all five rather than the three the file recommended.

## The ten strongest capabilities the competition has

Ordered by driven passers. A driven passer was installed, seeded with Dutch municipal cases and clicked. A documented passer is a vendor claim and an upper bound.

1. **The case and its term in the caseworker's own calendar.** Thirteen passers, twelve driven. The single strongest capability in the sweep, from GLPI's read-write CalDAV to OTOBO's read-only `text/calendar`. dossiq writes a hearing into a participant's calendar and no term reaches an agenda.
2. **A background job monitor with logs, failures and a run now button.** Nine driven. dossiq shows one poller's last run.
3. **A supported migration path out of a named competing product.** Eight driven. dossiq has none.
4. **A board of columns bound to the status field.** Eight driven, and dossiq passes it.
5. **A new domain from a template or a copy, carrying its configuration.** Seven driven.
6. **OAuth2 on the mail account.** Six driven. dossiq polls IMAP with a stored password, which Exchange Online no longer accepts.
7. **A health endpoint a monitor can poll without a session.** Five driven, and dossiq passes it.
8. **Replaying a failed delivery, or firing one by hand.** Five driven.
9. **S/MIME and PGP on case mail, with the keys administered in the product.** Five driven.
10. **A terms or privacy statement accepted and recorded.** Four driven.

## What dossiq lacks

The heaviest clusters, by how many members dossiq fails. The full list of 71 is in `build-plan.md`.

| cluster | dossiq `no` | of |
|---|---|---|
| Capabilities a gemeente does not need, re-read with an MKB lens | 19 | 19 |
| Security hardening of the instance | 18 | 18 |
| The administrator's own screens: jobs, logs, health and maintenance | 15 | 19 |
| Code lists, hierarchies and expiring values | 15 | 20 |
| The case page and the list as a place | 14 | 17 |
| Saved views, their tree and their labels | 13 | 19 |
| The timeline, the note and what can be searched in it | 13 | 15 |
| The party model beyond the requester | 13 | 16 |
| Who may do what: roles, grants and their provenance | 12 | 23 |
| The rules engine | 12 | 17 |
| Portal identity, registration and the organisation's cases | 10 | 12 |
| Publication, inspection and the national indexes | 10 | 15 |

The heaviest cluster is the one D17 created. Twenty candidates had been rated `not` by the lane that found them; re-read with an MKB lens they became a cluster of nineteen, and dossiq fails all nineteen. Four clusters have no `no` at all: the calendar, the phase vocabulary, access compiled into the query, and tenancy.

### The twenty-five loudest gaps

Sorted by relevance, then by driven passers. Every one is a `must` and a matrix hole, so each is a question a gemeente asks out loud that our own list cannot record. Ids are `candidates.md`.

| # | capability | driven | proving system | id |
|---|---|---|---|---|
| 1 | Every background run is listed with its outcome and can be started again | 9 | forgejo | C-configuration-57 |
| 2 | The product imports a running installation of the product it replaces | 8 | openproject | C-configuration-88 |
| 3 | The mailbox and the outbound mail authenticate with OAuth2 instead of a stored password | 6 | znuny | C-integrations-42 |
| 4 | A statement the administrator publishes is accepted before use, and who accepted which version is recorded | 4 | otobo | C-access-and-privacy-31 |
| 5 | A portal user sees the cases of the organisation they belong to, not only their own | 4 | zammad | C-access-and-privacy-23 |
| 6 | Ask the product who holds which right on a named object, and where each grant came from | 4 | forgejo | C-access-and-privacy-45 |
| 7 | Users and groups are taken from the directory and kept in step, so losing a group membership removes the access | 4 | glpi | C-access-and-privacy-82 |
| 8 | A public catalogue lists everything that can be requested online, and starts the right form | 3 | glpi | C-intake-15 |
| 9 | A deleted case is recoverable for a stated period and destroyed by a second act | 3 | vikunja | C-case-core-11 |
| 10 | A user chooses which kinds of event notify them, and over which channel | 3 | dimpact-zac | C-communication-29 |
| 11 | A field's values come from a query against an external source at the moment of use | 3 | zammad | C-integrations-9 |
| 12 | Administered links out of the case to an external system, built from the case's own field values | 3 | glpi | C-integrations-13 |
| 13 | A file is uploaded, its columns mapped onto fields, and records created in bulk | 2 | otobo | C-configuration-16 |
| 14 | Typed custom fields are added to parties and other records, not only to the case | 2 | osticket | C-configuration-105 |
| 15 | A person takes everything the product holds about them, in a machine readable form | 2 | request-tracker | C-access-and-privacy-19 |
| 16 | A second factor is required by policy, and the requirement is scoped to a chosen group | 2 | osticket | C-access-and-privacy-29 |
| 17 | An automatic acknowledgement goes to the sender the moment the case is created | 2 | request-tracker | C-intake-23 |
| 18 | A bulk action runs as a background job and reports its progress, naming what it skipped | 2 | dimpact-zac | C-case-core-1 |
| 19 | The OTRS action set on one case: hold, follow, merge, split, hand over, bounce, forward, park, log a call | 2 | znuny | C-case-core-40 |
| 20 | An attachment that arrived becomes a document on the case | 2 | dimpact-zac | C-documents-15 |
| 21 | The product creates a folder in the file store per unit or case domain and keeps its permissions in step | 2 | openproject | C-documents-36 |
| 22 | An indicator held on the party is shown on every case of theirs and changes how it is handled | 2 | dimpact-zac | C-parties-and-contacts-9 |
| 23 | A case message is forwarded to someone outside the system and recorded on the case | 2 | otobo | C-communication-5 |
| 24 | The exact outgoing message and its real recipients are readable, behind their own permission | 2 | request-tracker | C-communication-57 |
| 25 | A case type declares which kinds of party it accepts | 2 | xxllnc-zaken | C-configuration-4 |

Number 17 is the one to fix first. Awb 4:3a owes every electronic request a confirmation of receipt. That is a statutory duty, not a convenience, and dossiq returns zero hits for an intake ontvangstbevestiging.

## The case-type study: what a kenmerk can actually do

A sweep walks a surface, so a menu item called Kenmerken is one row. The depth study in `casetype-configurability.md` asks the next question instead: what can a kenmerk actually do. Fifty-four capabilities in four groups, rated over eleven systems and dossiq. It is the question a gemeente asks in the first demo.

A second reader rated ZAC, Valtimo and OpenCase again, independently and after the study was written, adding OpenZaak Catalogi as a fourth column and citing a file and usually a named field for every cell. Sixty of the 162 cells in those three columns changed, 56 up and 4 down. The more specific citation is the rating the table below carries.

Scoring a `yes` as one and a `partial` as a half:

| system | yes | partial | no | score |
|---|---|---|---|---|
| xxllnc Zaken | 36 | 14 | 4 | **43** |
| Valtimo / GZAC | 23 | 22 | 7 | **34** |
| Dimpact ZAC with OpenZaak Catalogi | 20 | 26 | 8 | **33** |
| Frappe Helpdesk | 20 | 17 | 16 | 28.5 |
| iTop | 19 | 18 | 15 | 28 |
| Tuleap CE | 20 | 16 | 18 | 28 |
| YouTrack | 20 | 15 | 18 | 27.5 |
| GLPI | 15 | 22 | 17 | 26 |
| Jira Data Center | 14 | 20 | 20 | 24 |
| Odoo Community | 10 | 25 | 17 | 22.5 |
| **dossiq** | **10** | **22** | **22** | **21** |
| OpenCase | 0 | 17 | 37 | 8.5 |

xxllnc is not slightly ahead here, it is twice ahead: 36 of the 54 against dossiq's 10. dossiq does not move on the recount and its position gets worse anyway. It is still eleventh of twelve, and it is now 13 points behind Valtimo where it was 2, and 12 behind ZAC. Those two are the systems a Dutch gemeente puts next to us in a tender.

Read the ZAC number with the split in mind. Twenty of its 33 points come from OpenZaak Catalogi rather than from ZAC. A municipality buying ZAC gets both, so the pair is the fair comparison, and it is the comparison dossiq faces, because dossiq is asked to replace the pair.

### The gap is concentrated, not spread

| group | what it rates | rows | xxllnc | dossiq |
|---|---|---|---|---|
| A | field types | 13 | 11 yes, 2 partial, 0 no | **0 yes**, 7 partial, 6 no |
| B | field behaviour | 16 | 10 yes, 4 partial, 2 no | 1 yes, 7 partial, 8 no |
| C | case-type structure | 16 | 9 yes, 6 partial, 1 no | 6 yes, 6 partial, 4 no |
| D | definition lifecycle | 9 | 6 yes, 2 partial, 1 no | 3 yes, 2 partial, 4 no |

dossiq scores **zero `yes` on the thirteen field types**. Not one of them is fully there. Group C is the half where we are competitive, because it is the half the ZGW model gave us: statustypen, resultaattypen, roltypen, besluittypen and deelzaaktypen. Group A is the half nobody gave us, and it is the half a demo is made of.

Thirteen rows are `yes` for xxllnc and `no` for dossiq. Eleven of the thirteen are field rows. That list is the tender risk.

### What xxllnc actually sells

xxllnc does not sell "slimme kenmerken". Their word is **slimme formulieren**. The product page says "Intelligente formulieren maken inclusief identificatie en authenticatie" and "Op basis van zero-coding kunnen beheerders formulieren ontwerpen, processen inrichten en sjablonen gebruiken" (`xxllnc.nl/applicaties/zaken`, fetched 2026-09-14). A grep of the whole corpus for "slimme" returns three hits, all of them "Slimme Formulieren" or "Slimme applicaties voor de overheid".

So when a gemeente says smart fields, they are naming two things xxllnc keeps apart: the **kenmerk**, a field with a type and a set of switches, and the **regel**, per-fase logic that reads kenmerken and writes them. Neither is smart on its own. The pair is what the buyer saw in the demo. Decisions D2 and D3 below say how we answer it: a small type plus a declared source key for the kenmerk, and the OpenRegister flow guards extended for the regel.

## Who builds it

The ownership rule decides the owner before any design does. dossiq reaches full comparability with the competition, and logic that belongs to another app is specified in that app and consumed by dossiq. That rule moves **536 of the 636 candidates out of dossiq**.

The carried column is the one to read. It counts the candidates whose cluster has an OpenSpec change open in that repository, from the gap register at v4.1.

| owner | candidates | clusters | carried |
|---|---|---|---|
| openregister | 280 | 31 | 280 |
| dossiq | 100 | 13 | 100 |
| integriq | 52 | 7 | 52 |
| nextcloud-vue | 47 | 3 | 47 |
| filinq | 34 | 4 | 34 |
| opencatalogi | 27 | 3 | 27 |
| portaliq | 23 | 3 | 23 |
| launchpad | 11 | 1 | 11 |
| decidiq | 11 | 1 | 11 |
| hermiq | 9 | 1 | 9 |
| humaniq | 9 | 1 | 9 |
| pipelinq | 9 | 1 | 9 |
| shillinq | 5 | 1 | 5 |
| nobody, recorded and not built | 19 | 1 | 1 |

Ninety-four changes carry the 618, across fourteen repositories: openregister 30, dossiq 21, integriq 9, filinq 6, hermiq 5, nextcloud-vue 5, humaniq 4, opencatalogi 3, pipelinq 3, portaliq 3, buildiq 2, decidiq 1, launchpad 1, shillinq 1. Read a change as `<repo>/openspec/changes/<slug>/`, so `openregister/object-watchers` opens at [that path in openregister](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/object-watchers).

### The fourteen clusters opened on 2026-09-14

The fourth version left 130 candidates in fifteen clusters with no change open. Fourteen of those clusters were opened that evening, and only cluster 59 is left. Six pull requests carry them, three of which had to create a parity umbrella in a repository that had none.

| cluster | owner | candidates | change now open |
|---|---|---:|---|
| What a new instance starts with | dossiq | 21 | `starter-content-and-templates` and `first-run-and-the-tour` |
| The term model: phases, chains, suspension | dossiq | 11 | `phase-terms-and-the-internal-target` |
| Dashboards, widgets and who may see them | launchpad | 11 | `dashboards-and-who-may-see-them`, with dossiq's `widget-roles-declared` |
| The decision as a walked process | decidiq | 11 | `the-decision-as-a-walked-process`, with dossiq's `decision-outcomes-on-the-case` |
| Lifecycle acts as separate, permissioned acts | dossiq | 10 | `lifecycle-acts-on-the-case` |
| Outbound sender identity and deliverability | integriq | 9 | `outbound-sender-identity-and-deliverability` |
| The task as a first-class record | dossiq | 8 | `task-as-a-first-class-record`, with buildiq's page layout |
| Intake routing, refusal and triage | dossiq | 7 | `intake-triage-and-refusal`, with hermiq's report collapsing |
| One personal queue fed by every mechanism | dossiq | 6 | `one-personal-queue` |
| Mail accounts, OAuth2 and alias domains | dossiq | 5 | `inbound-mail-filters` |
| Money and obligations: leges and payments | shillinq | 5 | `fees-payments-and-the-contract-register`, with dossiq's `fees-and-payments-on-the-case` |
| Handing a case to another team or handler | dossiq | 4 | `handing-a-case-over` |
| Talking to the citizen live, and to the assistant | dossiq | 4 | `live-conversation-on-the-case` |
| The satisfaction survey as its own object | openregister | 2 | `survey-object` |

The pull requests: [dossiq#2762](https://github.com/ConductionNL/dossiq/pull/2762) with nine changes, [decidiq#1316](https://github.com/ConductionNL/decidiq/pull/1316), [launchpad#637](https://github.com/ConductionNL/launchpad/pull/637) and [shillinq#1608](https://github.com/ConductionNL/shillinq/pull/1608), the last three each opening a parity umbrella the repository did not have, plus [integriq#2012](https://github.com/ConductionNL/integriq/pull/2012) and [openregister#3720](https://github.com/ConductionNL/openregister/pull/3720).

### The one cluster left

| cluster | owner | candidates | why |
|---|---|---:|---|
| Capabilities re-read with an MKB lens | nobody | 18 | D17 records the twenty rather than building them; humaniq took the one that is its own job |

Cluster 59 stays open on purpose. It is the cluster decision D17 created, and the decision was to record the capabilities rather than build them. It is not a backlog item.

## The three waves

**Wave 1, the platform under everything else.** Eight decisions land first, D1, D3, D8, D10, D12, D16, D19 and D22, because 28 clusters wait on one of them. Then openregister builds the rules engine, roles and their provenance, the destruction record over its own soft delete, bulk action as a job, per-user unread state and the code lists. The mail account is Nextcloud Mail's under D12, so dossiq reads the account Nextcloud already holds and builds sender authentication on its own intake path. dossiq widens `propertyDefinition` and the extends-form map, which is the cheapest work in the file: ten capability rows move on one JSON object. The ten Nextcloud platform integration points ship in parallel as one programme, blocking nothing.

**Wave 2, what a municipality sees.** portaliq builds the intake form as an object and portal identity. openregister builds the archiving process under D7, beside the retention it already holds, and filinq keeps the document half and inbound document classification. opencatalogi builds publication and the national indexes. openregister builds the party model and search quality. nextcloud-vue builds saved views and the working list. dossiq builds the ontvangstbevestiging, the term model per fase and intake routing. Every one of these consumes something wave 1 shipped.

**Wave 3, the long ones.** humaniq takes agenda, rostering and time. pipelinq takes the project above the cases. hermiq takes the assistant. integriq takes the statutory gateways. buildiq takes the layout per case type. openregister takes tenancy. All of them are L, and none of them blocks a tender answer.

## The twenty-two decisions, as taken

**Ruben took all twenty-two on 2026-09-14.** Each one blocked at least one of the 71 clusters, and eight blocked more than one. Six answers differ from the recommendation the file made, and those six are marked. The options, what each one costs, and the reasoning are in `procest/_round4/discovery/decisions.md`; the same rows are in the ledger under `data.decisions.openspec`.

1. **D1, the 104 rows dossiq publishes and the corpus never issued.** File all 104 as pending proposals and renumber centrally. 91 moved, 13 kept their id, and six were absorbed into a proposal that already asked their question, so the queue is 146. `src/data/capabilityComparison.json` is re-issued from the corpus by `scripts/sync-capability-comparison.mjs`, and `tests/vitest/capabilityComparison.spec.js` fails when the id set drifts again.
2. **D2, is a registry-backed field a type, or a small type plus a declared source.** One small type plus a declared source key, resolved through integriq. openregister owns the key, integriq owns the adapters, dossiq owns only the declaration. xxllnc's 39 attribute types are a symptom, not a feature.
3. **D3, the rules engine, and where a computed field lives.** Extend the OpenRegister flow guards, with the JSON AST for computed values. Two things stay missing and we say so: a rule whose condition reads a case-type property, and a rule action that makes a field required. xxllnc cannot do the second either.
4. **D4, does a fase carry its own term, documents and notifications.** Fase-level semantics. `statusType` gains `termInDays`, a template list and a required-document list. It completes the ZGW set rather than starting a new one.
5. **D5, which of the 41 revivals to re-argue. Differs from the recommendation.** Revive all five, not three. The calendar client joins the calendar cluster, the board and the Gantt join saved views, the budget ceiling joins the project above the cases, and the satisfaction survey gets a cluster of its own, because D5 revives it as an object rather than a rating.
6. **D6, the promotion bar for a candidate. Differs from the recommendation.** Relevance-led, not two driven passers. Every `must` enters whatever the passer count, and every statutory candidate enters with it. A row can now enter that nobody passes, so the driven passer count is printed beside every row or a promotion reads as a pass.
7. **D7, is archiving a process with sign-off, or an export. Differs from the recommendation.** A process, in openregister, not filinq. The vernietigingslijst, the reviewer per item, the approval and the act live beside the retention openregister already holds. filinq keeps the document half and dossiq keeps declaring the resultaattype.
8. **D8, portal identity: an account, a number plus an e-mail, or both.** Both, with the choice declared on the case type. Build the number plus e-mail first because it is small and unblocks eight candidates, then the account through portaliq.
9. **D9, Deck's ten platform integration points.** Ship them as one programme. It is the highest ratio of capability to design work in the file, and nothing blocks it.
10. **D10, two-step delete, or the delete guard already specified. Differs from the recommendation.** openregister's existing soft delete, with no new recycle state. There is nothing new to build: the soft delete is the recovery window, and what it needs is a stated period and a recorded destruction. The guard stays in dossiq, so `case-delete-guard` merges as it is and widens afterwards.
11. **D11, the calendar client.** A CalDAV provider in openregister, delivering the read-only feed first. A feed that recomputes is correct by construction; a written event needs the calendar-change recomputation still being specified.
12. **D12, the mail transport, and whose it is. Differs from the recommendation.** Nextcloud Mail owns the mail account and its OAuth2, not integriq. dossiq reads the account Nextcloud already holds, and sender authentication stays dossiq's. The cluster's owner moves from integriq to dossiq, and the unauthenticated sender stays a defect on dossiq's own intake path. Say out loud that a bezwaar can today be filed on somebody else's case.
13. **D13, where the assistant lives and what it may read.** hermiq owns the assistant, with anonymisation before the model reads as a requirement on it. The redaction client is already in filinq and the AI run record belongs in openregister's audit trail.
14. **D14, the priority model.** Store impact and urgency, derive the priority, and let a rule raise it as the term approaches. The corpus had no row for priority at all, which is a matrix hole D6 now closes.
15. **D15, configuration as code.** Stage inside one instance first, move packages between two when the export works. The export stub has to be fixed before either, because an export that returns an empty package behind a 200 makes the first impossible and the third a lie.
16. **D16, is the citizen's form the same definition as the internal one.** The flag lives on the field, the form owns order and channel. They are not alternatives. Decide it before the layout work starts, or the flag lands in the wrong place twice.
17. **D17, the 20 candidates in the `not` bucket. Differs from the recommendation.** Re-rate all twenty with an MKB lens, rather than recording them and never building them. The product serves a broad market including MKB, so a `not` written for a gemeente does not disqualify a capability. Each is re-rated `could` or higher with a one-clause reason, and sixteen carry `market: mkb` where the municipal reason still stands.
18. **D18, what a running case does when its type gets a new version.** Rebind on request, one case at a time with a record, and say "we refuse" in the first meeting. Refusing is defensible and it has to be said before the demo, not after the purchase. Bulk migration is wave 3.
19. **D19, who owns rostering, availability and the agenda.** humaniq owns rostering, availability and working hours. openregister owns the working calendar the term reads. dossiq reads both and owns neither.
20. **D20, the nine parked build branches.** Merge all nine, then extend. A merged change is evidence and an open branch is not.
21. **D21, the 143 candidates with no driven passer.** Admit them as documented, and never count them in a driven tally. The Dutch six are the systems a gemeente actually compares us to, so a corpus that refuses to record what they claim cannot answer the question the buyer asks.
22. **D22, who owns access compiled into the query.** openregister, and it is not close. Access is a property of the object, the object lives in openregister, and a filter that runs after the query has already leaked the count. `permission-provenance-and-deny` names it explicitly, because "compiled into the query" and "checked on the result" are the same sentence in English and different products in practice.

Eight of the 22 blocked more than one cluster: D5, D22 and D3 with four clusters each, then D10, D12, D16, D8 and D14. D1 names no cluster and is cited by 21 of them. D6 touched all 71.

## What is built so far

Twelve pull requests from this programme are merged on `development`.

| repository | merged |
|---|---|
| dossiq | [#2719](https://github.com/ConductionNL/dossiq/pull/2719), [#2721](https://github.com/ConductionNL/dossiq/pull/2721), [#2723](https://github.com/ConductionNL/dossiq/pull/2723), [#2729](https://github.com/ConductionNL/dossiq/pull/2729), [#2732](https://github.com/ConductionNL/dossiq/pull/2732), [#2744](https://github.com/ConductionNL/dossiq/pull/2744), [#2746](https://github.com/ConductionNL/dossiq/pull/2746), [#2748](https://github.com/ConductionNL/dossiq/pull/2748) |
| openregister | [#3707](https://github.com/ConductionNL/openregister/pull/3707), [#3711](https://github.com/ConductionNL/openregister/pull/3711), [#3714](https://github.com/ConductionNL/openregister/pull/3714) |
| portaliq | [#551](https://github.com/ConductionNL/portaliq/pull/551) |

Thirteen more build lanes are in flight and none of them is merged. A change directory named in the register and absent from this table is specified and not yet shipped.

## The one number to remember

dossiq is **71 of 180** on the domain-neutral rows, seventh of twenty-six driven columns. OTOBO and Odoo lead the non-Dutch set at 78. xxllnc Zaken is 125.

The gap to the top of the non-Dutch set is seven rows. The gap to xxllnc is fifty-four, and most of it sits in one place: what a kenmerk can be, and what a regel can do to it.

## Where to check a number

Every figure on this page is in `ConductionNL/market-intelligence` on `development`:

- `procest/_ledger/parity-ledger.html`, sections `data.discovery` and `data.decisions.openspec`, the record
- `procest/_round4/discovery/found-and-lacking.md`, the two-page executive view
- `procest/_round4/discovery/candidates.md` and `candidates.json`, the 636 consolidated candidates
- `procest/_round4/discovery/build-plan.md`, the 71 clusters with owner, size, dependencies and mechanism
- `procest/_gaps/gap-register.md` and `gap-register.json`, fourth version, which change carries which candidate
- `procest/_round4/discovery/decisions.md`, the 22 decisions in full, each with the answer taken on 2026-09-14
- `procest/_round4/discovery/casetype-configurability.md`, the depth study and its second read
- `procest/_round4/discovery/<system>.md`, the thirty-six lanes
- `procest/_round4/tools/consolidate-discovery.py` and `build-clusters.py`, which produce every count above

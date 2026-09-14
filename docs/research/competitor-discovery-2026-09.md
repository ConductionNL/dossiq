# Competitor discovery sweep, September 2026

The [parity page](competitor-parity-2026-09.md) measures dossiq against a list we wrote. This page asks the opposite question: what do thirty-six other systems have that our list never thought to ask.

Thirty-six product surfaces were walked, item by item. Every item became a candidate or was dropped against a row the corpus already holds. What is left is, by construction, what nobody has specified yet: **631 consolidated candidates in 70 capability clusters**, of which dossiq fails 431.

The record is `procest/_ledger/parity-ledger.html` (`data.discovery` and `data.decisions.openspec`) on `development` in `ConductionNL/market-intelligence`. The long versions are `procest/_round4/discovery/found-and-lacking.md` (the two-page executive view), `build-plan.md` (the 70 clusters), `decisions.md` (the 22 decisions), `casetype-configurability.md` (the depth study) and `candidates.md` with `candidates.json` (the 631). Every corpus path on this page is relative to that repository.

## Method

A lane is one file per system. It walks the product surface item by item, one row each, and rates every item against dossiq's tree read on `development`. Thirty-six lanes were written: `procest/_round4/discovery/<system>.md`.

A candidate is not a matrix row. It is a question the sweep found worth asking, and decision D6 below decides which of them ever get promoted.

The consolidation runs by script, never by hand. `procest/_round4/tools/consolidate-discovery.py` drops a candidate against the corpus, merges the duplicates and issues the ids. `build-clusters.py` groups all 631 and refuses to print when a candidate sits in no cluster or in two.

### What the sweep cannot tell you

Six limits, each one measured rather than assumed.

**A single reader has a noise floor of about 11 percent.** Batch 11 graded its own first pass against the already published Forgejo column, and 11 percent of the cells moved. Every count here that rests on one reader carries that floor. A difference of one or two is not a difference.

**A documented system shows what its vendor documents.** 143 of the 631 candidates have no driven passer at all. Ten lanes were read and never installed: YouTrack, Easy Redmine, Jira Data Center, Jira Service Management, and the six Dutch scouted systems Mozard, Atabix, Decos JOIN, Rx.Mission, Visma Circle and PinkRoccade. A documented passer is an upper bound, never a measurement.

**xxllnc was read from source the corpus had never quoted.** The corpus records "39 attribute types" and names the file without listing them. `versioned_casetype.py` is 2,950 lines, EUPL-1.2 and public, so it was fetched over the GitLab API. Every xxllnc claim naming a class or an enum value is quoted from it. The vendor wiki refuses every API call and there is no public zaaktypebeheer manual, so no administrator documentation was available.

**Tuleap closed its source tree while the column was being read.** The readable tree the sources register names no longer exists. That column came out of `/usr/share/tuleap` inside the container instead. A link in a register is evidence on the day it was fetched, and not after.

**A plugin store has no bottom.** Where a capability ships as an extension, the lane rated the product as installed. GLPI's marketplace, the Znuny and OTOBO package managers and osTicket's plugins can each add more than the lane saw. Read a `no` on such a system as "not in the box", not as "cannot".

**A candidate is dropped only against the corpus.** The corpus is the 225 rows plus the 48 pending proposals. A match against dossiq's own extra 104 rows is written into the note and the candidate is kept, because dropping against them would put the capability in neither list. That has already happened once: eleven lane candidates were withdrawn that way and are back in this list. Decision D1 settles it.

## The counts

Every figure comes from `python3 procest/_round4/tools/build-clusters.py summary`.

| step | count |
|---|---|
| lane files, one per system | 36 |
| surface items, summed over the 34 lanes that state one | **2,010** |
| candidate-bearing rows the lanes raised | 1,117 |
| distinct raw candidate ids | **1,008** |
| dropped against the corpus (119 rows, 17 pending) | 136 |
| merged away into 201 groups | 343 |
| **consolidated candidates** | **631** |
| **capability clusters** | **70** |
| **matrix holes** | **47** |

The Jira Data Center and Easy Redmine lanes state no surface-item count, because both were read from documents.

A matrix hole is a `must` for a gemeente, with two or more driven passers, and no row in the corpus to hold it. There are 47, and they are the sharpest evidence that the 225 rows ask the wrong questions in places.

### The 631, by area and by relevance

| area | candidates | | relevance | candidates |
|---|---|---|---|---|
| configuration | 106 | | **must** | **183** |
| access and privacy | 87 | | should | 299 |
| communication | 68 | | could | 129 |
| integrations | 50 | | not | 20 |
| intake | 48 | | | |
| case core | 46 | | **evidence** | |
| search | 44 | | two or more driven passers | 143 |
| documents | 43 | | exactly one driven passer | 345 |
| tasks and phases | 37 | | no driven passer at all | 143 |
| reporting | 30 | | | |
| decisions | 28 | | **marked a matrix hole** | 47 |
| deadlines | 23 | | revivals | 41 |
| parties and contacts | 21 | | non-row findings | 19 |

**431 of the 631 rate dossiq `no`.** 175 are `partial` and 25 are `yes`.

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

The heaviest clusters, by how many members dossiq fails. The full list of 70 is in `build-plan.md`.

| cluster | dossiq `no` | of |
|---|---|---|
| Security hardening of the instance | 18 | 18 |
| The administrator's own screens: jobs, logs, health, maintenance | 15 | 19 |
| Code lists, hierarchies and expiring values | 15 | 20 |
| The case page and the list as a place | 14 | 17 |
| The timeline, the note and what can be searched in it | 13 | 15 |
| The party model beyond the requester | 13 | 16 |
| Who may do what: roles, grants and their provenance | 12 | 23 |
| The rules engine | 12 | 17 |
| Saved views, their tree and their labels | 11 | 16 |
| Portal identity and the organisation's cases | 10 | 12 |
| Publication, inspection and the national indexes | 10 | 15 |

Four clusters have no `no` at all: the calendar, the phase vocabulary, access compiled into the query, and tenancy. Twenty candidates sit in the `not` bucket and will not be built.

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

So when a gemeente says smart fields, they are naming two things xxllnc keeps apart: the **kenmerk**, a field with a type and a set of switches, and the **regel**, per-fase logic that reads kenmerken and writes them. Neither is smart on its own. The pair is what the buyer saw in the demo, and decisions D2 and D3 below decide how we answer it.

## Who builds it

The ownership rule decides the owner before any design does. dossiq reaches full comparability with the competition, and logic that belongs to another app is specified in that app and consumed by dossiq. That rule moves **536 of the 631 candidates out of dossiq**.

| owner | candidates | clusters |
|---|---|---|
| openregister | 263 | 29 |
| dossiq | 95 | 12 |
| integriq | 57 | 8 |
| filinq | 50 | 5 |
| nextcloud-vue | 44 | 3 |
| opencatalogi | 27 | 3 |
| portaliq | 23 | 3 |
| launchpad | 11 | 1 |
| decidiq | 11 | 1 |
| hermiq | 9 | 1 |
| humaniq | 9 | 1 |
| pipelinq | 7 | 1 |
| shillinq | 5 | 1 |
| nobody, recorded and not built | 20 | 1 |

## The three waves

**Wave 1, the platform under everything else.** Eight decisions land first, D1, D3, D8, D10, D12, D16, D19 and D22, because 28 clusters wait on one of them. Then openregister builds the rules engine, roles and their provenance, delete and destroy, bulk action as a job, per-user unread state and the code lists. integriq builds the mail account with OAuth2. dossiq widens `propertyDefinition` and the extends-form map, which is the cheapest work in the file: ten capability rows move on one JSON object. The ten Nextcloud platform integration points ship in parallel as one programme, blocking nothing.

**Wave 2, what a municipality sees.** portaliq builds the intake form as an object and portal identity. filinq builds the archiving process and inbound document classification. opencatalogi builds publication and the national indexes. openregister builds the party model and search quality. nextcloud-vue builds saved views and the working list. dossiq builds the ontvangstbevestiging, the term model per fase and intake routing. Every one of these consumes something wave 1 shipped.

**Wave 3, the long ones.** humaniq takes agenda, rostering and time. pipelinq takes the project above the cases. hermiq takes the assistant. integriq takes the statutory gateways. buildiq takes the layout per case type. openregister takes tenancy. All of them are L, and none of them blocks a tender answer.

## The twenty-two decisions

Nothing here is decided. Each decision blocks at least one of the 70 clusters, and eight block more than one. The full version, with the options and what each one costs, is `procest/_round4/discovery/decisions.md`; the same rows are in the ledger under `data.decisions.openspec`.

1. **D1, the 104 rows dossiq publishes and the corpus has never issued.** dossiq's customer page carries 329 rows, the ledger 225 plus 48 pending. **Recommended: file all 104 as pending proposals and renumber centrally**, fix the 39 citations the discovery lanes already made, and add a test that reads the ledger's row ids and fails when dossiq's JSON differs. One of the three hardest, because it decides which of two documents is the record, and one of them is already in front of customers.
2. **D2, is a registry-backed field a type, or a small type plus a declared source.** **Recommended: one small type plus a declared source key**, resolved through integriq. openregister owns the key, integriq owns the adapters, dossiq owns only the declaration. xxllnc's 39 attribute types are a symptom, not a feature, and that is the argument to make in a tender.
3. **D3, the rules engine, and where a computed field lives.** **Recommended: extend the OpenRegister flow guards, and use the JSON AST for computed values.** All three proposals are written and none is built. Say out loud what is still missing afterwards: a rule whose condition reads a case-type property, and a rule action that makes a field required. xxllnc cannot do the second either. One of the three hardest.
4. **D4, does a fase carry its own term, documents and notifications.** **Recommended: fase-level semantics.** `statusType` gains `termInDays`, a template list and a required-document list. It completes the ZGW set rather than starting a new one.
5. **D5, which of the 41 revivals to re-argue.** **Recommended: revive three, leave two.** The calendar client revives outright. The board revives, because dossiq ships one now. The satisfaction survey revives as its own object. The Gantt stays parked, and so does the budget ceiling.
6. **D6, the promotion bar for a candidate.** **Recommended: keep two driven passers, and admit a candidate on one driven passer when it is statutory.** Awb 4:3a, the Wmebv, the Wet elektronisch publiceren and the archiving duties are obligations, not comparisons. This one touches all 70 clusters, because it decides how many of the 631 ever reach the ledger.
7. **D7, is archiving a process with sign-off, or an export.** **Recommended: a process, in filinq, with the record kept in openregister.** Today the only hit in dossiq's tree for `vernietigingslijst` is dossiq's own claim about itself in `src/data/capabilityComparison.json`.
8. **D8, portal identity: an account, a number plus an e-mail, or both.** **Recommended: both, with the choice declared on the case type.** Build the number plus e-mail first because it is small and unblocks eight candidates, then the account through portaliq. One of the three hardest, because a security officer reads it first.
9. **D9, Deck's ten platform integration points as a programme.** **Recommended: ship them as one programme.** It is the highest ratio of capability to design work in the file, and nothing blocks it.
10. **D10, two-step delete, or the delete guard already specified.** **Recommended: both, kept separate.** The recycle state goes in openregister beside `object-archive-state`, the guard stays in dossiq. Deletion on loss of lawful purpose is not archive retention.
11. **D11, the calendar client: CalDAV out of openregister, or Nextcloud Calendar.** **Recommended: openregister, delivering the read-only feed first.** A feed that recomputes is correct by construction. A written event needs the calendar-change recomputation that is still being specified.
12. **D12, the mail transport, and whose it is.** **Recommended: integriq holds the account, the token and the alias domains, with Nextcloud Mail as the fallback transport.** dossiq holds the filter pipeline. Say out loud that the sender is authenticated nowhere today, so a bezwaar can be filed on somebody else's case.
13. **D13, where the assistant lives and what it may read.** **Recommended: hermiq owns the assistant, with anonymisation before the model reads as a requirement on it.** The redaction client is already in filinq and the AI run record belongs in openregister's audit trail. dossiq declares which tools exist and who may call them.
14. **D14, the priority model.** **Recommended: store impact and urgency, derive the priority, and let a rule raise it as the term approaches.** The corpus has no row for priority at all, which is a matrix hole D6 should close.
15. **D15, configuration as code: environments, review and rollback.** **Recommended: stage inside one instance first, move packages between two when the export works.** The export stub has to be fixed before either, because an export that returns an empty package behind a 200 makes the first impossible and the third a lie.
16. **D16, is the citizen's form the same definition as the internal one.** **Recommended: both, and they are not alternatives.** The field carries whether the citizen may see and change it, because that is a property of the field. The form carries which fields appear in which order for which channel. Decide it before the layout work starts, or the flag lands in the wrong place twice.
17. **D17, the 20 candidates in the `not` bucket.** **Recommended: keep them, with one sentence each in the ledger's rejected list** so the reason survives the person who wrote it. Three are worth a second look: reactions on a case, a threaded board per case domain, and a leave request against a department schedule.
18. **D18, what a running case does when its type gets a new version.** **Recommended: build `case-type-rebind` so the answer is "one at a time, with a record", and say "we refuse" in the first meeting.** Refusing is defensible and it has to be said before the demo, not after the purchase. Bulk migration is wave 3.
19. **D19, who owns rostering, availability and the agenda.** **Recommended: humaniq owns rostering, availability and working hours; openregister owns the working calendar the term reads; dossiq reads both and owns neither.** Settle it before the term work starts.
20. **D20, the nine parked build branches.** **Recommended: merge all nine, then extend.** A merged change is evidence and an open branch is not. `case-delete-guard` is the one to watch, because D10 may widen it, and widening a merged change is cheaper than merging a widened one.
21. **D21, the 143 candidates with no driven passer.** **Recommended: admit them as rows, and never count them in a driven tally.** The Dutch six are the systems a gemeente actually compares us to, so a corpus that refuses to record what they claim cannot answer the question the buyer asks. Print the grade wherever the number appears.
22. **D22, who owns access compiled into the query.** **Recommended: openregister, and it is not close.** Access is a property of the object, the object lives in openregister, and a filter that runs after the query has already leaked the count. Name it explicitly in `permission-provenance-and-deny`, because "compiled into the query" and "checked on the result" are the same sentence in English and different products in practice.

Eight of the 22 block more than one cluster: D5, D22 and D3 with four clusters each, then D10, D12, D16, D8 and D14. D1 names no cluster and is cited by 21 of them. D6 touches all 70.

## The one number to remember

dossiq is **71 of 180** on the domain-neutral rows, seventh of twenty-six driven columns. OTOBO and Odoo lead the non-Dutch set at 78. xxllnc Zaken is 125.

The gap to the top of the non-Dutch set is seven rows. The gap to xxllnc is fifty-four, and most of it sits in one place: what a kenmerk can be, and what a regel can do to it.

## Where to check a number

Every figure on this page is in `ConductionNL/market-intelligence` on `development`:

- `procest/_ledger/parity-ledger.html`, sections `data.discovery` and `data.decisions.openspec`, the record
- `procest/_round4/discovery/found-and-lacking.md`, the two-page executive view
- `procest/_round4/discovery/candidates.md` and `candidates.json`, the 631 consolidated candidates
- `procest/_round4/discovery/build-plan.md`, the 70 clusters with owner, size, dependencies and mechanism
- `procest/_round4/discovery/decisions.md`, the 22 decisions in full
- `procest/_round4/discovery/casetype-configurability.md`, the depth study and its second read
- `procest/_round4/discovery/<system>.md`, the thirty-six lanes
- `procest/_round4/tools/consolidate-discovery.py` and `build-clusters.py`, which produce every count above

---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

The dossiq half of the OpenSpec phase of the competitor parity programme.
Source of record: the gap register at `procest/_gaps/` in
ConductionNL/market-intelligence (`README.md`, `gap-register.md`,
`gap-register.json`, `ownership-rules.md`, written 2026-09-13): 258 rows
read, 163 gaps, 38 owned by dossiq. Ruben's rule, in the ownership rules:
dossiq reaches 100% comparability with the competition, and logic that
belongs to another app is specified in that app; dossiq consumes it. This
umbrella indexes every dossiq change opened from the register, the re-read
that preceded them, the build order, and the halves dossiq carries without
a change of its own. The openregister lane and the small-owner lane write
their apps' changes; the slugs below name them.

## The re-read

`docs/research/competitor-gap-re-read-2026-09-13.md` re-reads every row
the register marks re-rate against dossiq `development` at `828da9a69`.
The register counts 34; its data carries the word on 36 rows, and all 36
were read, plus four rows the same archived changes moved (2.3, 3.8,
Q10.14, Q11.31).

| consequence | rows |
|---|---|
| gap closed | 12 |
| gap stays | 22 |
| gap stays, narrowed | 4 |
| new gap | 1 |

Closed: 2.7, 2.12, 2.15, 3.3, 3.10, 5.1, 9.10, 11.21, 11.22, 12.15, 12.17,
13.11. Narrowed: 2.3 (M to S), 2.5, 3.5, Q10.12. New: 2.20, opened as
`live-updates-on-the-case-page`.

## The changes

Thirty-three changes. Size S is a placement, a declaration or one action;
M a handful of tasks; L a new mechanism. "Consumes from" names the app
and its spec or change; "to be specified" means the register's slug has
no artefact on that app's `development` yet.

### dossiq's own rows

| change | rows | size | consumes from |
|---|---|---|---|
| `planned-case-series` | 1.8 | S | openregister flow scheduled trigger (shipped) |
| `case-type-version-chain` | 2.3 | S | nothing new |
| `case-claim-action` | 2.4 | S | nothing new |
| `case-type-rebind` | 2.13 | L | openregister `migrate-run-between-versions` (to be specified, row 3.16) |
| `case-delete-guard` | 2.17 | S | openregister `ObjectDeletingEvent`, legal hold (shipped) |
| `task-defaults-to-case-handler` | 3.8 | S | openregister engine task (shipped) |
| `gemachtigde-role-on-every-case-type` | 5.8 | S | nothing new |
| `document-correspondents` | 5.12 | S | dossiq `documents-on-the-case` (open); nextcloud-vue `files-browser-columns` (to be specified, row 4.8); integriq mail intake (shipped) |
| `case-reminder-as-task` | 8.4 | S | openregister engine task (shipped); dossiq `remove-casetask` (open) |
| `task-search-fields` | 9.11 | S | openregister flow-tasks inbox filters (shipped); dossiq `remove-casetask` (open) |
| `data-model-link` | Q11.32, 11.17 | S | openregister schema admin pages (shipped) |
| `attribute-catalogue-folders` | 11.23 | S | filinq template library category (open question) |
| `pause-reason-with-chasing` | 2.25 | M | openregister `flow-business-timers` escalation rules, messaging leaf (shipped) |
| `substituted-work-reaches-my-work` | 13.17 | S | humaniq `leave-management` (spec, done) |
| `counting-mode-per-term` | Q8.16 | S | openregister `flow-business-timers` (shipped); `working-calendar-admin` (to be specified, row 8.12) |
| `dependent-term-follows-predecessor` | Q3.21 | M | openregister engine task (shipped); `relation-types-with-inverses` (to be specified, row 2.26) |
| `declared-prerequisites` | Q12.25 | S | apphost `GenericAdminSettings` (shipped) |
| `refusals-carry-a-status` | Q10.14 | M | nothing new |
| `no-schema-without-a-surface` | Q11.31 | M | nothing new |
| `dwell-time-on-the-working-calendar` | Q10.15 | S | openregister `WorkingCalendarService`, `SlaCalculator` (shipped) |
| `citizen-status-labels` | Q6.19 | S | portaliq contribution contract (shipped) |

### dossiq's half of another owner's row, where the register marks the slug `(dossiq)`

| change | rows | size | consumes from |
|---|---|---|---|
| `admin-inspect-entry` | 2.22 | S | nextcloud-vue `CnObjectMetadataModal`, openregister runs page (shipped) |
| `scan-verdict-on-the-row` | 4.19 | S | nextcloud `files_antivirus` (platform); nextcloud-vue `files-browser-columns` (to be specified) |
| `sensitive-fields-declared` | 5.6 | S | openregister `row-field-level-security` (spec) |
| `code-lists-from-concepts` | 11.10 | S | openregister `skos-concept-registers` (spec) |
| `field-rules-declared` | 13.8 | S | openregister `row-field-level-security` (spec); `field-rules-by-state` (to be specified, row 11.25) |
| `case-merge` | 2.23 | M | openregister `mdm-merge` (spec); dossiq `email-case-matching` (open) |
| `duplicate-warning-at-intake` | 2.24 | S | openregister `duplicate-detection` (spec); portaliq contribution (shipped) |
| `edit-lock-on-the-case-page` | 2.27 | S | openregister `run-scoped-object-locking` (change) |
| `live-updates-on-the-case-page` | 2.20 | S | openregister notify_push, nextcloud-vue `liveUpdatesPlugin` (shipped) |

### dossiq's half of the register's first three picks, opened here as consumer changes

| change | rows | size | consumes from |
|---|---|---|---|
| `terms-on-the-engine-calendar` | 8.11, 8.12, Q8.19, Q8.17 (history only) | S | openregister `working-calendar-admin`, `end-date-roll-on-the-calendar`, `calendar-time-zone`, `calendar-change-recomputes-timers` (all to be specified) |
| `case-followers` | 13.18 | S | openregister `object-watchers` (to be specified) |
| `timeline-entries-default-internal` | 6.15 | S | openregister `timeline-entry-visibility` (to be specified); portaliq contribution (shipped) |

Sizes: 27 S, 5 M, 1 L. With `one-date-write-path`, 27 S, 6 M, 1 L.

### The eight gaps the regenerated register still lists, opened 2026-09-13

The register was rebuilt the same evening this umbrella was written
(market-intelligence #123). It ends on eight gaps with no change, across
four owners. Five changes are dossiq's, two of its own rows and three
consumer halves. The owner changes are opened in the same sweep:
openregister `object-archive-state` and `rbac-inherits-to-children`,
integriq `signed-outbound-webhooks` and `objecten-api-facade`, portaliq
`embedded-intake-form`, nextcloud-vue `saved-view-as-a-place`.

| change | rows | size | consumes from |
|---|---|---|---|
| `status-capacity-limit` | Q3.22 | S | nothing new |
| `intake-says-when-the-term-starts` | Q8.21 | S | openregister `working-calendar-admin` (to be specified), dossiq `terms-on-the-engine-calendar` (open) |
| `archived-cases-leave-the-lenses` | Q2.33 | S | openregister `object-archive-state` (open) |
| `deelzaken-inherit-the-parent-grants` | Q13.23 | S | openregister `rbac-inherits-to-children` (open) |
| `cases-views-are-places` | Q9.16 | S | nextcloud-vue `saved-view-as-a-place` (open); openregister `saved-search-views` (shipped) |

### The batch 11 row that indicts us, opened 2026-09-13

Batch 11 of round 4 (`procest/_round4/compare/proposed-rows-batch11.md`)
proposed row 8.22 and rated dossiq `no` from the source. It is not a
competitive row, it is an audit instruction: enumerate the writers of
every date field, not the readers. The audit was run against
`development` at `d28e11aa` and found nine write paths, nine private
normalisers and an administered time zone no write path reads.

| change | rows | size | consumes from |
|---|---|---|---|
| `one-date-write-path` | 8.22 (proposed) | M | openregister `calendar-time-zone` (openregister#3688); dossiq `terms-on-the-engine-calendar` (open) |

### The batch 12 row the calendar does not reach, opened 2026-09-14

Batch 12 of round 4 (`procest/_round4/compare/proposed-rows-batch12.md`)
proposed row 8.23 and rated dossiq `partial`. The row does not ask
whether the working calendar exists, it asks whether the engine reads it.
Read at `9c478d810`: 37 files under `lib/` do date arithmetic, five reach
`WorkingDayCalculator`, and three of the thirty-two that do not are term
paths by name. Gap register v3 (market-intelligence #128) carries the
row.

| change | rows | size | consumes from |
|---|---|---|---|
| `every-term-on-the-engine-calendar` | 8.23 (proposed) | M | dossiq `terms-on-the-engine-calendar` (open, the roll rule); openregister `working-calendar-admin` and `end-date-roll-on-the-calendar` (to be built) |

Neither open change carries it: `terms-on-the-engine-calendar` applies
the roll to two call sites, and `termijnbewaking-op-engine-timers` names
the three files to move their clock while freezing
`DeadlinePauseService`'s arithmetic and leaving `NoticeOfDefaultService`
unchanged.

Two of the eight need no dossiq change. Q6.20 signed outbound webhooks:
the register's half reads "nothing beyond finishing
dossiq-delivers-nothing, which retires WebhookHandler", and integriq's
`webhook-signing` already specifies and ships the signing, so only its
default was missing. Q1.16 the embedded intake form: the half reads
"nothing beyond the intake binding it already has".

**12.3 moved owner again.** The table below still reads openregister
`objecten-api-facade` for it. The openregister lane handed the row to
integriq under ADR-091 §6, and the change is now open there. dossiq's
half is unchanged: declare the `caseObject` types as objecttypes and
delete the controller answering today.


## Build order

The register's five to build first come first, in its order. Everything
after is grouped by what it waits on, so a lane picks the group whose
dependency has landed.

1. **The working calendar.** `terms-on-the-engine-calendar` and
   `counting-mode-per-term`, with openregister `working-calendar-admin`,
   `end-date-roll-on-the-calendar` and `calendar-time-zone`. Statutory:
   30.4% of stored terms land on a day the Algemene termijnenwet moves.
   The legal confirmation of the recognised-holiday list (task 1.1 of the
   calendar change) gates the default, not the build.
2. **Watchers.** `case-followers`, with openregister `object-watchers`.
3. **Internal versus public per entry.** `citizen-status-labels` first
   (the status entry needs a public label), then
   `timeline-entries-default-internal` with openregister
   `timeline-entry-visibility`.
4. **Refusals carry a status.** `refusals-carry-a-status`, dossiq's own,
   the instrument first.
5. **Substituted work reaches My work.** `substituted-work-reaches-my-work`,
   dossiq's own, with humaniq's leave as the absence signal.
6. **The schema sweep.** `no-schema-without-a-surface`, the instrument
   first, then batches.

Then, no dependency, config only, in any order: `case-claim-action`,
`case-type-version-chain`, `admin-inspect-entry`, `data-model-link`,
`gemachtigde-role-on-every-case-type`, `attribute-catalogue-folders`,
`live-updates-on-the-case-page`, `declared-prerequisites`,
`task-defaults-to-case-handler`, `case-delete-guard`,
`dwell-time-on-the-working-calendar`.

Waiting on a dossiq change: `task-search-fields` and
`case-reminder-as-task` (`remove-casetask`, 3 tasks left);
`document-correspondents` and `scan-verdict-on-the-row`
(`documents-on-the-case`, 2 of 13); `case-merge` (`email-case-matching`);
`pause-reason-with-chasing`, `dependent-term-follows-predecessor` and
`planned-case-series` (nothing open, but they build on
`termijnbewaking-op-engine-timers` phase 1, shipped).

Waiting on openregister: `sensitive-fields-declared`,
`field-rules-declared`, `code-lists-from-concepts`,
`duplicate-warning-at-intake` (specs exist, can start);
`edit-lock-on-the-case-page` (`run-scoped-object-locking`, open);
`case-type-rebind` (`migrate-run-between-versions`, to be specified).

## Deliberate no's

Recorded in the re-read file with the reason; no change opened.

- 2.5 `case-team-assignment` and 3.5 `task-lenses-on-engine-store`: the
  property, the column and the lenses shipped; what is left is one Team
  chip whose value nextcloud-vue cannot resolve yet.
- 3.18 task pane beside the tabs: a page layout per case type, row 11.6,
  buildiq's `page-layout-per-case-type`.
- 11.13 translation management in the UI: register content is
  translatable through openregister `register-i18n`; UI strings stay in
  l10n by decision C05.
- Q10.12 effort against something other than a case: humaniq's timesheet
  books it.

## Where this lane disagrees with the register

- **11.17 and Q11.32 are one change**, `data-model-link`: two links, one
  target, one consumer. The register keeps two slugs.
- **2.3 is S, not M**: the chain, the endpoint and REQ-ZV-02 exist; what
  is missing is the page.
- **2.20 is a gap, not "none needed"**: the spec is not wired on the case
  page. Opened as `live-updates-on-the-case-page`.
- **9.11 is its own change**, not "inside remove-casetask": that change
  belongs to another lane with 3 tasks left, so `task-search-fields`
  depends on it rather than editing it.
- **Three consumer changes for openregister-owned rows** (13.18, 6.15, and
  8.11/8.12/Q8.19). The register puts the slug on openregister and names
  dossiq's half in a column; the halves are surfaces (a Follow action, a
  default and a toggle, a flag and a zone statement), so they are opened
  here as changes that consume the openregister ones. The openregister
  lane owns the mechanism.
- **Q10.14's counts**: 47 catch-and-return-null sites in 37 files out of
  276 catches under `lib/Service/`, not 24 of 160; 93 of 106 controller
  suites assert a status.
- **Q11.31's count**: 27 schemas with no surface anywhere, not the eight
  cells the matrix names; an upper bound the structural test replaces.
- **Q12.25's note is stale**: `easter_date()` is gone; the row stays a gap
  because nothing states the prerequisites, and the README contradicts
  `info.xml` (28 to 34 versus 32).

## Halves dossiq carries without a change of its own

Two tables, generated from `gap-register.json` (rows not owned by dossiq,
not covered above, with a substantive dossiq half). The first names an
owner slug this lane consumes once it exists; the second names a shipped
or open artefact and the one task dossiq owes against it. Neither is a
change here; each is one task in the owner's change or in the dossiq
change that already covers the area.

### Against another owner's slug

| row | owner | owner slug | dossiq consumes it as |
|---|---|---|---|
| 1.4 | filinq | `document-intake-inbox` | the assign target that creates the zaakinformatieobject and the reject reason |
| 1.11 | shillinq | `leges-at-intake` | a payment request on the intake form for case types with a fee |
| 2.21 | portaliq | `change-proposal-queue` | the accept and reject actions and the field write |
| 3.16 | openregister | `migrate-run-between-versions` | the per-case migration action, paired with 2.13 |
| 3.19 | portaliq | `partner-tasks-in-the-portal` | contribute the consultation task to the partner audience; retire ExternalConsultationResponse |
| 4.8 | nextcloud-vue | `files-browser-columns` | declare the document columns on the Files tab |
| 4.14 | filinq | `merge-documents-to-pdf` | a Merge to PDF action over the selected rows; Copy to case from file-actions |
| 5.13 | openregister | `external-register-view-leaf` | place the BAG, BRK and WOZ lookups as leaf widgets on the case location |
| 6.2 | pipelinq | `contact-moments-on-pipelinq-schema` | the Communication tab over pipelinq's contactmoment with the case as domain object; retire customerContact and the kcc-werkplek copy |
| 6.9 | openregister | `send-at-on-the-messaging-leaf` | a Send later option on the case mail dialog |
| 11.6 | buildiq | `page-layout-per-case-type` | a manifest variant keyed on caseType, with visibleIf tabs as the interim |
| 11.7 | buildiq | `page-layout-per-case-type` | as 11.6 |
| 11.8 | buildiq | `page-layout-per-case-type` | declare the chips on CaseDetail once detail-header-field-chips lands |
| 11.15 | openregister | `feature-toggle-surface` | tenantConfiguration.features reads the plane instead of its own list |
| 12.3 | openregister | `objecten-api-facade` | expose caseObject types through it instead of a dossiq controller |
| 12.11 | integriq | `document-generation-vendor-adapter` | TemplateEngineAdapterInterface bound to the connector or to filinq; drop the mock |
| 12.12 | shillinq | `case-payment-requests` | financial-integration raises a payment request instead of an ERP callback |
| 9.13 | openregister | `saved-view-count-alert` | a threshold on the Overdue lens for the team lead |
| 3.20 | openregister | `macro-flows-with-next-item` | mark flows as macros on the case; nextcloud-vue next-item navigation after a write |
| 11.25 | openregister | `field-rules-by-state` | declare per case type and status which fields are hidden, mandatory or read only |
| 2.26 | openregister | `relation-types-with-inverses` | declare vervolg, subject and bijdrage with their inverse names and render both directions on Related cases |
| Q10.13 | openregister | `settings-change-audit` | route dossiq settings writes through the settings plane once it audits |
| Q13.20 | openregister | `scoped-api-tokens` | hand a supplier a scoped token instead of a user |
| Q8.18 | openregister | `term-engine-diagnostic` | a link from the termijn settings to the diagnostic with the TermijnDefinitie preselected |
| Q6.17 | openregister | `note-edit-history` | lock a journal note once it records a contact moment or a decision |
| Q6.18 | openregister | `reply-threading-by-headers` | store the Message-Id of every mail sent from a case; email-case-matching reads In-Reply-To before the subject tag |
| Q2.31 | openregister | `object-presence` | avatars of the other readers in the case header |
| Q1.14 | portaliq | `portal-identity-space` | contribute the case to that identity through the portal provider; the token share stays the anonymous fallback |
| Q1.15 | buildiq | `forms-per-case-type` | caseType.intakeFormRef becomes a list; a case type carries several forms and each presets what the citizen must not see |
| Q8.20 | openregister | `working-calendar-admin (the API half)` | push the municipal holiday list through the API instead of typing it |

### Against a shipped or open artefact (slug `none needed`)

| row | owner | covering artefact | dossiq task |
|---|---|---|---|
| 1.1 | portaliq | `dossiq/openspec/changes/leaf-integrations/proposal.md (the Forms half;` | caseType.intakeFormRef and FormsIntakeService (leaf-integrations); the portal journey targets the same intake |
| 1.5 | integriq | `integriq/openspec/changes/mail-intake-creates-cases/proposal.md` | accept the start-a-case offer (mailObjectTemplate from leaf-integrations) and the link offer |
| 2.1 | openregister | `openregister/openspec/changes/generated-identifier/proposal.md` | declare case.identifier as generated YYYY-NNNN and drop the free-text field from the forms |
| 2.19 | openregister | `openregister/openspec/changes/favourites-and-recent/proposal.md` | two lenses on Cases (Favourites, Recent) over the new endpoints |
| 3.1 | openregister | `openregister/openspec/changes/flow-bpmn-interchange/proposal.md` | workflow-definitions-to-flow moves dossiq's templates onto it; retire-cmmn-caseplanstate does the CMMN half |
| 3.4 | openregister | `openregister/openspec/changes/flow-task-forms/proposal.md` | replace DossiqAskPersonNode's bare task with a user-task node declaring the fields |
| 3.12 | buildiq | `buildiq/openspec/specs/form-editor-logic/spec.md` | author the registration form per case type there; the internal task form is OpenRegister's flow-task-forms |
| 3.13 | decidiq | `decidiq/openspec/changes/document-approval-chain-leaf/proposal.md` | place the leaf on the Files tab and read the outcome |
| 3.17 | openregister | `openregister/openspec/specs/computed-fields/spec.md` | declare formulas on case properties instead of code |
| 4.11 | nextcloud | `dossiq/openspec/changes/documents-live-on-the-case/proposal.md` | mirror the Files lock onto informatieobject.lockedOn |
| 4.17 | openregister | `openregister/openspec/changes/unified-search-file-content/proposal.md` | pass _content_search on the case search |
| 4.20 | openregister | `dossiq/openspec/specs/archief-edepot-handover/spec.md` | run the transfer on closed cases from the handover; the zip stays a convenience |
| 5.4 | openregister | `openregister/openspec/changes/contacts-leaf-cases-panel/proposal.md` | ContactDetail in contacts-domain places the cases panel |
| 5.11 | integriq | `integriq/openspec/changes/brp-kvk-store-and-subscriptions/proposal.md` | react to the change announcement on the case's requester |
| 6.4 | openregister | `openregister/openspec/changes/activity-leaf/proposal.md` | place it as the History tab (case-history-surface) and drop the duplicate version-history tab |
| 6.5 | integriq | `integriq/openspec/changes/mail-intake-creates-cases/proposal.md` | the assign target and the created case |
| 6.7 | portaliq | `dossiq/openspec/changes/archive/2026-09-09-move-portals-to-portaliq/pr` | contribute portaalBericht to the citizen audience; re-rate, move-portals-to-portaliq archived 09-09 with one task open |
| 6.13 | pipelinq | `pipelinq/openspec/specs/kcc-werkplek/spec.md (the panel); integriq/ope` | the case actions inside the panel (kcc-werkplek-zaaksysteem-bridge); no panel page of its own |
| 6.14 | integriq | `integriq/openspec/changes/zgw-connectors-for-dossiq/proposal.md` | place the connector set and mark the case as externally homed |
| 7.7 | openregister | `dossiq/openspec/specs/archief-edepot-handover/spec.md` | write resultType.archivalPeriod and archivalAction as the object's retention rule when the case closes |
| 8.7 | openregister | `dossiq/openspec/specs/archief-edepot-handover/spec.md` | surface the destruction date on the case and declare the rule per result type |
| 9.2 | openregister | `openregister/openspec/changes/query-related-schema-rows/proposal.md` | the Cases sidebar shows the case type's propertyDefinitions as filters once the query answers |
| 9.4 | openregister | `nextcloud-vue/openspec/changes/saved-views-shared-by-role/proposal.md` | ship department views for Cases as seeded shared views |
| 9.9 | openregister | `openregister/openspec/changes/contacts-leaf-cases-panel/proposal.md` | the Contacts index uses it |
| 10.5 | openregister | `openregister/openspec/changes/audit-log-page/proposal.md` | the Reports card |
| 10.8 | openregister | `openregister/openspec/changes/activity-leaf/proposal.md` | the Export action on the History tab |
| 10.10 | openregister | `nextcloud-vue/openspec/changes/dashboard-layout-per-user/proposal.md (` | My work tiles over the user's own views |
| 11.4 | buildiq | `buildiq/openspec/specs/form-editor-logic/spec.md` | open the case type's registration form in buildiq from the authoring surface |
| 11.5 | openregister | `openregister/openspec/changes/flow-bpmn-interchange/proposal.md` | open the case type's flow in CnFlowDetail from the authoring surface |
| 11.9 | nextcloud-vue | `nextcloud-vue/openspec/changes/index-columns-per-scope/proposal.md` | declare the columns per case type on Cases |
| 11.19 | openregister | `openregister/openspec/changes/rbac-department-role-matrix/proposal.md` | the Roles tab links the matrix for the dossiq register |
| 11.22 | openregister | `dossiq/openspec/changes/archive/2026-09-08-case-type-authoring-extras/` | caseType.processingActivity as a reference into the register; re-rate, case-type-authoring-extras shipped A31 |
| 12.7 | nextcloud | `none` | document it; the tenant SSO stays tenant-auth |
| 12.8 | integriq | `integriq/openspec/specs/digid-eherkenning-auth-adapter/spec.md` | retire the simulator adapters when the broker lands |
| 12.14 | integriq | `integriq/openspec/specs/events-cloudevents/spec.md` | dossiq-delivers-nothing re-points the webhooks and the NRC fan-out |
| 12.17 | integriq | `integriq/openspec/specs/connector-catalog/spec.md` | re-rate: pluggable-integration-registry archived 09-08 with 14 of 14 tasks |
| 12.20 | nextcloud | `none` | document it |
| 12.22 | integriq | `integriq/openspec/specs/cloud-event-management/spec.md` | subscribe the case to the events it should react to |
| 13.2 | openregister | `openregister/openspec/specs/rbac-scopes/spec.md` | role-routing-via-or-rbac declares the conditions dossiq's CaseAccessPolicy hard-codes |
| 13.3 | openregister | `openregister/openspec/changes/object-level-sharing-and-private-scope/p` | the Sharing tab uses it beside partner and federated shares |
| 13.5 | openregister | `openregister/openspec/changes/object-level-sharing-and-private-scope/p` | the Document properties action offers it |
| 13.6 | openregister | `openregister/openspec/changes/rbac-department-role-matrix/proposal.md` | case.assignedGroup is the field the matrix keys on |
| 13.10 | openregister | `dossiq/openspec/specs/archief-edepot-handover/spec.md` | surface the destruction date on the case and declare the rule per result type |
| 13.11 | openregister | `dossiq/openspec/changes/archive/2026-09-08-case-type-authoring-extras/` | caseType.processingActivity as a reference into the register; re-rate, case-type-authoring-extras shipped A31 |
| 13.15 | nextcloud | `none` | document it |
| 13.16 | openregister | `openregister/openspec/changes/rbac-department-role-matrix/proposal.md` | link it from the mandate matrix tab |
| 10.11 | humaniq | `dossiq/openspec/changes/hours-onto-humaniq-leaf/proposal.md` | place the humaniq-hours leaf; the ledger already corrected this row to partial on the current tree |
| 11.26 | openregister | `openregister/openspec/specs/integration-xwiki/spec.md` | place the xwiki leaf on the case and in the KCC panel |
| 6.16 | pipelinq | `pipelinq/openspec/changes/customer-satisfaction-closed-loop/proposal.m` | emit case closed as the survey trigger for the case types that want one |
| Q10.12 | humaniq | `humaniq/openspec/specs/hours-leaf/spec.md` | place the leaf on a case type or a programme where overhead is booked; re-rate |
| Q13.22 | nextcloud | `none` | write it down where a security officer looks |
| Q9.15 | hermiq | `none` | every case action is already a curated tool (hermiq-ai-tooling); a typed command line would parse onto them |

## Discovery wave 1

A second source of record sits beside the gap register: the round 4
discovery sweep, `procest/_round4/discovery/` in
ConductionNL/market-intelligence, written 2026-09-14. Thirty-six systems
read, 1,117 raw findings, 631 consolidated candidates, 70 capability
clusters in `build-plan.md`, 22 decisions in `decisions.md`, and a depth
study of case-type configurability in `casetype-configurability.md`.
Ruben answered all 22 decisions on 2026-09-14 and lifted the build hold.

The ownership rule moves 536 of the 631 candidates out of dossiq. dossiq
keeps 95 and 20 are recorded and not built. The one number to carry into a
meeting: dossiq is **71 of 180** on the domain-neutral rows, seventh of
twenty-six driven columns, against OTOBO and Odoo at 78 and xxllnc Zaken
at 125.

### The nine changes this wave opens

| change | cluster | candidates | size | decision | what dossiq owns |
|---|---|---|---|---|---|
| `casetype-field-vocabulary` | depth study CT-1, rows A1, A2, A3, A5, A9, A11, A12, A13, B7, B10, plus the A4 defect and the B2 exposure | none, it is rated from the depth study | M | D3, D2 | the `propertyType` enum and the `x-openregister-extends-form.map`, both dossiq's file |
| `ontvangstbevestiging` | 32 "Acknowledgement of receipt" | C-intake-23 (matrix hole), C-communication-54, C-communication-62, C-communication-55, C-communication-32 | M | D12, D16 | the trigger, the record and the failure mode. Statutory: Awb 4:3a |
| `inbound-mail-filters` | 25 "Mail intake that can be trusted" | C-intake-1, C-intake-11, C-intake-19, C-intake-29, C-intake-31, C-intake-26, C-intake-48 | M | D12 | the filter pipeline, the verdicts, the policy and the log. Awb 2:3 |
| `case-priority-impact-urgency` | 15 and 42 | C-search-6, C-deadlines-15 | M | D14 | impact, urgency, the matrix and the derived value |
| `case-page-and-list-as-a-place` | 58 "The case page and the list as a place" | C-search-30, C-search-25, C-search-4, C-search-19, C-configuration-55, C-configuration-27 | M | none | the declarations on three pages. No component |
| `case-recycle-window` | 39 "Delete, restore and destroy" | C-case-core-11 (matrix hole), C-access-and-privacy-65, C-access-and-privacy-50, C-documents-20, C-access-and-privacy-14 | M | D10 | the guard in front, the destroying role, the two clocks apart |
| `case-grants-name-their-source` | 11 and 54 | C-access-and-privacy-45 (matrix hole), C-access-and-privacy-62 (matrix hole), C-access-and-privacy-46, C-access-and-privacy-47 | M | D22 | the declaration, the refusal that names its rule, the access panel |
| `bulk-actions-report-progress` | 52 "Bulk action as a background job" | C-case-core-1 (matrix hole), C-case-core-2, C-case-core-4, C-case-core-45, C-reporting-30, C-case-core-3, C-search-17, C-configuration-20 | M | none | the hand-off, the skip list, the justification, the version guard |
| `unread-state-on-the-case` | 62 "Per-user unread state" | C-search-1, C-case-core-26, C-communication-15, C-communication-6, C-communication-18, C-communication-61 | M | none | what counts as a change, and where the badge sits |

### Three consumer halves that already exist and are not opened twice

The wave 1 openregister list names seven things. Three of dossiq's halves
are already open on `development` and are named here rather than
duplicated:

| openregister wave 1 item | dossiq's half, already open |
|---|---|
| the rules engine, D3, `field-rules-by-state`, `lifecycle-declarative-conditions` and `calc-engine-scalar-functions` | `field-rules-declared` |
| the code lists, `property-code-list-from-concept-scheme` | `code-lists-from-concepts` |
| the calendar feed, D11, `calendar-provider` and `integration-calendar` | `terms-on-the-engine-calendar` and `every-term-on-the-engine-calendar` |

`case-delete-guard` is the fourth of its kind. It stays as it is and
`case-recycle-window` sits behind it, per D10's answer of both.

### Slugs to be specified in openregister, wave 1

Four of this wave's dossiq changes wait on an openregister change with no
artefact on that repo's `development` yet. Each proposal records the slug
once the openregister lane opens it. `build-plan.md`'s own proposed names
are given so the two lanes converge:

| dossiq change | openregister half | proposed slug |
|---|---|---|
| `case-recycle-window` | the recycle state and the purge, beside `object-archive-state` and the shipped `retention-management` | to be specified, wave 1 |
| `bulk-actions-report-progress` | the bulk job with preview and per-row outcome | `bulk-action-jobs` |
| `unread-state-on-the-case` | the per-user read state | `object-read-state` |
| `casetype-field-vocabulary` | the property source key, `x-openregister-property-source` | to be specified, wave 1, asked for by name in integriq's `registry-backed-field-source` |

`case-grants-name-their-source` is the exception: both its openregister
halves exist, `permission-provenance-and-deny` and
`rbac-inherits-to-children`.

### What the decisions changed from the file's own recommendations

Three of the 22 were answered against the recommendation, and two of them
land in this wave.

- **D12 went to Nextcloud Mail, not integriq.** The file recommended that
  integriq hold the account, the token and the alias domains. Ruben
  answered that Nextcloud Mail owns the mail account, because the OAuth
  2.0 flow is already Nextcloud Mail's. integriq opens no mail-account
  change. dossiq's `inbound-mail-filters` reads the account Nextcloud Mail
  already holds and keeps the filter pipeline and the sender
  authentication, which were always dossiq's under D12 either way.
- **D6 is relevance-led.** Every `must` candidate enters the corpus
  whatever its passer count, so a `must` cluster is not skipped for having
  one passer. That matters most in `inbound-mail-filters`, where two of
  five `must` candidates carry documented passers only, and in
  `bulk-actions-report-progress`, where four of five have one driven
  passer each.
- **D17 is answered for a broad market.** The product serves MKB as well
  as municipalities, so the 20 candidates the lanes rated `not` are not
  disqualified: a `not` for a gemeente can be a `could` for an MKB buyer.
  None of the 20 falls in any of this wave's clusters, which every
  proposal states so nobody has to re-derive it.

### Two ratings this wave corrects

Read against `development` while writing the proposals, in the same spirit
as the re-read above.

| candidate | the lane said | what the tree says |
|---|---|---|
| C-intake-23 | `no`, "zero hits for an intake acknowledgement" | the text, the renderer and the requirement all exist (`lib/Settings/templates/ontvangstbevestiging.json`, `TermijnNotificationService.php:45,187`, `burger-notifications` REQ-TERM-008). Nothing triggers it: the only caller is a queued job and nothing enqueues one on case creation. The row stays a gap, for a different reason |
| C-search-6 | `partial`, "a value in a demo seed with nothing behind it" | `case.priority` is a schema field (`dossiq_register.json:1798`), `facetable`, written as the literal `normal` by three services. The field exists; the derivation and the sort do not |

### Later waves

Wave 2 is the intake form as an object, portal identity, the archiving
process, publication and the national indexes, the party model, saved
views and the working list. Wave 3 is agenda and rostering in humaniq, the
project above the cases in pipelinq, the assistant in hermiq, the
statutory gateways in integriq, tenancy and the layout per case type. Each
dossiq change is added to the wave table above in the PR that opens it.

## Discovery wave 4

The last OpenSpec wave of the programme: the discovery clusters dossiq
owns that still carried no change. Source of record
`procest/_gaps/gap-register.json` v4, `discovery.candidates`, every
candidate with owner `dossiq` and no `has_change`, grouped by cluster: 69
candidates over eight clusters, plus cluster 28 checked against an
existing change. Decision D6 is relevance-led, so every `must` enters and
a `should` or `could` enters when relevant.

### The nine changes this wave opens

| change | cluster | candidates | size | what dossiq owns | consumes from |
|---|---|---|---|---|---|
| `starter-content-and-templates` | 3 "What a new instance starts with", first half | C-configuration-3 (matrix hole), C-configuration-94, C-configuration-5, C-configuration-12, C-configuration-23, C-configuration-24, C-configuration-30, C-configuration-70, C-intake-17, C-access-and-privacy-77 | M | the seed, its version, the copies, the retirement, the template library, the connection test | buildiq for the form block and the free text block (to be specified), portaliq for the portal tiles |
| `first-run-and-the-tour` | 3, second half | C-configuration-43, C-configuration-96 | S | the readiness items and the tour declaration | nextcloud-vue `CnSetupWizard` (shipped), buildiq for the tour runner (to be specified) |
| `phase-terms-and-the-internal-target` | 18 "The term model" | C-deadlines-12, C-deadlines-17, C-deadlines-18, C-deadlines-20, C-deadlines-1, C-deadlines-4, C-deadlines-9, C-deadlines-13, C-deadlines-21, C-deadlines-5, C-reporting-20 | M | the phase term, the planned end, the internal target, the chain split, the declared lengths | openregister `working-calendar-admin` and `flow-business-timers`, through `terms-on-the-engine-calendar` (shipped); nextcloud-vue `index-columns-per-scope` for the list column |
| `lifecycle-acts-on-the-case` | 24 "Lifecycle acts as separate acts" | C-case-core-21, C-case-core-31, C-case-core-40 (matrix hole), C-case-core-29, C-case-core-7, C-case-core-35, C-case-core-41, C-case-core-42, C-intake-2, C-case-core-5 | M | one menu, four ending acts, the early close, incompleteness, the draft, hold | openregister's lifecycle engine; the write half is refused today, openregister#3679 |
| `intake-triage-and-refusal` | 35 "Intake routing, refusal and triage" | C-intake-8, C-intake-42, C-case-core-10, C-intake-5, C-intake-22, C-intake-33 | M | what must be answered before a case exists, the narrowing, the refusal outcome, the sleep, the fan-out | openregister `relation-types-with-inverses` and buildiq `forms-per-case-type` (both to be specified) |
| `handing-a-case-over` | 36 "Handing a case to another team" | C-case-core-44 (matrix hole), C-case-core-46 (matrix hole), C-case-core-34, C-access-and-privacy-56 | M | the internal handover, the second seat, the external home, the leaver handover | openregister rbac (shipped), integriq `zgw-connectors-for-dossiq`, humaniq for an offboarding signal (to be specified) |
| `task-as-a-first-class-record` | 53 "The task as a first-class record" | C-tasks-and-phases-10, C-tasks-and-phases-11, C-tasks-and-phases-13, C-tasks-and-phases-14, C-configuration-56, C-tasks-and-phases-16, C-tasks-and-phases-2 | M | the per-task configuration, the always-available acts, the declared effects, completion in place | openregister's task engine and `flow-task-forms`; the claim act and the task number to be specified; nextcloud-vue `working-list-row-actions` |
| `one-personal-queue` | 64 "One personal queue" | C-tasks-and-phases-26, C-deadlines-2, C-tasks-and-phases-27, C-tasks-and-phases-5, C-tasks-and-phases-22 | M | the queue source contract, the queue, the digest, the end of day, the personal stage | humaniq `hours-leaf` and `leave-management`, openregister `notification-routing-per-group-and-scope`, nextcloud-vue `notification-preferences-ui` |
| `live-conversation-on-the-case` | 70 "Talking to the citizen live" | C-communication-31, C-case-core-6, C-communication-25 | M | the conversation on any case, its record, the capture, the major declaration | Nextcloud Talk, and hermiq for the assistant per D13 |

Cluster 3 is two changes because it is two nouns and the build plan splits
them itself: "extend dossiq `lib/Settings/vth-templates/` and
`TenantSeedService`; buildiq owns the walkthrough". What ships in the box
waits on nothing; being walked through a first run waits on buildiq.

### Cluster 28, answered by an existing change rather than a new one

D12 put the mail account in Nextcloud Mail, so the cluster was
deliberately not opened. Checked against dossiq `inbound-mail-filters`:
C-intake-45 is answered by REQ-IMF-01, and C-integrations-42 was answered
only for the mailbox, never for sending. That change gains two sibling
requirements rather than a change of its own: **REQ-IMF-11**, outbound
mail leaving through the same account with no dossiq credential and filed
in its sent folder, and **REQ-IMF-12**, a sender identity per team
declared on the case type.

### Three consumer halves of other apps' wave 4 changes

| change | consumes | candidates |
|---|---|---|
| `decision-outcomes-on-the-case` | decidiq `the-decision-as-a-walked-process` (decidiq#1316), cluster 22 | C-decisions-1, C-decisions-13, C-decisions-25 |
| `widget-roles-declared` | launchpad `dashboards-and-who-may-see-them` (launchpad#637), cluster 12, ledger row 10.1 | C-reporting-22 |
| `fees-and-payments-on-the-case` | shillinq `fees-payments-and-the-contract-register` (shillinq#1608), cluster 55, ledger rows 1.11 and 12.12 | C-intake-44, C-intake-7, C-deadlines-10, C-parties-and-contacts-1 |

The fourth, integriq `outbound-sender-identity-and-deliverability`
(integriq#2012, cluster 61, candidate C-communication-44), is a sibling
requirement on `inbound-mail-filters` rather than a change, because its
noun is outbound mail and REQ-IMF-12 is where that already sits.

### Changes this wave asks another repo to open

Each is named in the proposal that needs it, under "Needs a change in
<app>", and none has an artefact on that repo's `development`.

| app | what is missing | asked for by |
|---|---|---|
| openregister | the lifecycle provider write half; openregister#3679 is an issue, not a change | `lifecycle-acts-on-the-case` |
| openregister | candidate users and groups on an engine task, with a claim act | `task-as-a-first-class-record` |
| openregister | a task's own number and its own lock | `task-as-a-first-class-record` |
| openregister | `relation-types-with-inverses`, the register's row 2.26 slug | `intake-triage-and-refusal` |
| buildiq | the reusable form block and the free text block | `starter-content-and-templates` |
| buildiq | the tour runner: per surface, per person, a broken step reported | `first-run-and-the-tour` |
| buildiq | `forms-per-case-type`, the register's row Q1.15 slug | `intake-triage-and-refusal` |
| nextcloud-vue | a progress and days-left column type for a list | `phase-terms-and-the-internal-target` |
| humaniq | an offboarding signal; `leave-management` covers leave, not uitdiensttreding | `handing-a-case-over` |

### Ratings this wave corrects

Read against `development` at `172d364f` while writing the proposals.

| candidate | the lane said | what the tree says |
|---|---|---|
| C-case-core-21 | `no`, a status marked not visible | the flag is honoured, under a second name. `case.statusHiddenInLists` is calculated from `statusType.hiddenInLists` and the Cases All lens filters it (`src/manifest.json:1180`, asserted at `tests/vitest/caseTypeAuthoringManifest.spec.js:154`). The gap is the other lenses, the Queue page, My Work, the counts and the tiles. Searching for the declared flag name finds only a form field and reads dark, which is why REQ-LIFE-02 traces calculations rather than names |
| C-case-core-31 | `no`, "dossiq renders no lifecycle action at all on a case" | `CaseDetail` carries Suspend, Resume, Extend term, Reopen, Copy case, Start and Plan follow-up as header actions, and `CaseActionProvider` publishes the transition set. The gap is one menu and one gate, not the acts |
| C-case-core-44 | `partial`, "reassign changes the assignee, not the owning team" | `CaseTransferService` performs the whole act with a reason, an acceptance, a rejection and a custody trail, for another **organisation**. The gap is the organisation boundary |
| C-deadlines-18 | `partial` | `caseType.suspensionAllowed` and `extensionAllowed` are declared and enforced at `CaseLifecycleService.php:114` and `:116`. Only the length in days is missing: `extensionPeriod` is read by the ZGW mapping and by nothing that moves a deadline |
| C-communication-31 | `no` by one lane, `partial` by another | `HearingService.php:89` creates a Talk room through `OCP\Talk\IBroker`, schedules it, records attendance and files minutes, for the bezwaar hoorzitting only. Generalising it is the work |
| C-intake-8 | `partial` | `case` already carries `intakeChannel`, `communicationChannel` and `confidentiality`; `required` is `["title", "caseType"]`. The fields exist and nothing asks for them |
| C-configuration-94 | `must`, `yes` | confirmed and wider than the lane read: six case types under `lib/Settings/vth-templates/` and six seed files loaded by `SeedDataService` and `TenantSeedService` |

### Recorded, not built

Seven members enter the corpus under D6 and D17 and no requirement is
written for them. Each carries its reason in its change's proposal.

| candidates | why |
|---|---|
| C-configuration-60, C-configuration-76, C-configuration-79, C-configuration-83, C-configuration-102, C-configuration-103 | vendor-model and positioning claims rather than behaviour. One is the lane's own words: "Positioning claim, no testable behaviour named". Two the lane already rated not applicable to an open source fleet |
| C-tasks-and-phases-37 | planning a personal day into hourly slots. The lane's own clause argues against it for this market: "a caseworker with fourteen open zaken plans by term, not by hour" |

### Halves declined, with the reason

| candidate | half declined | why |
|---|---|---|
| C-deadlines-21 | bundling the vocabularies onto one queue object | its three separately clocked targets are built, as the same second clock as C-deadlines-9. dossiq holds its vocabularies on the case type and in OpenRegister code lists, and moving them onto a queue would undo `code-lists-from-concepts`. The lane says the same: "Its distinctive half is the bundling, not the second clock" |
| C-intake-37 | several mail alias domains on one instance | Nextcloud Mail holds the accounts under D12, so a second domain is an account selection rather than a dossiq feature |

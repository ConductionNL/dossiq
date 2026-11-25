# Tasks: competitor-parity-2026-09

The build order of the proposal as checkboxes. A box is ticked when the
named change is archived.

- [x] 1 `terms-on-the-engine-calendar`, `counting-mode-per-term`
- [x] 2 `case-followers`
- [x] 3 `citizen-status-labels`, then `timeline-entries-default-internal`
- [x] 4 `refusals-carry-a-status`
- [x] 5 `substituted-work-reaches-my-work`
- [x] 6 `no-schema-without-a-surface`
- [x] 7 No dependency: `case-claim-action`, `case-type-version-chain`,
  `admin-inspect-entry`, `data-model-link`,
  `gemachtigde-role-on-every-case-type`, `attribute-catalogue-folders`,
  `live-updates-on-the-case-page`, `declared-prerequisites`,
  `task-defaults-to-case-handler`, `case-delete-guard`,
  `dwell-time-on-the-working-calendar`
- [x] 8 After `remove-casetask`: `task-search-fields`,
  `case-reminder-as-task`
- [x] 9 After `documents-on-the-case`: `document-correspondents`,
  `scan-verdict-on-the-row`
- [x] 10 After `email-case-matching`: `case-merge`
- [ ] 11 On termijnbewaking phase 1: `pause-reason-with-chasing`,
  `dependent-term-follows-predecessor`, `planned-case-series`
- [x] 12 Openregister specs exist: `sensitive-fields-declared`,
  `field-rules-declared`, `code-lists-from-concepts`,
  `duplicate-warning-at-intake`
- [x] 13 After openregister lands them: `edit-lock-on-the-case-page`,
  `case-type-rebind`
- [ ] 14 Re-run the re-read when the register's batch 7 and 9 rows land
- [ ] 15 The regenerated register's five dossiq changes (2026-09-13):
  `status-capacity-limit` and `intake-says-when-the-term-starts` have no
  dependency; `archived-cases-leave-the-lenses` and
  `deelzaken-inherit-the-parent-grants` wait on openregister;
  `cases-views-are-places` waits on nextcloud-vue
- [x] 16 `one-date-write-path` (batch 11 row 8.22): no dependency, the
  structural test first
- [x] 17 `every-term-on-the-engine-calendar` (batch 12 row 8.23): the
  audit first, which depends on nothing; the three fixes wait on
  `terms-on-the-engine-calendar` for the roll rule
- [ ] 18 Discovery wave 1, the nine changes opened 2026-09-14:
  `casetype-field-vocabulary` (CT-1, no dependency, the cheapest rows in
  the study); `inbound-mail-filters` (cluster 25, D12 as answered for
  Nextcloud Mail); `ontvangstbevestiging` (cluster 32, Awb 4:3a, after
  the mail gateway); `case-priority-impact-urgency` (D14);
  `case-page-and-list-as-a-place` (cluster 58, waits on nextcloud-vue);
  `case-recycle-window`, `bulk-actions-report-progress` and
  `unread-state-on-the-case` (wait on openregister, wave 1);
  `case-grants-name-their-source` (openregister's halves already open)
- [ ] 19 Record the openregister slug in each of the four proposals that
  wait on one, as that lane opens it
- [ ] 20 Follow the property source key integriq asked openregister for,
  `x-openregister-property-source`, in `casetype-field-vocabulary`; if
  openregister picks another name, follow it rather than the reverse
- [ ] 21 Ask the corpus lane for the missing priority row, in D14's own
  words, and rate every driven column for it
- [ ] 22 Carry the two rating corrections above back to the discovery
  lanes: C-intake-23 and C-search-6
- [ ] 23 The pending proposals of 2026-09-14, thirty rows: ten changes,
  none of which blocks another. `aanvullingsverzoek-as-a-record` waits on
  `pause-reason-with-chasing`; `term-configuration-beyond-the-case-type`
  waits on `termijnbewaking-op-engine-timers`;
  `what-a-status-declares`, `what-a-transition-declares`,
  `task-dependencies-and-the-next-planned-action`,
  `routing-by-weight-position-and-area`,
  `custody-and-handover-of-a-case`,
  `markers-and-assessments-on-the-case`,
  `splitting-a-case-and-its-incidents` and
  `the-social-domain-plan-and-its-grounds` have no dossiq dependency and
  each names the openregister slug it consumes
- [ ] 24 Rows 2.34, 7.8 and Q8.23 are carried by `case-priority-impact-urgency`,
  `frozen-beschikking-and-numbered-successor` and
  `every-term-on-the-engine-calendar`. Re-rate them from those changes
  when each is archived, not from a fresh reading of the tree
- [ ] 25 `one-timeline-on-the-case` (row 6.4), the consumer of
  openregister `timeline-entries-are-records` (#3762, merged 2026-09-15).
  No dossiq dependency. Rows 6.2 and 6.15 read the same entries:
  `timeline-entries-default-internal` owns the visibility half, and the
  Communication tab stays the place a contact moment is logged
- [ ] 26 `case-search-declares-its-fields` (rows 9.2, 9.1, 9.12), the
  consumer of openregister `search-quality-operators-and-facets` (#3768
  and #3806, both merged 2026-09-15). No dossiq dependency. The
  missing-value chip in the facet sidebar waits on
  ConductionNL/nextcloud-vue#1176 and is not built here; the change ships
  a not-set lens instead, which needs no facet payload
- [ ] 15 Consumer halves of what buildiq serves (handed over 25 Nov 2026 by the
  buildiq lane; leaf ids and example answers in `buildiq-consumer-halves.md`).
  Each stays optional: with buildiq absent or answering nothing, dossiq keeps
  its manifest shape.
  - [ ] 15.1 Case page: ask `buildiq-page-layout` for the case being opened,
    as the signed-in handler with no audience filter (the desk channel); merge
    the one answered layout over the manifest config, render the manifest
    unchanged when `items` is empty (buildiq `case-page-layout-per-case-type`,
    `screen-overrides-as-a-patch-with-fall-through` 5.1).
  - [ ] 15.2 Task panel: place the case's task list where the answered
    `taskList.panel.placement` says (`side`, `tab` beside the named `tabs`, or
    `none`), collapsed when `collapsed` is true (buildiq
    `pages-task-panel-beside-case-tabs`, REQ-BQTP-003).
  - [ ] 15.3 Task list and upload dialog may read the same leaf answer
    (`taskList`, `uploadFields`) and keep their manifest shape when nothing
    answers (buildiq `case-page-layout-per-case-type` 8.5).
  - [ ] 15.4 Create form: `friendly-case-create-form` asks
    `buildiq-registration-form` on the case type with `audience` and `channel`,
    renders `items[0]` (fields in the served order, grouped by `sections`) and
    offers `drafts`; hidden presets arrive only in `presets[]` and are written
    on save, never shown (buildiq `registration-form-builder` 4.4).
  - [ ] 15.5 Write `case.intakeChannel` from the served form's `channel` when a
    case is created from it (buildiq `forms-per-case-type` 8.4).
  - [ ] 15.6 Field half of gap-register row B16: `order`, `group` and
    `placeholder` on `propertyDefinition`, in the CT-1 change (buildiq
    `competitor-parity-2026-09` 2.1).
  - [ ] 15.7 With openregister: the portal flag on a property, on the field and
    not on the form (decision D16 in buildiq `competitor-parity-2026-09` 2.2).

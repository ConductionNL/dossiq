# Tasks: competitor-parity-2026-09

The build order of the proposal as checkboxes. A box is ticked when the
named change is archived.

- [ ] 1 `terms-on-the-engine-calendar`, `counting-mode-per-term`
- [ ] 2 `case-followers`
- [ ] 3 `citizen-status-labels`, then `timeline-entries-default-internal`
- [ ] 4 `refusals-carry-a-status`
- [ ] 5 `substituted-work-reaches-my-work`
- [ ] 6 `no-schema-without-a-surface`
- [ ] 7 No dependency: `case-claim-action`, `case-type-version-chain`,
  `admin-inspect-entry`, `data-model-link`,
  `gemachtigde-role-on-every-case-type`, `attribute-catalogue-folders`,
  `live-updates-on-the-case-page`, `declared-prerequisites`,
  `task-defaults-to-case-handler`, `case-delete-guard`,
  `dwell-time-on-the-working-calendar`
- [ ] 8 After `remove-casetask`: `task-search-fields`,
  `case-reminder-as-task`
- [ ] 9 After `documents-on-the-case`: `document-correspondents`,
  `scan-verdict-on-the-row`
- [ ] 10 After `email-case-matching`: `case-merge`
- [ ] 11 On termijnbewaking phase 1: `pause-reason-with-chasing`,
  `dependent-term-follows-predecessor`, `planned-case-series`
- [ ] 12 Openregister specs exist: `sensitive-fields-declared`,
  `field-rules-declared`, `code-lists-from-concepts`,
  `duplicate-warning-at-intake`
- [ ] 13 After openregister lands them: `edit-lock-on-the-case-page`,
  `case-type-rebind`
- [ ] 14 Re-run the re-read when the register's batch 7 and 9 rows land
- [ ] 15 The regenerated register's five dossiq changes (2026-09-13):
  `status-capacity-limit` and `intake-says-when-the-term-starts` have no
  dependency; `archived-cases-leave-the-lenses` and
  `deelzaken-inherit-the-parent-grants` wait on openregister;
  `cases-views-are-places` waits on nextcloud-vue
- [ ] 16 `one-date-write-path` (batch 11 row 8.22): no dependency, the
  structural test first
- [ ] 17 `every-term-on-the-engine-calendar` (batch 12 row 8.23): the
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

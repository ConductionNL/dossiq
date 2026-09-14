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

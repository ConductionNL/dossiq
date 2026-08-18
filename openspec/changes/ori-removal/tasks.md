# Tasks: ori-removal

Paired with decidesk `ori-adoption`. kind: code + data migration.

**Gate T0 blocks everything below it** — do not start T2+ until decidesk's
release carrying `ori-adoption` (schema deltas, `occ decidesk:import-ori`,
`/feed/ori/*.rss` adapter) is merged and deployed.

- [ ] **T0**: Confirm the decidesk `ori-adoption` release is deployed on the
  target instance(s): `occ app:list` shows the decidesk version containing the
  importer; `occ decidesk:import-ori --dry-run --strict --source-register=ori`
  runs and reports per-schema counts. Record the counts.

- [ ] **T1**: Execute the data migration per the runbook in `design.md`:
  dry-run → live run → **measured** parity verification (source register
  counts vs `ori:*`-tagged decidesk counts via the OpenRegister API, using
  `_limit`; `stemming` counts double as Decision + VotingRound) → feed
  spot-check on decidesk. On mismatch: `occ decidesk:import-ori --rollback`,
  diagnose, re-run. Do not proceed to T2 without recorded parity.

- [ ] **T2**: Delete `lib/Settings/ori_register.json` and
  `lib/Repair/RegisterOriRegister.php`; remove their `<install>` and
  `<post-migration>` entries from `appinfo/info.xml`. Add
  `lib/Repair/RetireOriRegister.php` (post-migration only) implementing the
  fail-safe retirement from `design.md`: no-op without OpenRegister or without
  an `ori` register; warn-and-keep while unmigrated objects exist; JSON backup
  export then delete register + schemas once parity holds. PHPUnit-cover all
  four branches (the guard must be shown able to say NO: a test seeds one
  unmigrated object and asserts the register survives).

- [ ] **T3**: Delete `lib/Cron/OriDataQualityCheck.php` and its `<job>` entry
  in `appinfo/info.xml`; delete
  `lib/Controller/RaadsinformatieFeedController.php` and the three
  `/feed/ori/*.rss` route entries in `appinfo/routes.php`. Delete or repoint
  their tests. Document the feed URL move (procest `/apps/procest/feed/ori/*`
  → decidesk `/apps/decidesk/feed/ori/*`) in the release notes — no proxy, no
  redirect.

- [ ] **T4**: Cross-app meeting link — add the additive optional
  `case.meetingRef` property (decidesk Meeting uuid) to
  `lib/Settings/procest_register.json` (bump register version so the import is
  not a no-op); rework `src/views/besluitvorming/VergaderingDetailView.vue` to
  render case data + "Open meeting in decidesk" deep link / ADR-019 leaf, with
  the quiet unavailable fallback when decidesk is absent or `meetingRef` is
  empty. Keep the `/besluitvorming/vergaderingen/:id` route registered.
  Correct the stale "created in the ORI register" header comment in
  `lib/Service/VergaderingCaseService.php`.

- [ ] **T5**: Run the grep gate from `design.md` (`ori_register`,
  `register: 'ori'`, `RegisterOriRegister`, `OriDataQualityCheck`,
  `RaadsinformatieFeed` over `lib/ src/ appinfo/`) — assert matches only in
  `RetireOriRegister` + its tests AND that the searched file count is
  non-zero. Run `composer check:strict`, the PHPUnit suite, and the
  besluitvorming e2e (deep-link route still renders). Verify on a dev
  instance: `occ upgrade` retires the register only after T1 parity, and a
  fresh install creates no `ori` register.

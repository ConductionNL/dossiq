## Summary

Four architecture tests were red on an untouched `parity/round2`. Three are fixed here and the fourth is named with a decision to make. Every one was measured on the branch before anything was changed.

**Two implementations of one statutory rule, with opposite defaults.** `b72f2f3f` (#2941, mine) added `Service/Termijn/WorkingDayRoll.php`, and `TermijnTimerService::rollTermEndFor()` was already there from the `every-term-on-the-engine-calendar` change, used by `NoticeOfDefaultService`, `CaseTermsService` and `DwangsomUitbetalingService`. That alone is a duplicate. The real defect is that the two disagreed: `WorkingDayRoll` read an absent `rollToWorkingDay` as OFF, `rollEnabled()` reads it as ON, because Awt art. 1 applies by law and not by configuration. The same case could get two different end dates depending on which path reached it.

`WorkingDayRoll` and its test are deleted, `createTermijnInstance()` routes through `rollTermEndFor()`, and the schema default follows the code to `true`. The switch now means what the sibling change says: turn the roll OFF for a term the Awt does not govern. `EveryTermOnTheCalendarTest` then reported its own allowlist entry for `TermijnService.php` as stale, which is that list working, so the entry goes and the file is green on both halves.

The reversal is mine to own: I shipped `default: false` four hours ago reasoning that the roll should wait on legal confirmation. The sibling lane's reading is better, and two defaults in one app is worse than either.

**A private date parser is a second rule for what a date is.** `TermijnService::momentOf()` came in with `8f2cd30b` (#2914) and `startOf()` is older; both built instants with `new DateTimeImmutable($raw)`, which reads the PROCESS zone, so one stored string became a different day on two servers and the swallowing catch meant nothing reported it. Both now route through `CaseDateNormaliser`, which is the class `OneDateWritePathTest` exists to protect.

That change caught a real one in the suite: `TermijnRearmTest` built the service with two positional arguments, so the normaliser defaulted to null and the successor silently started today instead of the day the case was received. The test now injects it.

**The catch list is classified and the ceiling is not raised.** Eleven swallowing catches across nine files had no allowlist entry, from `8f2cd30b` (#2914), `1a248288` (#2919), `b72f2f3f` (#2941) and older. Each now carries a class and a reason naming its own method, which is what the test asks for. `CapacityGuard::countIn()` is the one worth reading: null is deliberately not zero there, because zero would read as "the status is free" and let a transition through.

## Still red, deliberately

`ServiceCatchReturnsNullTest::testTheCeilingOnlyGoesDown`: **249 live swallowing catches against a ceiling of 238.** Classifying does not lower it, because `liveSites()` counts every site whether classified or not. Going green means converting eleven catches in `FormsIntakeService`, `PlannedActionService`, `EngineRunMigration`, `CaseTypeStore`, `ScanVerdictReader`, `InterventionProvider` and `CapacityGuard` into typed refusals, across four other lanes' changes. That is a debt sweep, not a build fix, and CLAUDE.md is explicit that a branch which turns into one is the loop to end.

There is also a design question for whoever owns the ratchet: every one of the eleven is a legitimate `degradation`-class site, and a raw count cannot tell a new legitimate degradation from a newly swallowed refusal. The ceiling needs a deliberate decision rather than a quiet bump from me.

## Not fixed here: the manifest Ajv failure

Measured and diagnosed, fix deferred to its own PR because it cannot be verified from this machine right now. `/pages/3` (Queue) and `/pages/5` (Cases) carry `savedViewPlaces`, added by `cc29faf5` (#2922). The installed `@conduction/nextcloud-vue` carries manifest schema **2.33.0**, which has no such property; the schema on nextcloud-vue's own `parity/round2` is **2.34.0** and does have it. So the manifest is right and the dependency is behind, exactly as the coordinator suspected.

Two separate facts, both verified from source rather than assumed:
- `package-lock.json` pins 3.2.0 while `node_modules` holds **3.1.0**, so this clone is stale even against its own lock.
- The published 3.2.0 tarball carries schema 2.33.0. I unpacked it and checked. So refreshing to the locked version would not fix it either; a real version bump is needed.

I could not confirm which published version carries 2.34.0: `npm install` and three tarball fetches for 3.3.0 all timed out against the registry from this box. Naming a version I have not verified would be a guess, and a dependency bump nobody checked is the kind of green that means nothing.

## Checks

- `php -l` on every changed PHP file: clean.
- `./vendor/bin/phpunit --filter 'Termijn|CaseTerms|MandateRegistry|WorkingDay|Architecture|Deadline|NoticeOfDefault|OrContract'`: 396 tests, 1687 assertions, one failure, the ceiling named above.
- `tests/Unit/Settings/SchemaVersionFloorTest.php`: green. Checked rather than assumed: the digest deliberately ignores what the importer compares, and a `properties` change is visible to it, so `deadlineDefinition` needs no version bump.
- `npm run test:l10n`: green, all four checks. No new user-facing strings.
- Build-first phase, so phpmd, psalm, phpstan, the whole-tree gates, `check:strict` and Playwright are deferred to the sweep and recorded in `quality-debt.md`.

## Test plan

- `EveryTermOnTheCalendarTest` goes green on both halves: no term path computes a date off the calendar, and no allowlist entry is stale. The second is what proves the fix rather than a suppression.
- `OneDateWritePathTest` goes green with no new allowlist entry, which is the distinction that matters: the parser is gone, not excused.
- `ServiceCatchReturnsNullTest`: three of four tests green. Every entry names its own method, which the test enforces and which caught two of my reasons on the first pass.
- The regression the change surfaced is kept as coverage: `TermijnRearmTest` asserts the successor starts on the day the case was received, and it now fails if the normaliser is not injected.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

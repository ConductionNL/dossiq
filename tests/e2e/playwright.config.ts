/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * in it runs. The ROOT `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). It re-shoots every
 *                  tutorial screenshot into `docs/static/screenshots/…` and is
 *                  driven deliberately by `npm run test:e2e:docs` (and by the
 *                  dedicated `Journeydoc Capture` job, which passes
 *                  `--project docs-capture` explicitly).
 *   visual       — pixel-diff baselines (GAP-5). Its own README records the
 *                  reason it cannot gate: the committed PNGs are host-font and
 *                  GPU specific, so a CI Linux runner does not byte-match a
 *                  dev-container baseline. Running it here would fail every
 *                  run for a reason that has nothing to do with the change.
 *
 * Letting the root config be picked would therefore make every PR both
 * re-shoot the documentation screenshots and fail on unmatched pixel
 * baselines. Rather than delete or weaken either project, `playwright-test-path:
 * tests/e2e` in the caller makes the workflow's FIRST lookup hit this file,
 * which declares only the regression project. The root config is untouched and
 * stays the entry point for local runs, `npm run test:e2e:docs` and
 * `--project visual`.
 *
 * ⚠️ `testIgnore` HAS TO BE REPEATED AT PROJECT LEVEL.
 * A project-level `testIgnore` REPLACES the top-level one, it does not merge
 * with it. Both lists below are therefore complete on their own, so a future
 * reader cannot delete one and silently start collecting `global-setup.ts`,
 * `base-url.ts` or `helpers/*.ts` as if they were specs (they export helpers,
 * not tests — Playwright errors with "no tests found in file"), or start
 * re-shooting docs screenshots and diffing visual baselines on CI.
 *
 * ARTIFACT PATHS
 * --------------
 * The report and trace output stay under `tests/e2e/`. The shared workflow's
 * upload steps list `server/apps/<app>/tests/e2e/playwright-report/` and
 * `.../tests/e2e/test-results/` alongside the app-root paths, so both produce
 * a downloadable artifact — and `tests/e2e/.gitignore` already ignores these
 * two directories plus `.auth/`, so nothing lands in `git status` locally.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

/**
 * Non-spec modules that live inside `testDir` and must never be collected.
 *
 * `docs-screenshots.spec.ts` and `visual/**` ARE specs — they are excluded
 * because they belong to the two opt-in projects described in the header, not
 * because they are unrunnable.
 */
const IGNORED = [
	// Helper / infrastructure modules that export no tests.
	'**/global-setup.ts',
	'**/base-url.ts',
	'**/helpers/**',
	'**/visual/_visual-helpers.ts',
	// Opt-in projects — see the header.
	'**/docs-screenshots.spec.ts',
	'**/visual/**',
]

/**
 * Specs that change state the whole instance shares, so they cannot run
 * alongside the rest once there is more than one worker.
 *
 * `demo-data-setup-step` records a setup decision and can install the demo
 * dataset; `integrations-page` POSTs to `/api/settings`; `case-type-edit-and-setup`
 * drives the setup wizard; `demo-caseload` reads the demo dataset and is
 * meaningless if another worker is mid-install. Each of these is either a
 * writer of instance state or a reader that a writer can invalidate.
 */
const INSTANCE_MUTATING = [
	'**/spec-coverage/demo-data-setup-step.spec.ts',
	'**/integrations-page.spec.ts',
	'**/case-type-edit-and-setup.spec.ts',
	'**/demo-caseload.spec.ts',
]

/**
 * Sharding, as the shared quality workflow runs it when `e2e-shards` is above 1.
 *
 * Each shard is its own runner with its own Nextcloud and its own Postgres, and
 * the workflow runs `npx playwright test --shard=<index>/<total>` on it. It also
 * exports the two numbers below, because two things in this file have to change
 * shape when the suite is split, and Playwright does not tell a config which
 * shard it is.
 *
 * Unset, or 1, means one job running the whole suite. Everything below then
 * behaves exactly as it did before sharding existed.
 */
const SHARD_TOTAL = Number(process.env.E2E_SHARD_TOTAL ?? 1)
const SHARDED = SHARD_TOTAL > 1

export default defineConfig({
	testDir: __dirname,
	// See the header: also repeated on the project below, because a
	// project-level testIgnore REPLACES this list rather than extending it.
	testIgnore: IGNORED,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	// FOUR WORKERS ON CI, ONE LOCALLY.
	//
	// The comment here used to argue for one worker, on the grounds that
	// `helpers/fixtures.ts#ensureCaseType` adopts `listObjects('caseType')[0]`,
	// so worker B could adopt worker A's throwaway and have it deleted out from
	// under it mid-test. That defect was real, it was in seven sites rather than
	// the one named, and it is fixed at the source: `adoptableCaseTypes()`
	// excludes fixture-owned rows, so a worker can only adopt a caseType no
	// teardown will remove.
	//
	// `fullyParallel` stays FALSE, so this parallelises at FILE granularity:
	// different spec files run on different workers, and the tests inside one
	// file still run in order on a single worker. That is the conservative half
	// of parallelism and it is the half this suite needs, because several files
	// build shared state in `beforeAll` and read it across their tests. The
	// files that mutate instance state are listed in `INSTANCE_MUTATING` above
	// and run in their own serial project after the parallel one.
	//
	// WHY IT HAS TO CHANGE. Measured off the log timestamps of run 34244366521,
	// not estimated:
	//
	//     111 tests produced a result in 37.6 min      20.3s each
	//     the 25 failures cost 18.5 min of that        22.2s x2 for the retry
	//     if every one became a ~5.1s pass             saves 16.4 min
	//     all 371 tests at the remaining rate          71 min SERIAL
	//
	// So fixing every red test still leaves the suite at roughly twice the 38
	// minute budget. Parallelism is the only lever that closes that gap.
	//
	// FOUR IS MEASURED RATHER THAN REASONED. This started at three, to keep
	// distance from a `SQLSTATE[53200] out of shared memory /
	// max_locks_per_transaction` that had been seen ONCE under four. Both counts
	// were then run against the same tree:
	//
	//     workers   reached a verdict   passed   never ran   postgres locks
	//        1            144             108       227          -
	//        3            282             205        89          0
	//        4            309             230        60          0
	//
	// Four reached more and the lock error did not reappear. One sighting against
	// a clean run is a weak argument, so the measurement wins over the caution.
	// Raise it further only the same way: behind a run, not behind arithmetic.
	//
	//        5           measured below: FEWER results per minute, not more
	//
	// 🔴 FOUR, AND FIVE WAS MEASURED AND MADE IT WORSE. #2485 raised this to
	// five on the reasoning that the gap had become throughput. The runs it
	// produced say the fifth worker buys no throughput and costs timeouts.
	// Measured over every E2E job on 2026-09-10 and 11 that ran the whole
	// suite (87 at four workers, 13 at five), read off the job logs:
	//
	//     THE RUNNER DECIDES FIRST. Identical code took 96.8 test-minutes on
	//     run 34581297676 and 123.0 on 34585313834, 35 minutes apart, with
	//     every spec slower by the same factor. GitHub's runners come in
	//     speeds about 1.3x apart, and the time the runner spends building
	//     decidiq (second `webpack ... compiled in` line of the job) sorts
	//     them: under 72s fast, 72 to 88s medium, over 90s slow. Of the 45
	//     four-worker jobs that line exists for, 29 landed on a slow runner:
	//     21 were stopped by the 38 minute globalTimeout and 5 more ended in
	//     its last half minute. The 16 fast or medium ones ended by 33.2.
	//     So workers are compared within a runner speed, never across one.
	//
	//     EVERY TEST GETS SLOWER, NOT JUST THE PAGE LOADS. Matching each test
	//     to itself on runners of the same speed, a fifth worker made its
	//     median duration 1.22 to 1.42 times longer, in every duration band
	//     from sub-second API tests to 30-second journeys (366 tests on fast
	//     runners, 338 on slow). A four-vCPU runner holding four Chromes, eight
	//     PHP workers and Postgres is already saturated; a fifth Chrome only
	//     divides the same CPU finer.
	//
	//                                   4 workers   5 workers
	//     results per minute, fast       15.9        14.7
	//     results per minute, slow        9.5         9.4
	//     flaky tests per slow run        1.2         4.8
	//     test timeouts per job           0.36        1.08
	//     page.goto timeouts per job      0           1.38
	//     hook timeouts per job           0.32        1.69
	//
	// So five did not close the gap and could not have: it turned time into
	// failures. Sharding (#2497) closes the gap, by giving each shard its own
	// runner; the count below is per shard, and the same arithmetic applies
	// to every one of them.
	//
	// ⚠️ WATCH FOR `SQLSTATE[53200] out of shared memory /
	// max_locks_per_transaction`. That is the failure three was held back to
	// before four was measured, seen once under four and never since.
	//
	// Raise this again only the way it was lowered: compare results per minute
	// within one runner speed, not the wall clock of one run.
	//
	// One locally, deliberately. A developer runs this against the SHARED dev
	// instance, where four workers seeding and tearing down at once is both
	// slower and ruder than one.
	//
	// `E2E_WORKERS` overrides both, so the count can be re-measured without a
	// code change.
	workers: Number(process.env.E2E_WORKERS ?? (process.env.CI ? 4 : 1)),
	retries: process.env.CI ? 1 : 0,
	// Stop on our own clock, ahead of the shared job's `timeout-minutes: 45`.
	//
	// The `actionTimeout` note below records exactly what happens without this:
	// "a 45-minute job that CI cancelled after 65 of 122 tests". A cancelled
	// job is not a verdict — Playwright never prints its tally, the
	// `if: failure()` trace upload never fires, and the `if: always()` report
	// upload does not run on a cancelled job either. The evidence that 57 tests
	// never ran had to be reconstructed from the live log rather than read off
	// an artifact, because there was no artifact.
	//
	// With a globalTimeout Playwright stops itself and exits with a count, and
	// the uploads run.
	//
	// The margin is thinner than this comment used to say. It claimed 2.0-2.4
	// minutes of setup before `Run Playwright tests` starts, and so about 7
	// minutes to spare. Read off the step timestamps of three jobs on
	// 2026-09-11 (103222399186, 103224405685, 103237298031), setup now takes
	// 5.8 to 5.9 minutes, most of it installing and building decidiq and
	// openregister. Job 103224405685 ran 44m03s against the 45 minute cap. So
	// 38 minutes still produces a tally, with about one minute to spare.
	//
	// SHARDED, EACH SHARD GETS 25 MINUTES. `globalTimeout` is per process, so
	// it is per shard, and leaving 38 in place would let a shard that should
	// take 15 minutes hang for 38 before it said anything. 25 is about 1.6
	// times the slowest shard expected at three shards, and 5.9 minutes of
	// setup plus 25 plus the uploads still ends about 13 minutes under the
	// job cap. The guarantee is the same one as above: a shard that overruns
	// stops on its own clock and leaves its tally and its report.
	globalTimeout: (SHARDED ? 25 : 38) * 60_000,
	reporter: [
		[
			'html',
			{
				open: 'never',
				outputFolder: path.resolve(__dirname, 'playwright-report'),
			},
		],
		[
			'junit',
			{ outputFile: path.resolve(__dirname, 'test-results', 'results.xml') },
		],
		['list'],
		// Last, so its lines follow the list reporter's tally. It fails the run
		// by name when any test did not run or was interrupted, and names the
		// step every timeout happened in. See the file for why both are needed.
		[path.resolve(__dirname, 'helpers', 'verdict-reporter.ts')],
	],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts. Deliberately NOT
		// `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`: that literal
		// is the SHARED dev container off CI, and this suite both seeds and
		// deletes OpenRegister objects.
		baseURL: BASE_URL,
		// Playwright's `actionTimeout` defaults to 0 — NO limit — so a single
		// `.click()` on a non-actionable element (e.g. a nav leaf hidden inside
		// a collapsed group) blocks until the entire 60s test budget is gone,
		// then reports a bare timeout naming the element rather than the cause.
		// That is what turned this suite into a 45-minute job that CI cancelled
		// after 65 of 122 tests. A bounded action fails in 15s with the same
		// diagnostic and leaves the remaining budget for the real assertions.
		actionTimeout: 15_000,
		// 30s is held because it is measured to fit at four workers. Every
		// `page.goto` in the slowest run's report (354 of them, run
		// 34585313834, a slow runner) finished in at most 27.4s, p99 25.6s,
		// and not one of the 87 four-worker jobs logged a goto timeout. At five
		// workers the same loads ran 1.3x longer and 13 jobs logged 18 of them.
		// When a load does overrun, this is the budget that names its URL.
		navigationTimeout: 30_000,
		// Written by global-setup.ts after the admin login. Path must match
		// `helpers/auth.ts#STORAGE_STATE`, which global-setup imports.
		storageState: path.resolve(__dirname, '.auth', 'user.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count. (The app-root config already used
		// `retain-on-failure`; this file, the one CI actually loads, did not.)
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// The parallel body of the suite: everything except the specs that
			// change state the whole INSTANCE shares.
			testIgnore: [...IGNORED, ...INSTANCE_MUTATING],
			use: { ...devices['Desktop Chrome'] },
			// Sharded only. See the note on the next project.
			...(SHARDED ? { teardown: 'chromium-instance-state' } : {}),
		},
		{
			// 🔴 THE SPECS THAT MUTATE THE INSTANCE, RUN LAST AND ALONE-ISH.
			//
			// `dependencies` makes this project start only once `chromium` has
			// finished, which is the ordering that matters. The hazard is
			// asymmetric: a spec that installs demo data or writes app settings
			// while ~131 empty-state assertions are running elsewhere makes
			// those assertions fail, and it reads as a product defect rather
			// than as a fixture racing them. The reverse order costs nothing.
			//
			// That asymmetry is why this list errs toward INCLUDING a spec.
			// Serialising one that did not need it costs a few seconds at the
			// end of the run; leaving one out costs a failure nobody can
			// reproduce and no diff explains.
			//
			// ⚠️ THE PRICE, STATED RATHER THAN DISCOVERED. Playwright SKIPS a
			// project whose dependency had failures. So while anything in
			// `chromium` is red, these 31 tests report as "did not run" — and a
			// test that never ran reads identically to one that passed in any
			// summary counting failures. That is a real cost and it is the
			// right trade only because a run with failures is red regardless:
			// the verdict is not being hidden, the detail is. Read the tally,
			// not the colour, until the parallel project is green.
			//
			// 🔴 WHY THIS BECOMES A TEARDOWN WHEN THE SUITE IS SHARDED.
			//
			// Playwright never shards a project that another project depends
			// on. It runs a dependency in full on every shard, because it treats
			// it as setup. Here that project is `chromium`, 361 of the 394
			// tests, so `--shard` on the unsharded shape split nothing. Measured
			// with `--list --shard=<i>/4`: every one of the four shards listed
			// all 361 `chromium` tests plus a quarter of these 33. Four runners,
			// each doing the whole job.
			//
			// Dropping the dependency is not an option either. Without it
			// Playwright starts this project as soon as a worker is idle, while
			// `chromium` tests are still running, which is exactly the race the
			// ordering exists to prevent. Measured with a two-project probe: the
			// second project started on the idle worker while the first was
			// still running.
			//
			// A teardown keeps the ordering and lets `chromium` shard. Playwright
			// runs `chromium`'s share of the tests on each shard first and this
			// project after it, on that shard's own instance. Measured with the
			// same probe: the teardown project waited for the first project on
			// every shard, and it ran even when a test in the first project
			// failed, so the "did not run" price described above goes away when
			// sharded.
			//
			// What it costs: these 33 tests run once on EVERY shard, because a
			// teardown is never sharded either. That is the slow direction, not
			// the lossy one. Each shard has its own database, so the mutations
			// cannot reach another shard's assertions. A red test here goes red
			// on every shard, which is loud rather than hidden.
			name: 'chromium-instance-state',
			testIgnore: IGNORED,
			testMatch: INSTANCE_MUTATING,
			...(SHARDED ? {} : { dependencies: ['chromium'] }),
			use: { ...devices['Desktop Chrome'] },
		},
	],
})

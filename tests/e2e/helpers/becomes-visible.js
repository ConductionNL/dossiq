/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A POLLING visibility probe, for use as a `test.skip()` condition.
 *
 * ⚠️ WHY THIS FILE EXISTS — `locator.isVisible()` DOES NOT WAIT.
 * ------------------------------------------------------------------
 * `Locator.isVisible()` is an *immediate* predicate: it answers "is this
 * element visible on this tick". Its `timeout` option is **ignored** — passing
 * `{ timeout: 10000 }` buys nothing at all, which is precisely why Playwright
 * deprecated the option. Only `expect(...).toBeVisible()` and
 * `locator.waitFor()` poll.
 *
 * So this shape, which this repo used in three spec files:
 *
 *     await page.goto('/index.php/apps/procest/workflow-board')
 *     if (!(await heading.isVisible({ timeout: 10000 }).catch(() => false))) {
 *         test.skip(true, 'Workflow Board surface not deployed in target instance')
 *     }
 *
 * asks the question before the SPA has issued a single XHR. It answers "no"
 * essentially always, and the test skips **with a reason that is false**.
 *
 * A SKIP WHOSE STATED REASON IS UNTRUE IS AN INVISIBLE PASS — worse than a
 * stub assertion, because it renders as "not applicable" rather than as a gap,
 * the reason looks investigated, and it inflates the skip count, which is the
 * number that separates a flake from a regression. procest skips 38 of 128.
 *
 * `waitFor` polls. **The skip that survives it is a real one.**
 *
 * ⚠️ AND HERE THEY ALL SURVIVED IT — READ THIS BEFORE REPEATING MY MISTAKE.
 * ------------------------------------------------------------------------
 * An earlier version of this comment asserted that procest's skip reasons were
 * FALSE, reasoning that `/workflow-board` is a declared route in
 * `src/manifest.json`, that `WorkflowBoardView` resolves in `src/registry.js`,
 * and that `WorkflowBoard.vue` renders an **unconditional** `<h2>Workflow
 * Board</h2>`. All three facts are true. **The conclusion drawn from them was
 * not.**
 *
 * Measured: with these polling gates in place, the tally is **unchanged** —
 * 128 collected, 90 passed, 38 skipped, byte-identical to the baseline, with
 * the app's 3.1 MB bundle confirmed served (HTTP 200), so this is not the
 * "e2e suite measuring an unbuilt app" trap either. The elements genuinely do
 * not appear, and the skips are therefore **honest**.
 *
 * 🔑 SOURCE CODE PROVES A SURFACE IS DECLARED. IT DOES NOT PROVE IT RENDERS.
 * A route can be declared, registered, and unreachable — the remaining suspect
 * here is the server-side URL the specs navigate to
 * (`/index.php/apps/procest/workflow-board`) versus where the SPA actually
 * mounts that page. That is an app/routing question, not a Playwright one.
 *
 * So this file's value is NOT that it unskipped anything. It is that the 38
 * skips are now **believable**: each survived a 10–15 s poll, so it reports a
 * real absence rather than a race, and the next person to investigate can
 * trust the number instead of re-deriving it.
 *
 * The `test.skip()` calls are deliberately KEPT: the fix is not to unskip, it
 * is to make the gate tell the truth.
 */

/**
 * Wait up to `timeout` for a locator to become visible; return whether it did.
 *
 * @param {import('@playwright/test').Locator} locator The locator to poll.
 *        `.first()` is applied so a strict-mode violation on a multi-match
 *        selector cannot masquerade as an absence.
 * @param {number} [timeout] Milliseconds to poll for. Default 10s — enough for
 *        a Nextcloud SPA route to mount and fetch.
 * @return {Promise<boolean>} `true` when the element became visible within
 *         `timeout`, else `false`. Never throws.
 */
export async function becomesVisible(locator, timeout = 10000) {
	return await locator
		.first()
		.waitFor({ state: 'visible', timeout })
		.then(() => true)
		.catch(() => false)
}

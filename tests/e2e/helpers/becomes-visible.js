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
 * ⚠️ THE COUNT DID NOT MOVE — AND THAT IS NOT THE SAME AS "THE REASONS WERE TRUE".
 * ---------------------------------------------------------------------------
 * Measured with these polling gates in place: 128 collected, 90 passed,
 * 38 skipped — byte-identical to the baseline. It is worth being precise about
 * what that does and does not show, because I got it wrong twice.
 *
 * I first asserted the reasons were FALSE (reasoning from source: the route is
 * declared, the component resolves, the `<h2>` is unconditional). I then
 * over-corrected and wrote that they were all TRUE, because the count had not
 * moved. **Both were wrong, because the question is PER GATE and the count
 * cannot answer it.** The kanban test has two gates in series:
 *
 *   1. heading "Workflow Board"  → "Workflow Board surface not deployed in
 *                                   target instance"        ← REASON IS FALSE
 *   2. `.case-card`              → "No cases on the Workflow Board to
 *                                   exercise the move control" ← REASON IS TRUE
 *
 * Gate 1's reason is false, and the proof is a SIBLING TEST THAT PASSES IN THE
 * SAME RUN: `spec-coverage/workflow-operations.spec.ts:18` reaches
 * `/index.php/apps/procest/workflow-board` by the identical navigation
 * (`navToRoute` is literally `page.goto('/index.php/apps/procest'+route)` plus
 * the same dialog dismissal) and asserts `.workflow-board__header h2` visible.
 * The board renders.
 *
 * Gate 2's reason is true, and the proof is also a passing test:
 * `workflows/case-lifecycle.spec.ts:185` is the only spec that sees case cards
 * — and it **seeds two cases itself** before looking. This file's specs seed
 * nothing, so there are no cards, and "no cases on the board" is exactly right.
 *
 * 🔑 SO THE SKIP MOVED FROM A FALSE REASON TO A TRUE ONE, AND THE COUNT — the
 * only thing CI reports — COULD NOT SHOW IT. De-racing gate 1 simply hands
 * control to gate 2. A stable skip count is compatible with every reason
 * underneath it changing.
 *
 * 🔑 AND SOURCE CODE PROVES A SURFACE IS DECLARED, NOT THAT IT RENDERS. What
 * settled both gates was not reading the app — it was finding a PASSING TEST
 * that already exercised the same thing. Prefer that evidence.
 *
 * ➡️ FOLLOW-UP, EVIDENCED AND NOT DONE HERE: to actually recover these tests,
 * seed cases the way `case-lifecycle.spec.ts` does rather than hoping the
 * shared fixture has some. That is a fixture change, not a timing change, and
 * it belongs in its own reviewed commit.
 *
 * So this file's value is NOT that it unskipped anything. It is that each
 * surviving skip now reports a real absence rather than a race, so the next
 * person can trust it instead of re-deriving it — as I had to, twice.
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

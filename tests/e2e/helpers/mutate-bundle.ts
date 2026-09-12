/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Break the product on its way to the browser, without touching the disk.
 *
 * WHY THIS EXISTS
 * ---------------
 * A test that asserts a requirement is not finished until somebody has WATCHED
 * it fail. The 2026-09-12 citation re-measurement found thirteen citations that
 * lost ground since the previous reading, and every one of them had looked
 * right: "looks right" is not evidence, and a citation that regressed is one
 * somebody already believed was fixed.
 *
 * For a requirement the CLIENT enforces, the mutation needs no permission and
 * no lock. Playwright's `page.route` can rewrite the served bundle on its way
 * into the browser, so the broken code really runs while nothing on disk
 * changes and no other session's instance moves.
 *
 * 🔴 A ROUTE THAT MATCHED NOTHING LEAVES THE REAL CODE RUNNING, and hands you
 * a green that means nothing at all. That is the same failure mode as a test
 * that never ran. So every mutation below is COUNTED, and a mutation that
 * matched zero times throws by name rather than passing quietly.
 *
 * ⚠️ THE BUNDLE IS MINIFIED. Identifiers are renamed on every rebuild, so a
 * mutation anchored on `go(lo.comparedOn, …)` stops matching the next time
 * anybody builds. Anchor on the parts webpack does NOT rewrite — method names
 * declared in an object literal, string literals, property names — and capture
 * the minified identifiers with a group instead of writing them out.
 *
 * USAGE
 *
 *     await mutateBundle(page, [{
 *         label: 'readingDateText() states no date',
 *         find: /readingDateText\(\)\{const (\w+)=\w+\(\w+\.comparedOn,this\.locale\)/,
 *         replace: 'readingDateText(){const $1=""',
 *     }])
 *     await page.goto(…)   // MUST come after the route is installed
 */
import type { Page } from '@playwright/test'

/** One edit to make to the served bundle. */
export interface BundleMutation {
	/** What this break is, quoted in the error when it fails to match. */
	label: string
	/** The code to find. Applied globally; the `g` flag is added for you. */
	find: RegExp
	/** The replacement, with `$1`-style back-references. */
	replace: string
}

/**
 * The bundles a mutation may address.
 *
 * Deliberately every `.js` under the app's `js/` directory rather than
 * `dossiq-main.js` alone: settings, the widgets and the leaves are separate
 * chunks, and a requirement enforced in one of those is no less worth
 * breaking. The count assertion is what keeps the wider net honest — a
 * mutation that matches in none of them still throws.
 */
const BUNDLE_URL = /\/(?:custom_apps|apps)\/dossiq\/js\/[^/?]+\.js(?:\?|$)/

/** Handle returned by {@link mutateBundle}, so the test can read the counts back. */
export interface AppliedMutations {
	/**
	 * Throw unless every mutation matched at least once.
	 *
	 * Call this AFTER the navigation that loads the bundle. `page.route` runs
	 * off the test's call stack, so a throw inside the handler is swallowed
	 * and the run would report a meaningless green; reading the counts back
	 * here puts the failure in front of the test.
	 *
	 * @return Nothing; throws naming every mutation that matched nothing.
	 */
	assertApplied(): void
	/** How many times each mutation matched, by label. */
	counts(): Record<string, number>
}

/**
 * Rewrite the app's JavaScript bundles as the browser fetches them.
 *
 * Install this BEFORE the `page.goto` whose load should run the broken code.
 *
 * @param page The page to install the route on.
 * @param mutations The edits to apply, in order.
 * @return A handle whose `assertApplied()` fails when a mutation matched nothing.
 */
export async function mutateBundle(
	page: Page,
	mutations: BundleMutation[],
): Promise<AppliedMutations> {
	const hits = new Map<string, number>(mutations.map((m) => [m.label, 0]))

	await page.route(BUNDLE_URL, async (route) => {
		const response = await route.fetch()
		let body = await response.text()
		for (const mutation of mutations) {
			const pattern = new RegExp(
				mutation.find.source,
				mutation.find.flags.includes('g')
					? mutation.find.flags
					: `${mutation.find.flags}g`,
			)
			let matched = 0
			body = body.replace(pattern, (...args) => {
				matched++
				// `String.replace` hands the replacement string the same
				// back-references it would in the two-argument form; rebuilding
				// it by hand here is what keeps `$1` working.
				return mutation.replace.replace(/\$(\d+)/g, (_, n) =>
					String(args[Number(n)] ?? ''),
				)
			})
			hits.set(mutation.label, (hits.get(mutation.label) ?? 0) + matched)
		}
		await route.fulfill({
			response,
			body,
			headers: {
				...response.headers(),
				// The rewritten body is a different length, and a stale
				// content-length truncates it into a syntax error that reads
				// like a product defect.
				'content-length': String(Buffer.byteLength(body)),
			},
		})
	})

	return {
		counts: () => Object.fromEntries(hits),
		assertApplied() {
			const missed = [...hits.entries()]
				.filter(([, n]) => n === 0)
				.map(([label]) => label)
			if (missed.length > 0) {
				throw new Error(
					'[mutate-bundle] these mutations matched nothing, so the real '
						+ 'code ran and any green from this run is meaningless: '
						+ missed.join(', '),
				)
			}
		},
	}
}

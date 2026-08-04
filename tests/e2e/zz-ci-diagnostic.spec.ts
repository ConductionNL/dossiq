/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TEMPORARY CI diagnostic — round 2. Round 1 established that the app mounts
 * cleanly on a runner (Vue mounted, 31 nav links, dashboard header + widgets
 * render, zero procest 4xx). It also showed the real problem: most nav leaves
 * report `visible:false` because they sit inside COLLAPSED groups, so
 * `navTo()`'s `.click()` — with no actionTimeout configured — blocks for the
 * whole 60s test budget.
 *
 * Round 2 answers the question that decides the fix: does a DIRECT deep link
 * render the target view? `helpers/nav.ts` asserts it does not ("a cold deep
 * link resets the history-mode router to the Dashboard"). If that claim is
 * stale, navTo can just goto the href and the entire hidden-group failure
 * class disappears.
 *
 * Delete before merge.
 */

import { test, expect } from '@playwright/test'

const ROUTES = [
	'/index.php/apps/procest/cases',
	'/index.php/apps/procest/my-work',
	'/index.php/apps/procest/doorlooptijd',
	'/index.php/apps/procest/tasks',
]

test('DIAGNOSTIC: do direct deep links render their view?', async ({ page }) => {
	test.setTimeout(180_000)

	for (const route of ROUTES) {
		await page.goto(route, { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(8000)

		const headings = await page.getByRole('heading').evaluateAll(
			(els) => els.map((e) => `${e.tagName}:${(e.textContent || '').trim().replace(/\s+/g, ' ')}`).slice(0, 12),
		)
		const radios = await page.getByRole('radio').evaluateAll(
			(els) => els.map((e) => (e.getAttribute('aria-label') || e.getAttribute('value') || (e.textContent || '').trim())),
		)
		const buttons = await page.getByRole('button').evaluateAll(
			(els) => els.map((e) => (e.textContent || '').trim().replace(/\s+/g, ' ')).filter(Boolean).slice(0, 18),
		)
		console.log(`\n##### ROUTE ${route}`)
		console.log('  final url :', page.url())
		console.log('  headings  :', JSON.stringify(headings))
		console.log('  radios    :', JSON.stringify(radios))
		console.log('  buttons   :', JSON.stringify(buttons))
		console.log('  searchbox :', await page.getByPlaceholder('Type to search').count())
	}

	// Can a collapsed group be expanded by clicking its toggle, making its
	// leaves visible? This is the alternative fix if deep links do not work.
	await page.goto('/index.php/apps/procest', { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(8000)
	const nav = page.locator('[id^="app-navigation"]').first()
	const before = await nav.getByRole('link', { name: 'My work', exact: true }).isVisible().catch(() => 'err')
	await nav.getByRole('link', { name: 'Work queue', exact: true }).click({ timeout: 10000 }).catch((e) => console.log('  group click failed:', String(e).slice(0, 200)))
	await page.waitForTimeout(1500)
	const after = await nav.getByRole('link', { name: 'My work', exact: true }).isVisible().catch(() => 'err')
	console.log('\n##### GROUP EXPANSION: "My work" visible before =', before, ' after clicking "Work queue" =', after)

	expect(true).toBe(true)
})

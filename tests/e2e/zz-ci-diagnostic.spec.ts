/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TEMPORARY CI diagnostic — round 3. Probes every route behind the remaining
 * 51 failures and dumps the controls each one actually renders, so the specs
 * can be corrected against measured output instead of guessed labels.
 *
 * Delete before merge.
 */

import { test, expect } from '@playwright/test'

const ROUTES = [
	'/settings',
	'/settings/parafeerroutes',
	'/settings/automatic-actions',
	'/settings/lhs-matrices',
	'/settings/lhs-recommendations',
	'/settings/partners',
	'/settings/wms-layers',
	'/settings/workflow-definitions',
	'/settings/tenants',
	'/settings/locations',
	'/settings/bezwaar-committees',
	'/bezwaren',
	'/beroepen',
	'/voorstellen',
	'/advice',
	'/workflow-board',
	'/map',
	'/tasks',
	'/verwerkingen',
	'/features-roadmap',
	'/subsidieregelingen',
]

test('DIAGNOSTIC: dump controls for every failing route', async ({ page }) => {
	test.setTimeout(600_000)

	for (const route of ROUTES) {
		const url = `/index.php/apps/procest${route}`
		const bad: string[] = []
		const onResp = (r: import('@playwright/test').Response) => {
			if (r.status() >= 400) bad.push(`${r.status()} ${r.url().replace('http://localhost:8080', '')}`)
		}
		page.on('response', onResp)
		await page.goto(url, { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(6000)

		const buttons = await page.getByRole('button').evaluateAll(
			(els) => els.map((e) => (e.textContent || '').trim().replace(/\s+/g, ' ')).filter(Boolean).slice(0, 25),
		)
		const headings = await page.getByRole('heading').evaluateAll(
			(els) => els.map((e) => `${e.tagName}:${(e.textContent || '').trim().replace(/\s+/g, ' ')}`).slice(0, 10),
		)
		console.log(`\n##### ${route}`)
		console.log('  url      :', page.url().replace('http://localhost:8080', ''))
		console.log('  headings :', JSON.stringify(headings))
		console.log('  buttons  :', JSON.stringify(buttons))
		console.log('  http4xx  :', JSON.stringify([...new Set(bad)].slice(0, 6)))
		page.off('response', onResp)
	}

	expect(true).toBe(true)
})

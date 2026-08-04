/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TEMPORARY CI diagnostic — dumps what the procest app actually renders on a
 * GitHub runner. Everything it prints goes to stdout, so it lands in the job
 * log directly and needs no artifact (the previous run was cancelled at the
 * 45-minute cap before any report was written).
 *
 * Delete before merge.
 */

import { test, expect } from '@playwright/test'

test('DIAGNOSTIC: dump rendered navigation and app state', async ({ page }) => {
	test.setTimeout(120_000)

	const consoleErrors: string[] = []
	page.on('console', (m) => {
		if (m.type() === 'error') consoleErrors.push(m.text())
	})
	const failed: string[] = []
	page.on('requestfailed', (r) => failed.push(`${r.method()} ${r.url()} :: ${r.failure()?.errorText}`))
	const bad: string[] = []
	page.on('response', (r) => {
		if (r.status() >= 400) bad.push(`${r.status()} ${r.url()}`)
	})

	await page.goto('/index.php/apps/procest', { waitUntil: 'domcontentloaded' })
	// Give the SPA a generous, FIXED budget to mount — no selector waits, so
	// this can never itself become a silent timeout.
	await page.waitForTimeout(20_000)

	console.log('=== URL ===', page.url())

	const navCount = await page.locator('[id^="app-navigation"]').count()
	console.log('=== app-navigation elements:', navCount)

	const links = await page.locator('[id^="app-navigation"] a').evaluateAll(
		(els) => els.map((e) => ({
			text: (e.textContent || '').trim().replace(/\s+/g, ' '),
			href: e.getAttribute('href'),
			visible: !!(e as HTMLElement).offsetParent,
		})),
	)
	console.log('=== NAV LINKS (' + links.length + ') ===')
	for (const l of links) console.log(JSON.stringify(l))

	// Is the Vue app mounted at all?
	const mounted = await page.evaluate(() => {
		const el = document.getElementById('content') as (HTMLElement & { __vue_app__?: unknown }) | null
		return { contentExists: !!el, vueApp: !!(el && el.__vue_app__) }
	})
	console.log('=== VUE MOUNT ===', JSON.stringify(mounted))

	// Support dialog present? (its mask is documented to eat nav clicks)
	const dlg = page.locator('[data-testid-modal="cn-support-dialog"]')
	console.log('=== support dialog count/visible:', await dlg.count(), await dlg.isVisible().catch(() => 'err'))

	// Any modal mask overlaying the page?
	const masks = await page.locator('.modal-mask, .modal-wrapper').count()
	console.log('=== modal masks:', masks)

	console.log('=== CONSOLE ERRORS (' + consoleErrors.length + ') ===')
	for (const e of consoleErrors.slice(0, 40)) console.log('  ', e)
	console.log('=== FAILED REQUESTS (' + failed.length + ') ===')
	for (const e of failed.slice(0, 40)) console.log('  ', e)
	console.log('=== HTTP >=400 (' + bad.length + ') ===')
	for (const e of bad.slice(0, 40)) console.log('  ', e)

	const body = await page.locator('body').innerText().catch(() => '')
	console.log('=== BODY TEXT (first 3000) ===')
	console.log(body.slice(0, 3000))

	expect(true).toBe(true)
})

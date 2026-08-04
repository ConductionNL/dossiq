/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TEMPORARY CI diagnostic — round 4. Probes the last eight failures so they
 * can be fixed against measured output rather than quarantined on a guess.
 *
 * Delete before merge.
 */

import { test, expect } from '@playwright/test'
import { getRequestToken, ensureCaseType, seedCase, objectId } from './helpers/fixtures'
import { STORAGE_STATE } from './helpers/auth'
import { request as pwRequest } from '@playwright/test'

test('DIAGNOSTIC: last-mile probes', async ({ page, baseURL }) => {
	test.setTimeout(600_000)

	// ---- 1. /subsidies : what create control does it render? ----
	await page.goto('/index.php/apps/procest/subsidies', { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(8000)
	console.log('\n##### /subsidies')
	console.log('  url     :', page.url().replace('http://localhost:8080', ''))
	console.log('  buttons :', JSON.stringify(await page.getByRole('button').evaluateAll((e) => e.map((x) => (x.textContent || '').trim().replace(/\s+/g, ' ')).filter(Boolean).slice(0, 25))))
	console.log('  headings:', JSON.stringify(await page.getByRole('heading').evaluateAll((e) => e.map((x) => (x.textContent || '').trim()).slice(0, 8))))

	// ---- 2. /cases : is there a <table> by default, and what is the scroll container? ----
	await page.goto('/index.php/apps/procest/cases', { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(8000)
	console.log('\n##### /cases default view')
	console.log('  table count      :', await page.locator('table').count())
	console.log('  role=table count :', await page.locator('[role="table"]').count())
	console.log('  .viewTable count :', await page.locator('.viewTable').count())
	console.log('  card-ish count   :', await page.locator('[class*="card"]').count())

	// ---- 3. in-app /settings : does .settings-form ever appear, and what scrolls? ----
	await page.goto('/index.php/apps/procest/settings', { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(10000)
	console.log('\n##### /settings (in-app)')
	console.log('  .settings-form count:', await page.locator('.settings-form').count())
	const scrollers = await page.evaluate(() => {
		const out: string[] = []
		document.querySelectorAll('*').forEach((el) => {
			const e = el as HTMLElement
			if (e.scrollHeight > e.clientHeight + 50 && e.clientHeight > 200) {
				out.push(`${e.tagName}.${(e.className || '').toString().slice(0, 40)} sh=${e.scrollHeight} ch=${e.clientHeight}`)
			}
		})
		return out.slice(0, 8)
	})
	console.log('  scrollable containers:', JSON.stringify(scrollers))
	console.log('  headings:', JSON.stringify(await page.getByRole('heading').evaluateAll((e) => e.map((x) => `${x.tagName}:${(x.textContent || '').trim()}`).slice(0, 12))))
	// Scroll the real container and re-check.
	await page.evaluate(() => {
		document.querySelectorAll('*').forEach((el) => {
			const e = el as HTMLElement
			if (e.scrollHeight > e.clientHeight + 50 && e.clientHeight > 200) e.scrollTop = e.scrollHeight
		})
	})
	await page.waitForTimeout(3000)
	console.log('  AFTER inner scroll -> .settings-form count:', await page.locator('.settings-form').count())
	console.log('  AFTER inner scroll -> headings:', JSON.stringify(await page.getByRole('heading').evaluateAll((e) => e.map((x) => `${x.tagName}:${(x.textContent || '').trim()}`).slice(0, 14))))

	// ---- 4. case detail : is the assigned identifier rendered anywhere? ----
	const api = await pwRequest.newContext({ baseURL, storageState: STORAGE_STATE })
	const token = await getRequestToken(api)
	const ct = await ensureCaseType(api, token)
	const kase = await seedCase(api, token, { title: 'DIAG detail case', caseType: ct.id, identifier: 'DIAG-1', description: 'diag' })
	const id = objectId(kase)
	const assigned = String((kase as Record<string, unknown>).identifier ?? '')
	console.log('\n##### case detail')
	console.log('  assigned identifier:', assigned)
	await page.goto(`/index.php/apps/procest/cases/${id}`, { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(10000)
	const body = await page.locator('body').innerText().catch(() => '')
	console.log('  identifier present in body text:', body.includes(assigned))
	console.log('  body (first 1200):')
	console.log(body.slice(0, 1200))
	await api.dispose()

	expect(true).toBe(true)
})

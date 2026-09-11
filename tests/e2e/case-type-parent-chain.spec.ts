/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two save-time rules of a case type that derives from a parent
 * (REQ-CT-20): a parent chain that returns to itself is refused when you
 * save it, and a case of a child that sets no processing deadline of its own
 * is due on its parent's term.
 *
 * WHY THESE NEED A BROWSER
 * ------------------------
 * `CaseTypeParentCycleListenerTest` and `CaseInheritedDeadlineListenerTest`
 * pin both rules in PHP. What they cannot show is that the rule is on the
 * path a person actually takes. Before these listeners the refusal existed
 * and only the publish path called it: the case type's Edit dialog saves
 * through OpenRegister's generic object API, so the loop was stored and the
 * dialog closed as if nothing had happened. And the deadline was computed by
 * a declarative calculation that reads only the case type the case points
 * at, so a case of a silent child had no deadline at all while every unit of
 * the resolver was green.
 *
 * LOCALE
 * ------
 * The refusal message is translated. The assertion is on the loop it names,
 * `A -> B -> A`, built from titles this spec seeded, which reads the same in
 * either language.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

let api: APIRequestContext
let token = ''

/** Bezwaar and its two children: one that overrides nothing, one for the loop. */
const PARENT_TITLE = `${RUN_PREFIX} Bezwaar`
const LOOP_CHILD_TITLE = `${RUN_PREFIX} Bezwaar (verkort)`
const SILENT_CHILD_TITLE = `${RUN_PREFIX} Bezwaar (standaard)`

const ids = { parent: '', loopChild: '', silentChild: '', intake: '' }

/**
 * Add a day count to an ISO date, in UTC so no timezone shifts the answer.
 *
 * @param isoDate A `YYYY-MM-DD` date.
 * @param days    How many days to add.
 * @return The resulting `YYYY-MM-DD`.
 */
function addDays(isoDate: string, days: number): string {
	const date = new Date(`${isoDate.slice(0, 10)}T00:00:00Z`)
	date.setUTCDate(date.getUTCDate() + days)
	return date.toISOString().slice(0, 10)
}

/**
 * Pick one option in a relation select, by the option's text.
 *
 * By TEXT, not by accessible name: vue-select splits an option label into
 * spans and the computed name gains a space a reader never sees. Opening the
 * picker preloads a capped first page, so the run prefix is typed only when
 * this run's option is not on it; typing replaces the preloaded options.
 *
 * @param page   The Playwright page.
 * @param field  The field wrapper holding the select.
 * @param title  The option's text.
 */
async function pickRelation(
	page: Page,
	field: Locator,
	title: string,
): Promise<void> {
	const combo = field.getByRole('combobox')
	await combo.click()

	const option = page
		.getByRole('option')
		.filter({
			hasText: new RegExp(
				`^\\s*${title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`,
			),
		})
	if (!(await option.isVisible().catch(() => false))) {
		await combo.pressSequentially(RUN_PREFIX, { delay: 30 })
	}
	await expect(option).toBeVisible({ timeout: 20_000 })
	await option.click()
}

test.describe('A case type that derives from a parent', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// PUBLISHED throughout: `case.caseType` filters the New case picker on
		// `isDraft: false`, and the schema defaults the flag to true.
		ids.parent = objectId(
			await createObject(api, token, 'caseType', {
				title: PARENT_TITLE,
				identifier: `${RUN_PREFIX.toLowerCase()}-cpc-parent`,
				description: 'Throwaway parent caseType for case-type-parent-chain.',
				processingDeadline: 'P12W',
				isDraft: false,
			}),
		)
		ids.intake = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} Bezwaar ontvangen`,
				caseType: ids.parent,
				order: 1,
				isFinal: false,
			}),
		)
		await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} Bezwaar afgehandeld`,
			caseType: ids.parent,
			order: 2,
			isFinal: true,
		})
		await updateObject(api, token, 'caseType', ids.parent, {
			initialStatus: ids.intake,
		})

		ids.loopChild = objectId(
			await createObject(api, token, 'caseType', {
				title: LOOP_CHILD_TITLE,
				identifier: `${RUN_PREFIX.toLowerCase()}-cpc-verkort`,
				description: 'Throwaway child caseType for case-type-parent-chain.',
				parentCaseType: ids.parent,
				processingDeadline: 'P6W',
				isDraft: false,
			}),
		)

		// Names the parent and sets NO processing deadline: the one thing
		// this child is for is inheriting it.
		ids.silentChild = objectId(
			await createObject(api, token, 'caseType', {
				title: SILENT_CHILD_TITLE,
				identifier: `${RUN_PREFIX.toLowerCase()}-cpc-standaard`,
				description: 'Throwaway child caseType for case-type-parent-chain.',
				parentCaseType: ids.parent,
				isDraft: false,
			}),
		)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e case-types::a-cycle-is-refused
	test('setting a parent that descends from the type is refused on save, naming the loop', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/settings/case-types/${ids.parent}`, {
			timeout: 60_000,
		})
		await dismissSupportDialog(page)
		const detail = page.locator('.cn-detail-page')
		await expect(detail).toBeVisible({ timeout: 30_000 })

		await detail
			.getByRole('button', { name: /^(Edit|Bewerken)$/ })
			.first()
			.click()
		// The ONE dialog on the page, not one filtered on its fields: on a
		// refusal the form dialog swaps its fields for the error, so a
		// locator that required the parent field would lose the dialog at
		// the exact moment it has something to say.
		const dialog = page.getByRole('dialog')
		const parentField = dialog.locator('[data-cn-field="parentCaseType"]')
		await expect(parentField).toBeVisible({ timeout: 30_000 })

		// WHEN you set Bezwaar's parent to Bezwaar (verkort) and save.
		await pickRelation(page, parentField, LOOP_CHILD_TITLE)
		await dialog.getByRole('button', { name: /^(Save|Opslaan)$/ }).click()

		// THEN the save fails with a message naming the cycle. The dialog
		// stays open on the refusal: a dialog that closed would mean the save
		// went through, or that the refusal was thrown away.
		const loop = `${PARENT_TITLE} -> ${LOOP_CHILD_TITLE} -> ${PARENT_TITLE}`
		await expect(dialog).toContainText(loop, { timeout: 30_000 })
		await expect(dialog).toBeVisible()
		// The sentence, and not a machine code printed after it.
		await expect(dialog).not.toContainText('caseType.parentCycle')

		// And nothing was stored: the loop exists only in the dialog.
		const stored = await showObject(api, 'caseType', ids.parent)
		expect(String(stored.parentCaseType ?? '')).toBe('')
	})

	// @e2e case-types::a-child-inherits-a-deadline-it-does-not-declare
	test('a case filed on a child that sets no deadline is due on its parent’s term', async ({
		page,
	}) => {
		const title = `${RUN_PREFIX} Bezwaar zonder eigen termijn`

		await page.goto(`/apps/${REGISTER}/`, { timeout: 60_000 })
		await dismissSupportDialog(page)
		await page
			.getByRole('button', { name: /^(New case|Nieuwe zaak)$/ })
			.click({ timeout: 30_000 })
		// The dialog ROOT: NcDialog renders its buttons in a footer beside the
		// div that carries the test id.
		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 30_000 })

		// The case type FIRST: choosing one prefills the title with the type's.
		await pickRelation(
			page,
			dialog.locator('[data-cn-field="caseType"]'),
			SILENT_CHILD_TITLE,
		)
		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(title)
		await dialog.getByRole('button', { name: /^(Create|Aanmaken)$/ }).click()

		let filed: any = null
		await expect(async () => {
			const rows = await listObjects(api, 'case', {
				caseType: ids.silentChild,
			})
			filed = rows.find((row) => String(row.title ?? '') === title) ?? null
			expect(filed, 'the case should have been filed').toBeTruthy()
		}).toPass({ timeout: 30_000 })

		// THEN the deadline is twelve weeks after the start date: the
		// parent's term, which the child never states.
		const stored = await showObject(api, 'case', objectId(filed))
		const startDate = String(stored.startDate ?? '')
		expect(startDate, 'a filed case should carry a start date').toMatch(
			/^\d{4}-\d{2}-\d{2}/,
		)
		expect(
			String(stored.deadline ?? '').slice(0, 10),
			'the deadline should count the parent’s twelve weeks from the start date',
		).toBe(addDays(startDate, 84))
	})
})

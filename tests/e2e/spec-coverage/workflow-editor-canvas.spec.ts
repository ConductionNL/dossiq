/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the visual workflow editor
 * (`workflow-editor-integration`): the case type's Workflow tab renders
 * the canonical canvas (`WorkflowEditor.vue`, the single surviving
 * editor after the dead `@vue-flow`-based duplicate was deleted), and its
 * keyboard-operable controls (WCAG 2.1.1 Keyboard) work.
 *
 * ⚠️ WHY THIS FILE SEEDS ITS OWN CASE TYPE
 * ----------------------------------------
 * All three tests used to skip on every run, for two reasons that stacked.
 *
 *  1. The row locator was `.viewTableRow, tr[role="row"], .list-item`.
 *     CnDataTable renders `<tr class="cn-table-row" data-testid="cn-object-row">`,
 *     so nothing matched whatever the data, and the skip said "No case types
 *     in the deployed/seeded register", which was never the reason.
 *  2. Even a matching row could not open. CaseTypeList passed `selectable`
 *     without `rowClickToView`, so a row-body click only ticked its checkbox
 *     and `@rowClick` never fired. That is fixed in CaseTypeList.vue.
 *
 * With both gone there is no honest skip left, so the tests now seed what
 * they read and ASSERT, with no escape hatch. `seedStateMachine()` gives a
 * throwaway case type with three ordered statusTypes and an active
 * workflowTemplate carrying two transitions, which is exactly the canvas the
 * "open the editor" scenario describes. Every seeded title carries
 * `RUN_PREFIX`, so the row found is this run's and never another run's or
 * the instance's demo data.
 *
 * Owning the case type also changes what may be clicked. The old header
 * called this file "deliberately non-destructive" because it drove the FIRST
 * case type on a shared register, where adding a status node persists a real
 * `statusType` immediately (`WorkflowEditor.vue::onAddStatusKeyboard()` saves
 * it before the template is saved). On a throwaway type that write is safe,
 * so the "Add status node" test now activates the button and proves the
 * statusType lands, and teardown removes it with the rest.
 *
 * The open-edit-save-reopen round trip and blocked-save-on-invalid behaviour
 * are proven directly against the real component in
 * `tests/vitest/workflowEditorSmoke.spec.js` (renders a definition,
 * `validate()` blocks the exact gate `WorkflowTab.vue::publish()` calls)
 * and `tests/vitest/workflowGraphValidation.spec.js` (every validation
 * rule + a serialization round-trip).
 *
 * Note: the case-type admin surface is NOT a dossiq route. It lives under
 * Nextcloud's own /settings/admin/dossiq page (AdminRoot -> CaseTypeAdmin,
 * which swaps CaseTypeList for CaseTypeDetail when a row opens).
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { StateMachine } from '../helpers/fixtures.ts'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	getRequestToken,
	listObjects,
	objectId,
	purgeObject,
	RUN_PREFIX,
	seedStateMachine,
} from '../helpers/fixtures.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

let api: APIRequestContext
let token: string
let machine: StateMachine

/**
 * Every statusType that belongs to the seeded case type, seeded or not.
 *
 * Filtered on the client as well as in the query. OpenRegister treats an
 * unknown query parameter as a field filter, but a filter it ignored would
 * hand back every statusType on the instance, and teardown purges what this
 * returns. The client-side check is what keeps that from being a sweep of
 * other runs' data.
 *
 * @param caseTypeId The case type the rows must point at.
 * @return The statusType rows of that case type.
 */
async function statusTypesOf(caseTypeId: string): Promise<any[]> {
	const rows = await listObjects(api, 'statusType', { caseType: caseTypeId })
	return rows.filter(
		(row: any) => String(row.caseType?.id ?? row.caseType ?? '') === caseTypeId,
	)
}

/**
 * Open the seeded case type from the admin list and bring up its Workflow
 * tab.
 *
 * Each step asserts rather than skips. The seeded type is known to exist, so
 * a missing row, a row that does not open, or a tab without a canvas is a
 * defect in the surface, and it should read as one.
 *
 * @param page The page.
 * @return The `.workflow-editor` root of the seeded type's canvas.
 */
async function openSeededWorkflowTab(page: Page): Promise<Locator> {
	await page.goto(ADMIN_SETTINGS_URL)
	await expect(
		page.getByRole('heading', { name: 'Case Type Management' }),
	).toBeVisible({ timeout: 60_000 })

	// By title and by the row's testid. `.first()` on an unfiltered row list
	// would open whichever case type the server happened to return first.
	const row = page
		.locator('[data-testid="cn-object-row"]')
		.filter({ hasText: machine.caseTypeTitle })
	await expect(row, 'the seeded case type is listed').toHaveCount(1, {
		timeout: 120_000,
	})
	// On the title cell, not the row centre: the row also holds a checkbox
	// cell and an actions cell, both of which stop the click from reaching
	// the row, and which one sits under the centre depends on the columns.
	await row.getByText(machine.caseTypeTitle).first().click()

	// The detail header names the type, so this proves the RIGHT row opened
	// rather than that some detail view rendered.
	await expect(
		page.locator('.case-type-detail__title'),
		'a row click opens that case type',
	).toHaveText(machine.caseTypeTitle, { timeout: 60_000 })

	await page.locator('.case-type-detail__tab', { hasText: 'Workflow' }).click()
	const editor = page.locator('.workflow-editor')
	await expect(editor).toBeVisible({ timeout: 60_000 })
	return editor
}

/**
 * The canvas node of one seeded status, by the accessible name the node
 * carries (`Status: {name}`). Exact, so the node's own actions menu, which
 * is named "Actions for status {name}", can never be the match.
 *
 * @param editor The `.workflow-editor` root.
 * @param suffix The seeded status name after `RUN_PREFIX`.
 * @return The node locator.
 */
function seededNode(editor: Locator, suffix: string): Locator {
	return editor.getByRole('button', {
		name: `Status: ${RUN_PREFIX} ${suffix}`,
		exact: true,
	})
}

test.describe('Visual workflow editor canvas (workflow-editor-integration)', () => {
	// The admin page mounts fourteen CnSettingsSections that each query
	// OpenRegister on mount, measured between ~7s and 3.2m on the CI
	// `php -S` server (see spec-coverage/admin-settings.spec.ts, which sets
	// the same budget for the same reason).
	test.setTimeout(300_000)

	test.beforeAll(async ({ baseURL }) => {
		test.setTimeout(120_000)
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		// caseType + Ontvangen / In behandeling / Afgehandeld (final) + an
		// active, published workflowTemplate with transitions t1 and t2.
		machine = await seedStateMachine(api, token)
	})

	test.afterAll(async () => {
		test.setTimeout(120_000)
		// Children before the case type. The statusTypes the "Add status node"
		// test created carry no RUN_PREFIX (the editor names them "New
		// status"), so a prefix sweep would miss them and leave rows pointing
		// at a case type that is gone, which is the dangling-reference class
		// that reddened spec-coverage/ui-pages.spec.ts on a second run. They
		// are found by their case type instead, and go first.
		//
		// Failures are collected rather than thrown, so a list that errors
		// still lets the seeded rows go, and everything is reported at the end.
		const failures: string[] = []
		const seeded = new Set(machine?.created.map(([, id]) => id) ?? [])
		const added: Array<[string, string]> = machine
			? (
					await statusTypesOf(machine.caseTypeId).catch(
						(error: unknown) => {
							failures.push(`list added statusTypes: ${String(error)}`)
							return []
						},
					)
				)
					.map((row: any) => objectId(row))
					.filter((id: string) => id !== '' && seeded.has(id) === false)
					.map((id: string) => ['statusType', id])
			: []
		// `created` is in creation order, parent first, so reversed it is
		// template, statuses, then the case type.
		const order = [...added, ...[...(machine?.created ?? [])].reverse()]

		for (const [schema, id] of order) {
			const gone = await purgeObject(api, token, schema, id).catch(
				(error: unknown) => {
					failures.push(`${schema}/${id}: ${String(error)}`)
					return true
				},
			)
			if (gone === false) failures.push(`${schema}/${id} survived`)
		}
		await api?.dispose()
		expect(
			failures,
			'teardown did not finish, so the next run starts dirty',
		).toEqual([])
	})

	// @e2e openspec/specs/visual-workflow-editor/spec.md#scenario-open-workflow-editor-for-a-case-type
	test('the Workflow tab renders the canonical canvas with the configured nodes and transitions', async ({
		page,
	}) => {
		const editor = await openSeededWorkflowTab(page)

		// The canvas, not the "no workflow defined yet" empty state. The old
		// assertion accepted either, because it drove an arbitrary case type
		// that might have no template. This one has an active template, so an
		// empty state here means the tab failed to find it.
		await expect(page.locator('.workflow-tab__empty')).toHaveCount(0)

		// "a canvas showing all configured status nodes": each seeded status
		// by name, and no others, since the editor lists only this type's.
		await expect(seededNode(editor, 'Ontvangen')).toBeVisible({
			timeout: 30_000,
		})
		await expect(seededNode(editor, 'In behandeling')).toBeVisible()
		await expect(seededNode(editor, 'Afgehandeld')).toBeVisible()
		await expect(editor.locator('.workflow-node')).toHaveCount(3)

		// "...and their transitions": the template carries t1 and t2, drawn
		// as one arrow group each on the SVG layer.
		await expect(editor.locator('.workflow-transition')).toHaveCount(2)
	})

	// @e2e openspec/specs/visual-workflow-editor/spec.md#scenario-keyboard-node-selection
	test('a status node on the canvas is keyboard-focusable and exposes a keyboard-operable actions menu', async ({
		page,
	}) => {
		const editor = await openSeededWorkflowTab(page)
		const received = seededNode(editor, 'Ontvangen')
		const inProgress = seededNode(editor, 'In behandeling')
		await expect(received).toBeVisible({ timeout: 30_000 })

		await expect(received).toHaveAttribute('role', 'button')
		await expect(received).toHaveAttribute('tabindex', '0')

		// "presses Enter or Space ... selected exactly as a mouse click would
		// select it". Both keys, on two nodes, so the second press also proves
		// the selection MOVES rather than piling up.
		await received.focus()
		await page.keyboard.press('Enter')
		await expect(received).toHaveClass(/workflow-node--selected/)
		await inProgress.focus()
		await page.keyboard.press('Space')
		await expect(inProgress).toHaveClass(/workflow-node--selected/)
		await expect(received).not.toHaveClass(/workflow-node--selected/)

		// The actions menu (Connect to…/Disconnect from…/Add step/Delete
		// status) is a separate focusable control from the node's own
		// select handler. Open it via keyboard alone and verify at least one
		// visible-text menu item renders, then cancel without selecting:
		// "Delete status" is one of the items, and this test is about
		// reaching the menu, not about what its items do.
		const actionsTrigger = received
			.locator('.workflow-node__actions button')
			.first()
		await expect(actionsTrigger).toBeVisible()
		await actionsTrigger.focus()
		await page.keyboard.press('Enter')
		const firstMenuItem = page.getByRole('menuitem').first()
		await expect(firstMenuItem).toBeVisible({ timeout: 5000 })
		await page.keyboard.press('Escape')
	})

	// @e2e openspec/specs/visual-workflow-editor/spec.md#scenario-keyboard-add-status-node
	test('the palette "Add status node" button adds a status from the keyboard', async ({
		page,
	}) => {
		const editor = await openSeededWorkflowTab(page)
		const nodes = editor.locator('.workflow-node')
		await expect(nodes).toHaveCount(3, { timeout: 30_000 })

		// Keyboard only: focus the native <button>, then Enter. A click would
		// prove the mouse path, which the drag-and-drop gesture already is.
		const addStatusButton = editor.getByRole('button', {
			name: 'Add status node',
		})
		await expect(addStatusButton).toBeVisible()
		await addStatusButton.focus()
		await expect(addStatusButton).toBeFocused()
		await page.keyboard.press('Enter')

		// "a new StatusType SHALL be created ... placed at a default
		// position": a fourth node on the canvas, and a fourth statusType
		// row on the case type. The read-back is what separates a persisted
		// status from a node the editor only drew.
		await expect(nodes).toHaveCount(4, { timeout: 30_000 })
		const seeded = new Set(machine.created.map(([, id]) => id))
		await expect
			.poll(
				async () =>
					(await statusTypesOf(machine.caseTypeId)).filter(
						(row: any) => seeded.has(objectId(row)) === false,
					).length,
				{ timeout: 30_000, message: 'the new statusType must persist' },
			)
			.toBe(1)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The archive gate on the case page, asked of the library's own evaluator.
 *
 * `caseActionsMenu.spec.js` reads the DECLARATION of the seven gated header
 * actions. That is all it ever did, and the declaration was `eq null` for
 * weeks: it read correct and hid all seven on every working case. A gate is
 * only tested by evaluating it against the object the page really holds.
 *
 * jsdom, because the evaluator imports `@nextcloud/auth`, which reads
 * `window` on load.
 *
 * @spec openspec/changes/simple-structure-profile/specs/case-management/spec.md#REQ-CM-73
 */

import { evaluateVisibleWhen } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const caseDetail = () => manifest.pages.find((page) => page.id === 'CaseDetail')

describe('the archive gate on the case page', () => {
	it('shows the seven write actions on a case that is not archived, by the library evaluator', async () => {
		// The test above reads the declaration. This one asks the library's own
		// evaluator, because the declaration was `eq null` for weeks and read
		// correct while it hid all seven on every working case.
		//
		// The `@self` block is the one a live, non-archived case answered on
		// 2026-10-05: 24 keys and no `archived` among them. OpenRegister adds
		// the key when a case is archived and not before, so the read is
		// undefined, and `eq null` holds for null or the word "null" only.
		const working = {
			id: '2e98a265-401c-426f-9dc5-1e07f7f442c9',
			title: 'Dakkapel Kerkstraat 12',
			'@self': {
				id: '2e98a265-401c-426f-9dc5-1e07f7f442c9',
				name: 'Dakkapel Kerkstraat 12',
				register: '23',
				schema: '172',
				owner: '__system__',
				folder: '203',
				favourite: false,
				unread: false,
				watching: false,
				watcherCount: 0,
				created: '2026-08-30T20:04:30+00:00',
				updated: '2026-09-29T18:39:16+00:00',
			},
		}
		const archived = {
			...working,
			'@self': {
				...working['@self'],
				archived: { at: '2026-10-01T09:00:00+00:00', by: 'admin' },
			},
		}
		// A store that spells absence as null must read the same as one that
		// leaves the key off.
		const explicitNull = {
			...working,
			'@self': { ...working['@self'], archived: null },
		}
		const gated = caseDetail().config.headerActions.filter(
			(action) => action.visibleWhen?.field === '@self.archived',
		)
		expect(gated).toHaveLength(7)
		for (const action of gated) {
			expect(
				await evaluateVisibleWhen(action.visibleWhen, { object: working }),
				`${action.id} must show on a working case`,
			).toBe(true)
			expect(
				await evaluateVisibleWhen(action.visibleWhen, {
					object: explicitNull,
				}),
				`${action.id} must show when the marker is null`,
			).toBe(true)
			expect(
				await evaluateVisibleWhen(action.visibleWhen, { object: archived }),
				`${action.id} must hide on an archived case`,
			).toBe(false)
		}
		// The control: the old spelling, asked of the same evaluator, hides the
		// action on the working case. If this ever turns true the library has
		// changed what `eq null` means and the note in the manifest is stale.
		expect(
			await evaluateVisibleWhen(
				{ field: '@self.archived', op: 'eq', value: null },
				{ object: working },
			),
		).toBe(false)
	})
})

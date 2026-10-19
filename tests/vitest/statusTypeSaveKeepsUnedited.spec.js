// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving a status from the statuses editor, by edit or by reorder, keeps the
 * schema properties the form does not edit. The store saves with a PUT, which
 * replaces the row.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const { default: StatusesTab } = await import('../../src/views/settings/tabs/StatusesTab.vue')
const { mergeOntoStored, statusTypeToForm } = await import('../../src/utils/statusTypeForm.js')

/**
 * A stored status carrying the properties the form has no input for.
 *
 * @param {object} extra Fields to change.
 * @return {object} The row.
 */
function stored(extra = {}) {
	return {
		id: 's1',
		caseType: 'ct',
		name: 'Ontvangen',
		order: 1,
		isFinal: false,
		capacity: 12,
		phaseTermDays: 5,
		phaseTermShare: 40,
		'@self': { id: 's1', owner: 'admin' },
		...extra,
	}
}

/**
 * A component context with a store that records what it was asked to save.
 *
 * @param {Array<object>} rows The stored statuses.
 * @return {object} The context.
 */
function context(rows) {
	const saved = []
	return {
		saved,
		statusTypes: rows,
		get sortedStatusTypes() {
			return [...this.statusTypes].sort((a, b) => a.order - b.order)
		},
		editingId: null,
		editForm: {},
		editError: '',
		editSaving: false,
		dragIndex: null,
		dragOverIndex: null,
		objectStore: {
			saveObject: vi.fn(async (type, data) => {
				saved.push(data)
				return data
			}),
			getError: () => null,
		},
	}
}

describe('saving a status keeps what the form does not edit', () => {
	it('an edit keeps capacity and the phase term', async () => {
		const ctx = context([stored(), stored({ id: 's2', name: 'Afgehandeld', order: 2, isFinal: true })])
		ctx.editingId = 's1'
		ctx.editForm = { ...statusTypeToForm(ctx.statusTypes[0]), name: 'Binnengekomen' }

		await StatusesTab.methods.saveEdit.call(ctx)

		expect(ctx.saved).toHaveLength(1)
		expect(ctx.saved[0]).toMatchObject({ id: 's1', name: 'Binnengekomen', capacity: 12, phaseTermDays: 5, phaseTermShare: 40 })
		expect(ctx.saved[0]).not.toHaveProperty('@self')
	})

	it('a reorder writes the row back with only its order changed', async () => {
		const ctx = context([stored(), stored({ id: 's2', name: 'Afgehandeld', order: 2, isFinal: true, capacity: 3 })])
		ctx.dragIndex = 1

		await StatusesTab.methods.onDrop.call(ctx, 0)

		const byId = Object.fromEntries(ctx.saved.map((row) => [row.id, row]))
		expect(byId.s2).toMatchObject({ order: 1, capacity: 3, phaseTermDays: 5, phaseTermShare: 40 })
		expect(byId.s1).toMatchObject({ order: 2, capacity: 12 })
	})

	it('mergeOntoStored lays the changes over the stored row and drops @self', () => {
		expect(mergeOntoStored(stored(), { name: 'X' })).toEqual({
			id: 's1', caseType: 'ct', name: 'X', order: 1, isFinal: false, capacity: 12, phaseTermDays: 5, phaseTermShare: 40,
		})
		expect(mergeOntoStored(undefined, { name: 'X' })).toEqual({ name: 'X' })
	})
})

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pins the mobile inspection store (stack A of the inspection cluster)
 * before it moves onto OpenRegister's Task.
 *
 * Step 1 of inspection-checklists-onto-task: every reader and writer of the
 * seven schemas has a test that fails when its behaviour changes. This store
 * had none. What it does today, and what the move must keep:
 * - templates live in `inspectieChecklist`, reports in `inspectieRapport`;
 * - a report's result is computed from its items, where `nvt` items count
 *   neither for nor against;
 * - a report with a failed item opens a follow-up task on the case;
 * - a new checklist version archives the old one and starts as a draft.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = {
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
	uploadFiles: vi.fn(),
}
const engineTaskStore = { create: vi.fn() }

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => objectStore,
}))
vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => engineTaskStore,
}))

const { useInspectionStore } = await import('../../src/store/modules/inspection.js')

describe('inspection store (stack A)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		objectStore.saveObject.mockImplementation(async (schema, data) => ({
			id: data.id || 'new-id',
			...data,
		}))
	})

	it('reads checklists from inspectieChecklist, scoped to the case type', async () => {
		objectStore.fetchCollection.mockResolvedValue({
			results: [
				{ id: 'c1', status: 'active' },
				{ id: 'c2', status: 'archived' },
			],
		})
		const store = useInspectionStore()

		await store.fetchChecklists('type-1')

		expect(objectStore.fetchCollection).toHaveBeenCalledWith(
			'inspectieChecklist',
			{ caseType: 'type-1', limit: 100 },
		)
		expect(store.activeChecklists.map((c) => c.id)).toEqual(['c1'])
	})

	it('reads reports from inspectieRapport, scoped to the case', async () => {
		objectStore.fetchCollection.mockResolvedValue([
			{ id: 'r1', result: 'conform' },
			{ id: 'r2', result: 'partly_conform' },
		])
		const store = useInspectionStore()

		await store.fetchReports('case-1')

		expect(objectStore.fetchCollection).toHaveBeenCalledWith(
			'inspectieRapport',
			{ case: 'case-1', limit: 100 },
		)
		expect(store.nonConformReports.map((r) => r.id)).toEqual(['r2'])
	})

	it('writes a report with every item passed as conform and opens no follow-up', async () => {
		const store = useInspectionStore()

		const saved = await store.createReport({
			case: 'case-1',
			items: [{ result: 'pass' }, { result: 'nvt' }],
		})

		expect(objectStore.saveObject).toHaveBeenCalledWith(
			'inspectieRapport',
			expect.objectContaining({
				result: 'conform',
				failedItems: 0,
				followUpRequired: false,
			}),
		)
		expect(saved.result).toBe('conform')
		expect(engineTaskStore.create).not.toHaveBeenCalled()
	})

	it('calls a report partly conform when some applicable items fail, and opens a follow-up task', async () => {
		const store = useInspectionStore()

		await store.createReport({
			case: 'case-1',
			items: [{ result: 'fail' }, { result: 'pass' }, { result: 'nvt' }],
		})

		expect(objectStore.saveObject).toHaveBeenCalledWith(
			'inspectieRapport',
			expect.objectContaining({
				result: 'partly_conform',
				failedItems: 1,
				followUpRequired: true,
			}),
		)
		expect(engineTaskStore.create).toHaveBeenCalledWith(
			expect.objectContaining({
				case: 'case-1',
				status: 'available',
				relatedObject: 'new-id',
			}),
		)
	})

	it('calls a report non conform when every applicable item fails; nvt items do not save it', async () => {
		const store = useInspectionStore()

		await store.createReport({
			case: 'case-1',
			items: [{ result: 'fail' }, { result: 'nvt' }],
		})

		expect(objectStore.saveObject).toHaveBeenCalledWith(
			'inspectieRapport',
			expect.objectContaining({ result: 'non_conform', failedItems: 1 }),
		)
	})

	it('archives the old checklist and saves the next version as a draft', async () => {
		const store = useInspectionStore()

		await store.createNewVersion({
			id: 'c1',
			name: 'Fundering',
			version: 2,
			status: 'active',
		})

		expect(objectStore.saveObject).toHaveBeenNthCalledWith(
			1,
			'inspectieChecklist',
			expect.objectContaining({ id: 'c1', status: 'archived' }),
		)
		expect(objectStore.saveObject).toHaveBeenNthCalledWith(
			2,
			'inspectieChecklist',
			expect.objectContaining({ id: undefined, version: 3, status: 'draft' }),
		)
	})

	it('keeps the error and returns null when the report cannot be written', async () => {
		objectStore.saveObject.mockRejectedValue(new Error('schema refused'))
		const store = useInspectionStore()

		const saved = await store.createReport({
			case: 'case-1',
			items: [{ result: 'fail' }],
		})

		expect(saved).toBeNull()
		expect(store.error).toBe('schema refused')
		expect(engineTaskStore.create).not.toHaveBeenCalled()
	})

	it('deletes a checklist from inspectieChecklist and drops it locally', async () => {
		const store = useInspectionStore()
		store.checklists = [{ id: 'c1' }, { id: 'c2' }]

		expect(await store.deleteChecklist('c1')).toBe(true)
		expect(objectStore.deleteObject).toHaveBeenCalledWith(
			'inspectieChecklist',
			'c1',
		)
		expect(store.checklists.map((c) => c.id)).toEqual(['c2'])
	})
})

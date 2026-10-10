// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pins the mobile inspection store (stack A of the inspection cluster)
 * before it moves onto OpenRegister's Task.
 *
 * Step 1 of inspection-checklists-onto-task: every reader and writer of the
 * seven schemas has a test that fails when its behaviour changes. This store
 * had none. What it does today, and what the move must keep:
 * - templates live in `inspectionChecklistTemplate` (4.1);
 * - runs are OpenRegister tasks (4.2): the store reads and submits them
 *   through dossiq's two VTH result endpoints, and the server decides the
 *   outcome (its rule is pinned in InspectionAnswersTest);
 * - a run with a failed item opens a follow-up task on the case;
 *
 * The store's own template writes (save, new version, delete) had no caller
 * and were removed in 4.1; templates are authored in the settings tab.
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

const http = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params = {}) => url.replace('{id}', params.id) }))
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
	})

	it('reads templates from inspectionChecklistTemplate, scoped to the case type, with section items flattened', async () => {
		objectStore.fetchCollection.mockResolvedValue({
			results: [
				{ id: 'c1', status: 'active', sections: [{ items: [{ id: 'a', label: 'A', responseType: 'yes_no_na', photoRequired: 'if_no' }] }, { items: [{ id: 'b', label: 'B', photoRequired: 'nooit' }] }] },
				{ id: 'c2', status: 'retired', sections: [] },
			],
		})
		const store = useInspectionStore()

		await store.fetchChecklists('type-1')

		expect(objectStore.fetchCollection).toHaveBeenCalledWith('inspectionChecklistTemplate', { caseType: 'type-1', limit: 100 })
		expect(store.activeChecklists.map((c) => c.id)).toEqual(['c1'])
		expect(store.activeChecklists[0].items.map((i) => i.id)).toEqual(['a', 'b'])
		expect(store.activeChecklists[0].items.map((i) => [i.type, i.photoRequired])).toEqual([['yes_no_na', true], [undefined, false]])
	})

	it('reads a case\'s runs from the results endpoint', async () => {
		http.get.mockResolvedValue({ data: [{ id: 'r1', result: 'conform' }, { id: 'r2', result: 'partly_conform' }] })
		const store = useInspectionStore()

		await store.fetchReports('case-1')

		expect(http.get).toHaveBeenCalledWith('/apps/dossiq/api/vth/cases/case-1/inspection-results')
		expect(store.nonConformReports.map((r) => r.id)).toEqual(['r2'])
	})

	it('submits a run to the result endpoint and opens no follow-up when nothing failed', async () => {
		http.post.mockResolvedValue({ data: { id: 'task-1', result: 'conform', failedItems: 0 } })
		const store = useInspectionStore()

		const saved = await store.createReport({ case: 'case-1', checklist: 't-1', items: [{ itemId: 'q1', result: 'pass' }] })

		expect(http.post).toHaveBeenCalledWith(
			'/apps/dossiq/api/vth/cases/case-1/inspection-result',
			expect.objectContaining({ checklistId: 't-1', items: [{ itemId: 'q1', result: 'pass' }] }),
		)
		expect(saved.result).toBe('conform')
		expect(engineTaskStore.create).not.toHaveBeenCalled()
	})

	it('opens a follow-up task naming the run when the server counts failed items', async () => {
		http.post.mockResolvedValue({ data: { id: 'task-1', result: 'partly_conform', failedItems: 2 } })
		const store = useInspectionStore()

		await store.createReport({ case: 'case-1', checklist: 't-1', items: [] })

		expect(engineTaskStore.create).toHaveBeenCalledWith(expect.objectContaining({ case: 'case-1', status: 'available', relatedObject: 'task-1' }))
	})

	it('keeps the server\'s refusal and returns null when the run is refused', async () => {
		http.post.mockRejectedValue(Object.assign(new Error('422'), { response: { data: { message: 'A photo is required for: Wapening' } } }))
		const store = useInspectionStore()

		const saved = await store.createReport({ case: 'case-1', checklist: 't-1', items: [] })

		expect(saved).toBeNull()
		expect(store.error).toBe('A photo is required for: Wapening')
		expect(engineTaskStore.create).not.toHaveBeenCalled()
	})
})

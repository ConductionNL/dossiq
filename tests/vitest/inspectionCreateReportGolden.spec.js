/**
 * Golden test for the inspection store's createReport: the exact request, the
 * follow-up, the state and the answer, with the clock frozen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const engineTaskStore = { create: vi.fn(async () => ({ id: 'follow-up' })) }
const http = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace('{id}', params.id),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchCollection: vi.fn(), saveObject: vi.fn(), deleteObject: vi.fn(), uploadFiles: vi.fn() }),
}))
vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => engineTaskStore,
}))

const { useInspectionStore } = await import('../../src/store/modules/inspection.js')

describe('inspection store createReport', () => {
	let logged
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T09:30:00Z'))
		logged = vi.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		vi.useRealTimers()
		logged.mockRestore()
	})

	it('records every run as before', async () => {
		const answers = [
			{ data: { id: 'run-1', failedItems: 0 } },
			{ data: { id: 'run-2', failedItems: 3 } },
			Object.assign(new Error('422'), { response: { data: { message: 'A photo is required' } } }),
			new Error('offline'),
		]
		const inputs = [
			{ case: 'case-1', checklist: 't-1', items: [{ itemId: 'q1' }], remarks: 'ok', location: 'Kade 1', inspectionDate: '2026-11-01' },
			{ case: 'case-2', checklist: 't-2' },
			{ case: 'case-3', checklist: 't-3', items: [] },
			{ case: 'case-4', checklist: 't-4' },
		]
		const store = useInspectionStore()
		const outcomes = []
		for (const [index, answer] of answers.entries()) {
			if (answer instanceof Error) {
				http.post.mockRejectedValueOnce(answer)
			} else {
				http.post.mockResolvedValueOnce(answer)
			}
			const saved = await store.createReport(inputs[index])
			outcomes.push({ saved, error: store.error, loading: store.loading, reports: store.reports.map((report) => report.id) })
		}
		expect({
			outcomes,
			posted: http.post.mock.calls,
			followUps: engineTaskStore.create.mock.calls,
			logged: logged.mock.calls.map((call) => [call[0], call[1]?.message]),
		}).toMatchSnapshot()
	})
})

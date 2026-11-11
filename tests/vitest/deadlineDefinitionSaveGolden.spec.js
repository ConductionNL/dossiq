// @vitest-environment jsdom
/**
 * Golden test for saving a term definition in the Deadline definitions tab:
 * the requests, in order, and what the tab does after, with the clock frozen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
const failures = { patch: null, post: null }
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async () => ({ data: [] })),
		patch: vi.fn(async (url, body) => {
			calls.push(['patch', url, body])
			if (failures.patch) {
				throw failures.patch
			}
			return { data: {} }
		}),
		post: vi.fn(async (url, body) => {
			calls.push(['post', url, body])
			if (failures.post) {
				throw failures.post
			}
			return { data: {} }
		}),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (u) => '/index.php' + u }))

const { default: DeadlineDefinitionsTab } = await import('../../src/views/settings/tabs/DeadlineDefinitionsTab.vue')

/**
 * Save through the tab's own method, on a stand-in for the tab.
 *
 * @param {object|null} editing The definition being edited, or null for a new one.
 * @param {object} payload What the editor hands over.
 * @return {Promise<object>} What happened.
 */
async function save(editing, payload) {
	calls.length = 0
	const tab = {
		editingDefinition: editing,
		error: '',
		closeEditor: () => calls.push(['closeEditor']),
		load: async () => calls.push(['load']),
	}
	await DeadlineDefinitionsTab.methods.onSave.call(tab, payload)
	return { calls: [...calls], error: tab.error }
}

describe('saving a term definition', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-10T23:30:00Z'))
		failures.patch = null
		failures.post = null
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('versions and saves as before', async () => {
		const outcomes = {}
		outcomes.created = await save(null, { name: 'Beslistermijn', validFrom: 'x', version: 9 })
		outcomes.edited = await save({ id: 'td/1', version: '2' }, { name: 'Beslistermijn' })
		outcomes.editedWithoutVersion = await save({ id: 'td-2' }, {})
		failures.patch = Object.assign(new Error('patch failed'), { response: { data: { message: 'Locked' } } })
		outcomes.closeRefused = await save({ id: 'td-3', version: 1 }, {})
		failures.patch = null
		failures.post = new Error('offline')
		outcomes.postFailed = await save(null, {})
		failures.post = Object.assign(new Error(''), { response: {} })
		outcomes.postFailedSilently = await save(null, {})
		expect(outcomes).toMatchSnapshot()
	})
})

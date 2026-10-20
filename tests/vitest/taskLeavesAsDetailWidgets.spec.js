// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import { flushPromises, mount } from '@vue/test-utils'
/**
 * The task page's own leaves as detail widgets (task-page-on-the-manifest).
 *
 * A manifest detail page hands a registry widget the record in view as
 * `objectId` (and its manifest `content`), never a `taskId`. Each leaf must
 * therefore read the task named by `objectId`, and the four must be widget
 * entries a manifest can name, with the titled card on the side column and in
 * a tab stack.
 *
 * @spec openspec/changes/task-page-on-the-manifest/specs/task-management/spec.md#requirement-the-task-page-is-a-manifest-detail-page
 */
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

let reads = []
let fetched = []
const storeStub = {
	async readLeaf(uuid, leaf) {
		reads.push({ uuid, leaf })
		return { results: [], error: null }
	},
	async fetch(uuid) {
		fetched.push(uuid)
		return { uuid, runUuid: 'run-1', objectUuid: 'case-1' }
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchObject: async () => ({ title: 'Bezwaar parkeerboete' }) }),
}))

const { default: TaskNotesLeaf } = await import('../../src/components/tasks/TaskNotesLeaf.vue')
const { default: TaskEventsLeaf } = await import('../../src/components/tasks/TaskEventsLeaf.vue')
const { default: TaskAuditLeaf } = await import('../../src/components/tasks/TaskAuditLeaf.vue')
const { default: TaskWaitingCaseSection } = await import('../../src/components/flow/TaskWaitingCaseSection.vue')
const registrySource = fs.readFileSync(path.resolve(__dirname, '../../src/registry.js'), 'utf8')

/**
 * One registry entry's body, read from the source: importing the registry
 * pulls in every page of the app.
 *
 * @param {string} key The entry key.
 * @return {string} The body, or ''.
 */
function entryBody(key) {
	const match = new RegExp(`^\\t'${key}': \\{\\n((?:\\t\\t.*\\n)*?)\\t\\},`, 'm').exec(registrySource)
	return match ? match[1] : ''
}

beforeEach(() => {
	reads = []
	fetched = []
})

describe('the task leaves are detail widgets', () => {
	it.each([
		['task-notes', 'TaskNotesLeaf'],
		['task-events', 'TaskEventsLeaf'],
		['task-audit', 'TaskAuditLeaf'],
		['task-waiting-case', 'TaskWaitingCaseSection'],
	])('registers %s as a carded widget', (key, component) => {
		const body = entryBody(key)
		expect(body).toContain("kind: 'widget',")
		expect(body).toContain("chrome: 'card',")
		expect(body).toContain(`component: ${component},`)
	})

	it.each([
		[TaskNotesLeaf, 'notes'],
		[TaskEventsLeaf, 'events'],
	])('reads the task the page hands over as objectId', async (component, leaf) => {
		mount(component, { props: { objectId: 'task-9' } })
		await flushPromises()

		expect(reads).toEqual([{ uuid: 'task-9', leaf }])
	})

	it('shows the history straight away with content.open', async () => {
		const wrapper = mount(TaskAuditLeaf, { props: { objectId: 'task-9', content: { open: true } } })
		await flushPromises()

		expect(reads).toEqual([{ uuid: 'task-9', leaf: 'audit' }])
		expect(wrapper.find('[data-testid="task-audit-leaf-toggle"]').exists()).toBe(false)
	})

	it('names the case a run waits on, for the task handed over as objectId', async () => {
		const wrapper = mount(TaskWaitingCaseSection, {
			props: { objectId: 'task-9' },
			global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
		})
		await flushPromises()

		expect(fetched).toEqual(['task-9'])
		expect(wrapper.find('[data-testid="task-waiting-case-link"]').text()).toBe('Bezwaar parkeerboete')
	})
})

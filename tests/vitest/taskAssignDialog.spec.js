// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task page assigns a task to another user or to a team (REQ-TASK-003,
 * capability-rows-say-the-whole-capability task 3.3). TaskDetail offered Pick
 * up and Hand back only; the engine decides who may assign, and its refusal
 * is shown as it said it.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const busEmit = vi.fn()
const searchUsers = vi.fn()
const searchGroups = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => busEmit(...args) }))
vi.mock('../../src/services/userSearch.js', () => ({
	searchUsers: (...args) => searchUsers(...args),
	searchGroups: (...args) => searchGroups(...args),
}))

const { default: TaskAssignDialog } = await import('../../src/dialogs/TaskAssignDialog.vue')
const { assignRequest } = await import('../../src/utils/taskAssign.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

const STUBS = {
	NcDialog: { name: 'NcDialog', props: ['name'], template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input class="search" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
	NcCheckboxRadioSwitch: { props: ['modelValue', 'value'], emits: ['update:modelValue'], template: '<label><input type="radio" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)" /><slot /></label>' },
}

/**
 * Mount the dialog on a task route.
 *
 * @param {object} props The dialog props.
 * @return {object} The wrapper.
 */
function mountDialog(props = { taskId: '@objectId' }) {
	return mount(TaskAssignDialog, {
		props,
		global: { stubs: STUBS, mocks: { $route: { params: { id: 'task-7' } } } },
	})
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The dialog.
 * @param {string} text The text.
 * @return {object} The button.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text() === text)
}

/**
 * Type a term and pick the first result.
 *
 * @param {object} wrapper The dialog.
 * @param {string} term What is typed.
 * @return {Promise<void>}
 */
async function pickFirst(wrapper, term) {
	await wrapper.find('input.search').setValue(term)
	await flushPromises()
	await wrapper.find('[data-testid="task-assign-result"] input').trigger('change')
}

describe('the task page declares the assign action', () => {
	it('opens TaskAssignDialog from a header action while the task is open', () => {
		const page = manifest.pages.find((entry) => entry.id === 'TaskDetail')
		const action = page.config.headerActions.find((a) => a.id === 'task-assign')
		expect(action).toMatchObject({ type: 'open-modal', target: 'TaskAssignDialog', props: { taskId: '@objectId' } })
		expect(action.visibleWhen).toEqual({ field: 'state', op: 'in', value: ['available', 'enabled', 'active'] })
	})
})

describe('assignRequest', () => {
	it('assigns a colleague through the engine assign verb', () => {
		expect(assignRequest('t 1', 'user', 'bea')).toEqual({
			url: '/apps/openregister/api/flow-tasks/t%201/assign',
			body: { assignee: 'bea' },
		})
	})

	it('offers a team the task as its candidate pool', () => {
		expect(assignRequest('t1', 'group', 'vergunningen')).toEqual({
			url: '/apps/openregister/api/flow-tasks/t1/offer',
			body: { candidateGroups: ['vergunningen'] },
		})
	})
})

describe('TaskAssignDialog', () => {
	beforeEach(() => {
		post.mockReset()
		busEmit.mockReset()
		searchUsers.mockReset().mockResolvedValue([{ id: 'bea', label: 'Bea' }])
		searchGroups.mockReset().mockResolvedValue([{ id: 'vergunningen', label: 'Vergunningen' }])
	})

	it('cannot assign before someone is picked', () => {
		const wrapper = mountDialog()
		expect(button(wrapper, 'Assign').attributes('disabled')).toBeDefined()
	})

	it('assigns the route task to the picked colleague and refreshes the page', async () => {
		post.mockResolvedValue({ data: {} })
		const wrapper = mountDialog()
		await pickFirst(wrapper, 'be')
		expect(searchUsers).toHaveBeenCalledWith('be')
		await button(wrapper, 'Assign').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/task-7/assign', { assignee: 'bea' })
		expect(busEmit).toHaveBeenCalledWith('cn:page:refresh')
		expect(wrapper.emitted('assigned')[0]).toEqual([{ kind: 'user', id: 'bea' }])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('offers the task to a picked team', async () => {
		post.mockResolvedValue({ data: {} })
		const wrapper = mountDialog({ taskId: 'task-9' })
		await wrapper.find('[data-testid="task-assign-kind-group"] input').trigger('change')
		await pickFirst(wrapper, 'verg')
		expect(searchGroups).toHaveBeenCalledWith('verg')
		expect(searchUsers).not.toHaveBeenCalled()
		await button(wrapper, 'Assign').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/task-9/offer', { candidateGroups: ['vergunningen'] })
	})

	it('keeps the dialog open with the engine\'s own refusal', async () => {
		post.mockRejectedValue({ response: { data: { error: "Verb 'offer' refused: task 'task-7' is already assigned; unclaim or reassign it instead." } } })
		const wrapper = mountDialog()
		await pickFirst(wrapper, 'be')
		await button(wrapper, 'Assign').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="task-assign-error"]').text()).toBe("Verb 'offer' refused: task 'task-7' is already assigned; unclaim or reassign it instead.")
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(busEmit).not.toHaveBeenCalled()
	})

	it('words a refusal the engine gave no sentence for', async () => {
		post.mockRejectedValue(new Error('network'))
		const wrapper = mountDialog()
		await pickFirst(wrapper, 'be')
		await button(wrapper, 'Assign').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="task-assign-error"]').text()).toBe('The task could not be assigned.')
	})

	it('asks nothing for fewer than two characters', async () => {
		const wrapper = mountDialog()
		await wrapper.find('input.search').setValue('b')
		await flushPromises()
		expect(searchUsers).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="task-assign-result"]').exists()).toBe(false)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Herinneren: when the reminder may be set, what is sent, and what a refusal shows.
 *
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const emitted = vi.fn()
const store = { create: vi.fn(), error: '' }

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'pieter' }) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args) }))
vi.mock('../../src/store/modules/engineTask.js', () => ({ useEngineTaskStore: () => store }))
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: plain('NcTextField') }))
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({ default: plain('NcDateTimePicker') }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: plain('NcButton') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: plain('CnDialog') }))

const { default: RemindDialog } = await import('../../src/dialogs/RemindDialog.vue')

/**
 * Mount the dialog on a case route.
 *
 * @param {object} props The props.
 * @param {string} routeId The route's case id.
 * @return {object} The wrapper.
 */
function dialog(props = {}, routeId = 'c-route') {
	return mount(RemindDialog, { props, global: { mocks: { $route: { params: { id: routeId } } } } })
}

/**
 * A date some days from now.
 *
 * @param {number} days Days ahead.
 * @return {Date} The date.
 */
function inDays(days) {
	const date = new Date()
	date.setDate(date.getDate() + days)
	return date
}

describe('RemindDialog confirm', () => {
	beforeEach(() => {
		emitted.mockReset()
		store.create.mockReset()
		store.error = ''
	})

	it('prefers the case id prop over the route', () => {
		expect(dialog({ caseId: 'c-prop' }).vm.targetCaseId).toBe('c-prop')
		expect(dialog().vm.targetCaseId).toBe('c-route')
	})

	it('reads a picked date as its ISO day, and a string date by its first ten characters', () => {
		const w = dialog()
		w.vm.date = new Date('2030-03-04T10:00:00Z')
		expect(w.vm.isoDate).toBe('2030-03-04')
		w.vm.date = '2030-05-06T00:00:00'
		expect(w.vm.isoDate).toBe('2030-05-06')
		w.vm.date = null
		expect(w.vm.isoDate).toBe('')
		w.vm.date = new Date('not a date')
		expect(w.vm.isoDate).toBe('')
	})

	it('cannot confirm without a title, without a case, or while busy', async () => {
		const w = dialog()
		w.vm.date = inDays(3)
		expect(w.vm.canConfirm).toBe(false)
		w.vm.title = 'Bel de aanvrager'
		expect(w.vm.canConfirm).toBe(true)
		w.vm.busy = true
		expect(w.vm.canConfirm).toBe(false)

		const noCase = dialog({}, '')
		noCase.vm.title = 'Bel de aanvrager'
		noCase.vm.date = inDays(3)
		expect(noCase.vm.canConfirm).toBe(false)
		await noCase.vm.confirm()
		expect(store.create).not.toHaveBeenCalled()
	})

	it('sends the reminder for the case, refreshes the page and closes', async () => {
		store.create.mockResolvedValue({ id: 't-1' })
		const w = dialog({ caseId: 'c-9' })
		w.vm.title = 'Bel de aanvrager'
		w.vm.date = inDays(3)
		w.vm.assignee = ' marieke '

		await w.vm.confirm()

		expect(store.create).toHaveBeenCalledTimes(1)
		const payload = store.create.mock.calls[0][0]
		expect(JSON.stringify(payload)).toContain('c-9')
		expect(JSON.stringify(payload)).toContain('marieke')
		expect(JSON.stringify(payload)).toContain('Bel de aanvrager')
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
		expect(w.emitted('close')).toHaveLength(1)
		expect(w.vm.busy).toBe(false)
	})

	it('shows the store\'s refusal, or a fallback, and stays open', async () => {
		store.create.mockResolvedValue(null)
		store.error = 'Not allowed on a closed case'
		const w = dialog({ caseId: 'c-9' })
		w.vm.title = 'Bel de aanvrager'
		w.vm.date = inDays(3)

		await w.vm.confirm()
		expect(w.vm.error).toBe('Not allowed on a closed case')

		store.error = ''
		await w.vm.confirm()
		expect(w.vm.error).toBe('The reminder could not be set.')
		expect(w.emitted('close')).toBeUndefined()
		expect(emitted).not.toHaveBeenCalled()
		expect(w.vm.busy).toBe(false)
	})
})

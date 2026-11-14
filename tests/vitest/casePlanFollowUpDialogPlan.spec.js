// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Planning a follow-up case: the case types offered, the plan sent, and the refusals shown.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const http = { get: vi.fn(), post: vi.fn() }
const emitted = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => http.get(...a), post: (...a) => http.post(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args) }))
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: plain('NcButton') }))
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({ default: plain('NcDateTimePicker') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: plain('NcSelect') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: plain('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: plain('CnDialog') }))

const { default: Dialog } = await import('../../src/dialogs/CasePlanFollowUpDialog.vue')

/**
 * A date some days from now, at noon UTC.
 *
 * @param {number} days Days ahead.
 * @return {Date} The date.
 */
function inDays(days) {
	const date = new Date()
	date.setUTCDate(date.getUTCDate() + days)
	date.setUTCHours(12, 0, 0, 0)
	return date
}

/**
 * Mount on a case and let the case types settle.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function dialog(props = { caseId: 'c 1' }) {
	const w = mount(Dialog, { props, global: { mocks: { $route: { params: { id: 'c-route' } } } } })
	await flushPromises()
	return w
}

/**
 * Fill in a complete one-off plan.
 *
 * @param {object} w The wrapper.
 * @return {void}
 */
function fill(w) {
	w.vm.caseType = { id: 'ct-1', label: 'Controle' }
	w.vm.date = inDays(5)
	w.vm.title = ' Hercontrole '
}

describe('CasePlanFollowUpDialog plan', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.post.mockReset()
		emitted.mockReset()
		http.get.mockResolvedValue({ data: { results: [] } })
	})

	it('offers published case types only, labelled by title or id', async () => {
		http.get.mockResolvedValue({ data: { results: [
			{ id: 'ct-1', title: 'Controle' },
			{ id: 'ct-2', title: 'Concept', isDraft: true },
			{ id: 7 },
			{ title: 'No id' },
		] } })
		const w = await dialog()

		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/caseType?_limit=200')
		expect(w.vm.caseTypes).toEqual([{ id: 'ct-1', label: 'Controle' }, { id: '7', label: '7' }])
		expect(w.vm.loadingTypes).toBe(false)
	})

	it('offers no case types when the read fails', async () => {
		http.get.mockImplementationOnce(() => Promise.reject(new Error('down')))
		const w = await dialog()
		expect(w.vm.caseTypes).toEqual([])
		expect(w.vm.loadingTypes).toBe(false)
	})

	it('a one-off plan ignores the series end', async () => {
		const w = await dialog()
		fill(w)
		w.vm.end = w.vm.ends[2]
		w.vm.count = '5'

		expect(w.vm.repeats).toBe(false)
		expect(w.vm.plan).toEqual({
			caseType: 'ct-1',
			date: inDays(5).toISOString().slice(0, 10),
			title: ' Hercontrole ',
			recurrence: 'none',
			end: 'open',
			until: '',
			count: 0,
		})
		expect(w.vm.canConfirm).toBe(true)
	})

	it('a series ending on a date carries the date, and refuses one before the start', async () => {
		const w = await dialog()
		fill(w)
		w.vm.recurrence = w.vm.recurrences.find((r) => r.id === 'quarterly')
		w.vm.end = w.vm.ends.find((e) => e.id === 'until')
		w.vm.until = inDays(400)

		expect(w.vm.plan.until).toBe(inDays(400).toISOString().slice(0, 10))
		expect(w.vm.plan.count).toBe(0)
		expect(w.vm.canConfirm).toBe(true)

		w.vm.until = inDays(2)
		expect(w.vm.canConfirm).toBe(false)
		w.vm.until = 'not a date'
		expect(w.vm.isoUntil).toBe('')
	})

	it('a series ending after a number of cases carries the number', async () => {
		const w = await dialog()
		fill(w)
		w.vm.recurrence = w.vm.recurrences.find((r) => r.id === 'monthly')
		w.vm.end = w.vm.ends.find((e) => e.id === 'count')
		w.vm.count = '4'

		expect(w.vm.plan).toMatchObject({ recurrence: 'monthly', end: 'count', until: '', count: 4 })
		w.vm.count = '0'
		expect(w.vm.canConfirm).toBe(false)
	})

	it('cannot plan for today, without a title, or while busy', async () => {
		const w = await dialog()
		fill(w)
		w.vm.date = inDays(0)
		expect(w.vm.canConfirm).toBe(false)
		fill(w)
		w.vm.title = ' '
		expect(w.vm.canConfirm).toBe(false)
		fill(w)
		w.vm.busy = true
		expect(w.vm.canConfirm).toBe(false)
		w.vm.date = 'not a date'
		expect(w.vm.isoDate).toBe('')
	})

	it('posts the plan for the case with a trimmed title, refreshes and closes', async () => {
		http.post.mockResolvedValue({ data: {} })
		const w = await dialog()
		fill(w)

		await w.vm.confirm()

		expect(http.post).toHaveBeenCalledWith('/apps/dossiq/api/case/c%201/plan', {
			caseType: 'ct-1',
			date: inDays(5).toISOString().slice(0, 10),
			title: 'Hercontrole',
			recurrence: 'none',
			until: '',
			count: 0,
		})
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
		expect(w.emitted('close')).toHaveLength(1)
		expect(w.vm.busy).toBe(false)
	})

	it('falls back to the route case, and sends nothing for an incomplete plan', async () => {
		const w = await dialog({})
		expect(w.vm.targetCaseId).toBe('c-route')
		await w.vm.confirm()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('says why the plan was refused, by its code', async () => {
		const w = await dialog()
		fill(w)

		http.post.mockImplementationOnce(() => Promise.reject({ response: { data: { code: 'invalid_recurrence' } } }))
		await w.vm.confirm()
		expect(w.vm.error).toBe('Pick how often the follow-up comes back.')

		http.post.mockImplementationOnce(() => Promise.reject(new Error('network')))
		await w.vm.confirm()
		expect(w.vm.error).toBe('This did not work. Try again.')
		expect(w.emitted('close')).toBeUndefined()
		expect(w.vm.busy).toBe(false)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the lifecycle menu's `confirm`
 * (src/dialogs/CaseLifecycleMenuDialog.vue): the transition post, the act
 * post with its per-act fields, the refresh and close, and a refusal.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const { methods } = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

/**
 * A stand-in for the component instance `confirm` runs on.
 *
 * @param {object} chosen The chosen act.
 * @param {object} data What the act's form answers.
 * @return {object} The instance.
 */
function instance(chosen, data = {}) {
	return {
		...methods,
		canConfirm: true,
		targetCaseId: 'case 1',
		chosen,
		answers: { data },
		values: {},
		reminder: null,
		busy: false,
		error: 'old',
		warnAboutFailedActions: vi.fn(),
		sayWhetherTheRequesterWasTold: vi.fn(),
		$emit: vi.fn(),
	}
}

describe('lifecycle menu confirm', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: { status: 'ok' } })
	})

	it('does nothing when the act cannot be confirmed', async () => {
		const vm = { ...instance({ kind: 'gesture', id: 'hold' }), canConfirm: false }
		await methods.confirm.call(vm)
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
		expect(vm.error).toBe('old')
	})

	it('posts a transition with its payload and warns about failed actions', async () => {
		const vm = instance({ kind: 'transition', id: 't-1' }, { reason: 'Klaar', resultTypeId: 'rt-1' })
		await methods.confirm.call(vm)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0]).toEqual([
			'/index.php/apps/dossiq/api/case/case%201/transition',
			{ transitionId: 't-1', comment: 'Klaar', resultTypeId: 'rt-1' },
		])
		expect(vm.warnAboutFailedActions).toHaveBeenCalledWith({ status: 'ok' })
		expect(vm.sayWhetherTheRequesterWasTold).not.toHaveBeenCalled()
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(vm.$emit).toHaveBeenCalledWith('close')
		expect(vm.error).toBe('')
		expect(vm.busy).toBe(false)
	})

	it('posts an act with the defaulted fields and the days as a number', async () => {
		const vm = instance({ kind: 'gesture', id: 'extend' }, { reason: 'Drukte', days: '14', extra: 'x' })
		await methods.confirm.call(vm)
		expect(axios.post.mock.calls[0]).toEqual([
			'/index.php/apps/dossiq/api/case/case%201/extend',
			{ reason: 'Drukte', resultTypeId: '', until: '', days: 14, pauseReason: '' },
		])
		expect(vm.sayWhetherTheRequesterWasTold).toHaveBeenCalledWith({ status: 'ok' }, 'extend')
		expect(vm.warnAboutFailedActions).not.toHaveBeenCalled()
		expect(vm.$emit).toHaveBeenCalledWith('close')
	})

	it('sends unreadable days as zero', async () => {
		const vm = instance({ kind: 'gesture', id: 'hold' }, { days: 'soon' })
		await methods.confirm.call(vm)
		expect(axios.post.mock.calls[0][1].days).toBe(0)
	})

	it.each([
		[true, true],
		[undefined, false],
		['yes', false],
	])('asks the archive to notify the requester (%s → %s)', async (ticked, sent) => {
		const vm = { ...instance({ kind: 'gesture', id: 'archive' }, { reason: 'Af' }), values: { notifyRequester: ticked } }
		await methods.confirm.call(vm)
		expect(axios.post.mock.calls[0][1]).toEqual({ reason: 'Af', resultTypeId: '', until: '', days: 0, pauseReason: '', notifyRequester: sent })
	})

	it('asks a suspend to remind the requester only when a reminder is on offer', async () => {
		const offered = { ...instance({ kind: 'gesture', id: 'suspend' }, { pauseReason: 'k' }), reminder: { due: 'x' }, values: { remindRequester: true } }
		await methods.confirm.call(offered)
		expect(axios.post.mock.calls[0][1]).toEqual({ reason: '', resultTypeId: '', until: '', days: 0, pauseReason: 'k', remindRequester: true })

		const notOffered = { ...instance({ kind: 'gesture', id: 'suspend' }), values: { remindRequester: true } }
		await methods.confirm.call(notOffered)
		expect(axios.post.mock.calls[1][1]).toEqual({ reason: '', resultTypeId: '', until: '', days: 0, pauseReason: '' })
	})

	it('shows the refusal and stays open when the post is refused', async () => {
		axios.post.mockRejectedValue({ response: { data: { code: 'reason_required' } } })
		const vm = instance({ kind: 'gesture', id: 'abort' })
		await methods.confirm.call(vm)
		expect(vm.error).toBe('Give a reason first.')
		expect(emit).not.toHaveBeenCalled()
		expect(vm.$emit).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})

	it('shows the default refusal when the post fails without an answer', async () => {
		axios.post.mockRejectedValue(new Error('network'))
		const vm = instance({ kind: 'transition', id: 't-1' })
		await methods.confirm.call(vm)
		expect(vm.error).toBe('The case could not be changed.')
	})
})

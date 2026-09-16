// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case that moved without all of its actions says so.
 *
 * The engine answers 200 with `status: "partial"` and a `failedActions` list
 * when the case moved and an automatic action failed after it. The dialog used
 * to discard the body, so a handler saw a moved case with no checklist behind
 * it and nothing to tell that apart from a phase that asks for no work.
 *
 * The helper is asserted on the count it pluralises by, and the dialog on
 * what it does with the answer: warn, and still close and refresh, because
 * the move itself happened.
 *
 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { showWarning, emit } = vi.hoisted(() => ({
	showWarning: vi.fn(),
	emit: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showWarning }))
vi.mock('@nextcloud/event-bus', () => ({ emit }))

const { failedActionsOf, failedActionsWarning } =
	await import('../../src/utils/transitionOutcome.js')
const CaseTransitionConfirmDialog = (
	await import('../../src/dialogs/CaseTransitionConfirmDialog.vue')
).default

/** Two actions that did not run. */
const TWO_FAILED = [
	{ type: 'createTask', error: 'no_actor' },
	{ type: 'sendEmail', error: 'mail_down' },
]

describe('failedActionsWarning', () => {
	it('says nothing when every action ran', () => {
		expect(failedActionsWarning({ status: 'ok', failedActions: [] })).toBe('')
		expect(failedActionsWarning({ status: 'ok' })).toBe('')
		expect(failedActionsWarning(undefined)).toBe('')
		expect(failedActionsOf({ failedActions: 'not a list' })).toEqual([])
	})

	it('names one action in the singular', () => {
		expect(
			failedActionsWarning({
				status: 'partial',
				failedActions: [TWO_FAILED[0]],
			}),
		).toBe(
			'You moved the case, but 1 automatic action did not run. Its status record shows which.',
		)
	})

	it('names several actions in the plural', () => {
		expect(
			failedActionsWarning({ status: 'partial', failedActions: TWO_FAILED }),
		).toBe(
			'You moved the case, but 2 automatic actions did not run. Its status record shows which.',
		)
	})
})

describe('the case-page transition dialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * Mount the dialog on an ordinary transition and confirm it.
	 *
	 * @param {object} body What the engine answers.
	 * @return {Promise<object>} The mounted wrapper.
	 */
	async function confirmWith(body) {
		axios.post.mockResolvedValue({ data: body })
		const wrapper = shallowMount(CaseTransitionConfirmDialog, {
			props: {
				caseId: 'case-1',
				transition: {
					id: 't1',
					label: 'Start behandeling',
					toStatus: 'st-progress',
				},
				closing: false,
			},
		})
		await wrapper.vm.confirm()
		await flushPromises()
		return wrapper
	}

	it('warns with the failed-action count, and still closes and refreshes', async () => {
		const wrapper = await confirmWith({
			status: 'partial',
			failedActions: TWO_FAILED,
		})

		expect(showWarning).toHaveBeenCalledTimes(1)
		expect(showWarning).toHaveBeenCalledWith(
			'You moved the case, but 2 automatic actions did not run. Its status record shows which.',
		)
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.error).toBe('')
	})

	it('stays quiet when every action ran', async () => {
		const wrapper = await confirmWith({ status: 'ok', failedActions: [] })

		expect(showWarning).not.toHaveBeenCalled()
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

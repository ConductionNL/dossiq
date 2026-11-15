// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the lifecycle menu's `askForApproval`
 * (src/dialogs/CaseLifecycleMenuDialog.vue): the post, the re-read, and a
 * refusal.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const { methods } = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

/**
 * A stand-in for the component instance.
 *
 * @return {object} The instance.
 */
function instance() {
	return { targetCaseId: 'case 1', busy: false, error: 'old', load: vi.fn().mockResolvedValue(undefined) }
}

describe('lifecycle menu askForApproval', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('posts the approval request for the act and re-reads the menu', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const vm = instance()
		await methods.askForApproval.call(vm, { act: 'close/now' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/case/case%201/approvals/close%2Fnow')
		expect(vm.load).toHaveBeenCalledTimes(1)
		expect(vm.error).toBe('')
		expect(vm.busy).toBe(false)
	})

	it('shows the refusal and does not re-read', async () => {
		axios.post.mockRejectedValue({ response: { data: { code: 'approval_unreadable' } } })
		const vm = instance()
		await methods.askForApproval.call(vm, { act: 'close' })
		expect(vm.error).toBe('We could not reach the approval service, so this act stays closed.')
		expect(vm.load).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})

	it('shows the default refusal when the post fails without an answer', async () => {
		axios.post.mockRejectedValue(new Error('network'))
		const vm = instance()
		await methods.askForApproval.call(vm, { act: 'close' })
		expect(vm.error).toBe('The case could not be changed.')
	})
})

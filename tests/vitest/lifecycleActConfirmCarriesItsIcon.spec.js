// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The confirm button of an act opened from the lifecycle menu carries the
 * act's own icon, the one its card shows (board DqVolgendeStap draws
 * "Opschorten" with the pause icon). An act with its own confirm icon, such
 * as Archiveren, keeps that one.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { iconFor } from '../../src/utils/caseActsMenu.js'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

/**
 * The menu with one act open.
 *
 * @param {object} act The act.
 * @return {Promise<object>} The wrapper.
 */
async function openOn(act) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/acts') ? { acts: [] } : url.endsWith('/lifecycle') ? {} : { transitions: [] },
	}))
	const wrapper = shallowMount(CaseLifecycleMenuDialog, {
		props: { caseId: 'case-1' },
		global: {
			stubs: {
				CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				CnChoiceCards: true,
				NcButton: { template: '<button><slot name="icon" /><slot /></button>' },
				CnIcon: { props: ['name'], template: '<i class="cn-icon" :data-name="name" />' },
			},
		},
	})
	await flushPromises()
	wrapper.vm.choose(act)
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('the confirm button of a lifecycle act', () => {
	it('carries the act\'s own icon', async () => {
		const suspend = { kind: 'gesture', id: 'suspend' }
		expect(iconFor(suspend)).not.toBe('')
		const wrapper = await openOn(suspend)
		const confirm = wrapper.find('[data-testid="case-acts-confirm"]')
		expect(confirm.find('.cn-icon').attributes('data-name')).toBe(iconFor(suspend))
	})

	it('keeps an act\'s own confirm icon instead', async () => {
		const wrapper = await openOn({ kind: 'ending', id: 'archive' })
		const confirm = wrapper.find('[data-testid="case-acts-confirm"]')
		expect(confirm.find('.cn-icon').exists()).toBe(false)
	})
})

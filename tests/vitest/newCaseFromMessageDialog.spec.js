// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * New case from an inbox message: the New case dialog opens seeded from the
 * message, and the case it makes gets the message filed on it.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockPost = vi.fn()
const mockEmit = vi.fn()
const mockOpenForm = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/manifest.json', () => ({
	default: {
		pages: [{
			id: 'Dashboard',
			config: {
				headerActions: [{
					id: 'new-case',
					type: 'open-form',
					register: 'dossiq',
					schema: 'case',
					onSuccessRoute: 'CaseDetail',
					props: { assignee: '@me' },
				}],
			},
		}],
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnActionButtons: defineComponent({
		name: 'CnActionButtons',
		emits: ['created'],
		data: () => ({ formEntry: null }),
		methods: {
			openForm(entry) {
				this.formEntry = entry
				mockOpenForm(entry)
			},
		},
		render() {
			return h('div', { class: 'cn-action-buttons' })
		},
	}),
}))

const { default: NewCaseFromMessageDialog, actionFromMessage, findNewCaseAction, NEW_CASE_REASON } = await import('../../src/modals/NewCaseFromMessageDialog.vue')

const ROW = { subject: 'Report: fallen tree on the Parklaan footpath', sender: 'ingrid.smit@example.net', preview: 'A tree fell across the path.' }

describe('NewCaseFromMessageDialog', () => {
	beforeEach(() => {
		mockPost.mockReset()
		mockEmit.mockReset()
		mockOpenForm.mockReset()
	})

	it('seeds the New case action from the message and drops its own navigation', () => {
		const action = findNewCaseAction({ pages: [{ config: { headerActions: [{ id: 'new-case', type: 'open-form', onSuccessRoute: 'CaseDetail', props: { assignee: '@me' } }] } }] })
		const seeded = actionFromMessage(action, ROW)

		expect(seeded.props).toEqual({ assignee: '@me', intakeChannel: 'email', title: ROW.subject, description: ROW.preview })
		expect(seeded.onSuccessRoute).toBeUndefined()
		expect(action.onSuccessRoute).toBe('CaseDetail')
	})

	it('prefers the case list\'s New case action, which carries the board\'s form', () => {
		const source = {
			pages: [
				{ id: 'Dashboard', config: { headerActions: [{ id: 'new-case', type: 'open-form', formTitle: 'plain' }] } },
				{ id: 'Cases', config: { headerActions: [{ id: 'new-case', type: 'open-form', formTitle: 'board' }] } },
			],
		}
		expect(findNewCaseAction(source).formTitle).toBe('board')
	})

	it('opens the effective manifest\'s action when the app root provides it', async () => {
		const provided = { pages: [{ id: 'Cases', config: { headerActions: [{ id: 'new-case', type: 'open-form', formTitle: 'board', props: {} }] } }] }
		mount(NewCaseFromMessageDialog, { props: { object: ROW, objectId: 'entry-7' }, global: { provide: { cnManifest: provided } } })
		await flushPromises()

		expect(mockOpenForm.mock.calls[0][0].formTitle).toBe('board')
	})

	it('finds no action in a manifest without one', () => {
		expect(findNewCaseAction({ pages: [{ config: { headerActions: [{ id: 'other', type: 'open-form' }] } }] })).toBeNull()
	})

	it('opens the form on mount and files the message on the case it makes', async () => {
		mockPost.mockResolvedValue({ data: {} })
		const push = vi.fn(() => Promise.resolve())
		const wrapper = mount(NewCaseFromMessageDialog, {
			props: { object: ROW, objectId: 'entry-7' },
			global: { mocks: { $router: { push } } },
		})
		await flushPromises()

		expect(mockOpenForm).toHaveBeenCalledTimes(1)
		expect(mockOpenForm.mock.calls[0][0].props.title).toBe(ROW.subject)

		wrapper.findComponent({ name: 'CnActionButtons' }).vm.$emit('created', { id: 'case-9' })
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/mail-intake/log/entry-7/file-on-case', {
			caseId: 'case-9',
			reason: NEW_CASE_REASON,
			addSender: true,
			notifyHandler: false,
		})
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(push).toHaveBeenCalledWith({ name: 'CaseDetail', params: { id: 'case-9' } })
	})

	it('closes without filing when the form is closed without a case', async () => {
		const wrapper = mount(NewCaseFromMessageDialog, { props: { object: ROW, objectId: 'entry-7' } })
		await flushPromises()

		wrapper.findComponent({ name: 'CnActionButtons' }).vm.formEntry = null
		await flushPromises()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeTruthy()
	})
})

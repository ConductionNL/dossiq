// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dossiq's own New case dialog, offered to OpenRegister's Mail panel: the
 * entry registers it for the case schema without loading it, the action is
 * the New case action of dossiq's own pages seeded with the mail's values,
 * and the host opens it through the library's action host and reports the
 * created case, or null when it closes without one.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { actionFromMail, findNewCaseAction } from '../../src/mailCreateDialog/newCaseAction.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

const mockOpenForm = vi.fn()
vi.mock('@conduction/nextcloud-vue', () => ({
	CnActionButtons: defineComponent({
		name: 'CnActionButtons',
		props: { actions: { type: Array, default: () => [] } },
		emits: ['created'],
		data: () => ({ formEntry: null }),
		methods: {
			openForm(entry) {
				mockOpenForm(entry)
				this.formEntry = entry
			},
		},
		render: () => h('div', { class: 'cn-action-buttons' }),
	}),
}))
vi.mock('../../src/publicPath.js', () => ({}))

afterEach(() => {
	delete window.OCA
	vi.resetModules()
	mockOpenForm.mockReset()
})

describe('the Mail-page entry', () => {
	it('offers a dialog for the case schema of the dossiq register, and keeps what others offered', async () => {
		const other = { register: 'x', schema: 'y', open: () => null }
		window.OCA = { OpenRegister: { mailCreateDialogs: [other] } }
		await import('../../src/mail-create-dialog.js')
		const offered = window.OCA.OpenRegister.mailCreateDialogs
		expect(offered[0]).toBe(other)
		expect(offered[1]).toMatchObject({ register: 'dossiq', schema: 'case' })
		expect(typeof offered[1].open).toBe('function')
	})

	it('creates the list when the panel has not loaded yet', async () => {
		await import('../../src/mail-create-dialog.js')
		expect(window.OCA.OpenRegister.mailCreateDialogs).toHaveLength(1)
	})
})

describe('the New case action from a mail', () => {
	it('is the New case open-form action of dossiq\'s own pages', () => {
		const action = findNewCaseAction(manifest)
		expect(action).toMatchObject({ id: 'new-case', type: 'open-form', register: 'dossiq', schema: 'case' })
		expect(action.includeFields).toContain('caseType')
		expect(findNewCaseAction({ pages: [{ config: { headerActions: [{ id: 'other', type: 'open-form' }] } }] })).toBeNull()
		expect(findNewCaseAction(null)).toBeNull()
	})

	it('seeds the mail\'s values over the action\'s own and stays on the page', () => {
		const action = { id: 'new-case', type: 'open-form', onSuccessRoute: 'CaseDetail', props: { assignee: '@me', confidentiality: 'vertrouwelijk' } }
		const fromMail = actionFromMail(action, { title: 'Street light', confidentiality: 'openbaar' })
		expect(fromMail.props).toEqual({ assignee: '@me', confidentiality: 'openbaar', title: 'Street light' })
		expect(fromMail.onSuccessRoute).toBeUndefined()
		expect(actionFromMail({ id: 'new-case' }, undefined).props).toEqual({})
	})
})

describe('MailNewCaseHost', () => {
	/**
	 * Mount the host with an action.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountHost() {
		const { default: MailNewCaseHost } = await import('../../src/mailCreateDialog/MailNewCaseHost.vue')
		const wrapper = mount(MailNewCaseHost, { props: { action: { id: 'new-case', type: 'open-form' } } })
		await flushPromises()
		return wrapper
	}

	it('opens the action\'s form on mount and reports the created case once', async () => {
		const wrapper = await mountHost()
		expect(mockOpenForm).toHaveBeenCalledWith({ id: 'new-case', type: 'open-form' })
		const buttons = wrapper.findComponent({ name: 'CnActionButtons' })
		buttons.vm.$emit('created', { id: 'case-1' })
		buttons.vm.formEntry = null
		await nextTick()
		expect(wrapper.emitted('done')).toEqual([[{ id: 'case-1' }]])
	})

	it('reports null when the form closes without a case', async () => {
		const wrapper = await mountHost()
		wrapper.findComponent({ name: 'CnActionButtons' }).vm.formEntry = null
		await nextTick()
		expect(wrapper.emitted('done')).toEqual([[null]])
	})
})

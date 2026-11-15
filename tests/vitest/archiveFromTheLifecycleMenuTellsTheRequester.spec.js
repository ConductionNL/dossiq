// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Archiving from the lifecycle menu offers to tell the requester, as the case
 * page's Archive this case dialog does (board DqArchiveren: "Sanne de Vries
 * laten weten dat de zaak is afgehandeld", ticked), posts the choice as
 * `notifyRequester`, and says afterwards whether the requester was told.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'
import { archiveNotice } from '../../src/utils/caseLifecycleHelpers.js'

vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

const ARCHIVE = { kind: 'ending', id: 'archive' }

/**
 * The notify input of an act's form.
 *
 * @param {object} act The act.
 * @param {object} [scope] What the form is scoped by.
 * @return {object|undefined} The declaration.
 */
const notifyInput = (act, scope) => actForm(act, scope).inputs.find((row) => row.field === 'notifyRequester')

describe('the archive act form', () => {
	it('offers a ticked checkbox naming the requester, after the reason', () => {
		const { inputs } = actForm(ARCHIVE, { requester: 'Sanne de Vries' })
		expect(inputs.map((row) => row.field)).toEqual(['reason', 'notifyRequester'])
		expect(notifyInput(ARCHIVE, { requester: 'Sanne de Vries' })).toMatchObject({
			widget: 'checkbox',
			default: true,
			required: false,
			label: 'Let Sanne de Vries know the case is completed',
			testid: 'case-act-notify-requester',
		})
	})

	it('says "the requester" while the case is not read, and is offered on archive only', () => {
		expect(notifyInput(ARCHIVE).label).toBe('Let the requester know the case is completed')
		for (const id of ['finish', 'abort', 'suspend', 'extend', 'hold', 'unarchive']) {
			expect(notifyInput({ kind: 'gesture', id }), id).toBeUndefined()
		}
	})
})

describe('archiveNotice', () => {
	const t = (s) => s

	it('says the requester was told, or why not, and nothing without a notice', () => {
		expect(archiveNotice({ notice: { noticeStatus: 'sent' } }, t)).toEqual({ told: true, text: 'The case is archived. The requester was told.' })
		expect(archiveNotice({ notice: { noticeStatus: 'not-sent', noticeReason: 'No address.' } }, t))
			.toEqual({ told: false, text: 'The case is archived. The requester was not told. No address.' })
		expect(archiveNotice({ archiveStatus: 'archived' }, t)).toBeNull()
	})
})

describe('archiving from the lifecycle menu', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * The menu with Archiveren open.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function archiveForm() {
		axios.get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/lifecycle') ? {} : url.endsWith('/acts') ? { acts: [] } : { transitions: [] },
		}))
		const wrapper = shallowMount(CaseLifecycleMenuDialog, {
			props: { caseId: 'case-1' },
			global: { stubs: { CnDialog: { template: '<div><slot /><slot name="actions" /></div>' }, CnChoiceCards: true } },
		})
		await flushPromises()
		wrapper.vm.choose(ARCHIVE)
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it('starts ticked and posts notifyRequester with the reason, then says the requester was told', async () => {
		const wrapper = await archiveForm()
		expect(wrapper.vm.values.notifyRequester).toBe(true)

		wrapper.vm.values = { ...wrapper.vm.values, reason: 'Dossier compleet' }
		axios.post.mockResolvedValue({ data: { archiveStatus: 'archived', notice: { noticeStatus: 'sent' } } })
		await wrapper.vm.confirm()

		expect(axios.post.mock.calls[0][0]).toContain('/apps/dossiq/api/case/case-1/archive')
		expect(axios.post.mock.calls[0][1]).toMatchObject({ reason: 'Dossier compleet', notifyRequester: true })
		expect(showSuccess).toHaveBeenCalledWith('The case is archived. The requester was told.')
	})

	it('posts false when the handler unticks it, and says nothing when no notice came back', async () => {
		const wrapper = await archiveForm()
		wrapper.vm.values = { ...wrapper.vm.values, reason: 'Dossier compleet', notifyRequester: false }
		axios.post.mockResolvedValue({ data: { archiveStatus: 'archived' } })
		await wrapper.vm.confirm()
		expect(axios.post.mock.calls[0][1]).toMatchObject({ notifyRequester: false })
		expect(showSuccess).not.toHaveBeenCalled()
		expect(showWarning).not.toHaveBeenCalled()
	})
})

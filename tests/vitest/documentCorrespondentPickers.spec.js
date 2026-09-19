// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The document properties dialog offers the parties of the case, and posts
 * their identifiers.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS THE PAYLOAD. A picker that renders both
 * parties and then posts "Jan Jansen" draws exactly the right screen: the
 * handler picks a person, sees their name, presses Save, and stores a string
 * the People tab cannot resolve and the dossier filter cannot match. So this
 * reads the body of the PATCH rather than the options on screen.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DocumentMetadataDialog from '../../src/modals/DocumentMetadataDialog.vue'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn(), post: vi.fn() },
}))

const PARTIES = {
	primary: 'party-jan',
	results: [
		{
			partyUuid: 'party-jan',
			displayName: 'Jan Jansen',
			email: 'jan@example.org',
		},
		{ partyUuid: 'party-council', displayName: 'Gemeente Utrecht' },
	],
}

const RECORD = {
	id: 'inf-1',
	fileId: 4711,
	title: 'Aanvraag',
	informatieobjecttype: 'iot-1',
	vertrouwelijkheidaanduiding: 'zaakvertrouwelijk',
	direction: 'incoming',
	keywords: [],
	sender: 'party-jan',
	recipients: [],
}

/**
 * The dialog on an existing file of a case with two parties.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDialog() {
	axios.get.mockImplementation((url) => {
		if (String(url).includes('/parties')) {
			return Promise.resolve({ data: PARTIES })
		}
		if (String(url).includes('/dossier')) {
			return Promise.resolve({ data: { informatieobjecten: [RECORD] } })
		}
		return Promise.resolve({ data: [] })
	})
	axios.patch.mockResolvedValue({ data: { updated: true } })

	const wrapper = mount(DocumentMetadataDialog, {
		props: { caseId: 'case-1', fileId: 4711, fileName: 'aanvraag.pdf' },
		global: {
			mocks: { t: (app, text) => text, $route: { params: { id: 'case-1' } } },
			stubs: {
				NcModal: { template: '<div><slot /></div>' },
				NcButton: { template: '<button><slot /></button>' },
				NcProgressBar: true,
				NcTextField: true,
				NcTextArea: true,
				NcSelect: {
					props: ['modelValue', 'options', 'inputLabel'],
					template: '<div class="nc-select" :data-label="inputLabel" />',
				},
			},
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('the document properties dialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers exactly the parties of the case, once each', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.vm.partyOptions.map((option) => option.label)).toEqual([
			'Jan Jansen',
			'Gemeente Utrecht',
		])
	})

	it('shows the sender the record already stores', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.vm.sender).toMatchObject({
			id: 'party-jan',
			label: 'Jan Jansen',
		})
	})

	it('posts the party identifier and never the name it rendered', async () => {
		const wrapper = await mountDialog()
		await wrapper.setData({
			selectedDirection: 'outgoing',
			recipients: [{ id: 'party-council', label: 'Gemeente Utrecht' }],
		})

		await wrapper.vm.submit()

		const [, body] = axios.patch.mock.calls[0]
		expect(body.recipients).toEqual(['party-council'])
		// 🔴 Outgoing carries addressees and no sender, so the sender the
		// record held is posted away rather than left to contradict the
		// direction the person just chose.
		expect(body.sender).toBe('')
	})

	it('posts the sender and no addressees on an incoming document', async () => {
		const wrapper = await mountDialog()
		await wrapper.setData({
			selectedDirection: 'incoming',
			recipients: [{ id: 'party-council', label: 'Gemeente Utrecht' }],
		})

		await wrapper.vm.submit()

		const [, body] = axios.patch.mock.calls[0]
		expect(body.sender).toBe('party-jan')
		expect(body.recipients).toEqual([])
	})

	it('says so when the case has no parties rather than offering a text box', async () => {
		axios.get.mockImplementation((url) => {
			if (String(url).includes('/parties')) {
				return Promise.reject(new Error('OpenRegister said no'))
			}
			if (String(url).includes('/dossier')) {
				return Promise.resolve({ data: { informatieobjecten: [RECORD] } })
			}
			return Promise.resolve({ data: [] })
		})
		axios.patch.mockResolvedValue({ data: { updated: true } })

		const wrapper = mount(DocumentMetadataDialog, {
			props: { caseId: 'case-1', fileId: 4711, fileName: 'aanvraag.pdf' },
			global: {
				mocks: {
					t: (app, text) => text,
					$route: { params: { id: 'case-1' } },
				},
				stubs: {
					NcModal: { template: '<div><slot /></div>' },
					NcButton: { template: '<button><slot /></button>' },
					NcProgressBar: true,
					NcTextField: true,
					NcTextArea: true,
					NcSelect: true,
				},
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.partyOptions).toEqual([])
		expect(wrapper.find('[data-testid="document-no-parties"]').exists()).toBe(
			true,
		)
	})
})

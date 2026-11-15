// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * GatherDocumentsDialog, the paths `gatherDocumentsDialog.spec.js` leaves
 * out: closing through the dialog and the Close button; the reasons for the
 * other two kinds of unconnected source; sources that cannot be read;
 * unticking and re-ticking a source, choosing a custodian and an end date
 * through the form, and an add where nothing is refused closing the dialog;
 * a notice code the dialog has no sentence for; and a search refused
 * locally before anything is asked.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GatherDocumentsDialog from '../../src/dialogs/GatherDocumentsDialog.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CASE_ID = '11111111-1111-4111-8111-111111111111'

const SOURCES = [
	{
		id: 'files',
		label: 'Files',
		kind: 'platform',
		available: true,
		reason: '',
		search: { provider: 'files', filters: {} },
	},
	{
		id: 'microsoft365',
		label: 'SharePoint, Teams and mail',
		kind: 'integriq',
		available: true,
		reason: '',
		search: null,
	},
]

const PLAN = {
	recorded: true,
	plan: {
		custodians: [{ name: 'Wethouder Ruimte' }, { name: 'Afdeling Vergunningen' }],
		terms: 'Stationsweg',
		periodFrom: '',
		periodTo: '',
	},
}

const stubs = {
	NcDialog: {
		emits: ['closing'],
		template:
			'<div><button data-testid="stub-dialog-closing" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcSelect: {
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		template:
			'<select :aria-label="inputLabel" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o" :value="o">{{ o }}</option></select>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<label><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}

/**
 * The axios double.
 *
 * @return {Promise<object>} The mocked axios.
 */
async function axiosDouble() {
	return (await import('@nextcloud/axios')).default
}

/**
 * Mount the dialog with GET /woo/sources answering `sources` (or failing
 * with it when it is an Error-shaped rejection) and the plan recorded.
 *
 * @param {object} options The answers.
 * @param {Array<object>|object} [options.sources] The sources, or `{ reject }`.
 * @param {Array<string>} [options.notices] The integriq notices.
 * @return {Promise<{wrapper: object, axios: object}>} The dialog and axios.
 */
async function mountDialog({ sources = SOURCES, notices = [] } = {}) {
	const axios = await axiosDouble()
	axios.get.mockImplementation((url) => {
		if (String(url).endsWith('/woo/plan')) {
			return Promise.resolve({ data: PLAN })
		}
		if (String(url).endsWith('/woo/sources')) {
			return sources.reject
				? Promise.reject(sources.reject)
				: Promise.resolve({ data: { sources } })
		}
		if (String(url).includes('search/providers/files/search')) {
			return Promise.resolve({
				data: {
					ocs: {
						data: {
							entries: [
								{
									title: 'notulen.pdf',
									attributes: { fileId: '11', path: '/Team/notulen.pdf' },
								},
							],
						},
					},
				},
			})
		}
		return Promise.reject(new Error(`unexpected GET ${url}`))
	})
	axios.post.mockImplementation((url) => {
		if (String(url).endsWith('/woo/collection/queries')) {
			return Promise.resolve({ data: {} })
		}
		if (String(url).endsWith('/woo/sources/search')) {
			return Promise.resolve({ data: { rows: [], remaining: 0, notices } })
		}
		if (String(url).endsWith('/woo/sources/add')) {
			return Promise.resolve({
				data: {
					results: [
						{ source: 'files', key: '11', status: 'added', documentId: 'd1' },
					],
				},
			})
		}
		return Promise.reject(new Error(`unexpected POST ${url}`))
	})

	const wrapper = mount(GatherDocumentsDialog, {
		props: { caseId: CASE_ID },
		global: { stubs },
	})
	await flushPromises()
	return { wrapper, axios }
}

describe('GatherDocumentsDialog — remaining paths', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('emits close from the dialog and from the Close button', async () => {
		const { wrapper } = await mountDialog()

		await wrapper.find('[data-testid="stub-dialog-closing"]').trigger('click')
		await wrapper.find('[data-testid="gather-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('names why a source without search, or with an unknown reason, cannot answer', async () => {
		const { wrapper } = await mountDialog({
			sources: [
				{ ...SOURCES[1], available: false, reason: 'search-not-offered' },
				{ ...SOURCES[0], available: false, reason: 'something-else' },
			],
		})

		expect(
			wrapper.find('[data-testid="gather-source-reason-microsoft365"]').text(),
		).toBe('Not connected: this integriq version cannot search.')
		expect(
			wrapper.find('[data-testid="gather-source-reason-files"]').text(),
		).toBe('Not connected.')
	})

	it('shows the server sentence, or the fallback, when the sources cannot be read', async () => {
		const first = await mountDialog({
			sources: { reject: { response: { data: { message: 'No Woo case.' } } } },
		})
		expect(first.wrapper.find('[data-testid="gather-error"]').text()).toBe(
			'No Woo case.',
		)

		const second = await mountDialog({ sources: { reject: new Error('down') } })
		expect(second.wrapper.find('[data-testid="gather-error"]').text()).toBe(
			'The sources could not be read.',
		)
		expect(second.wrapper.find('[data-testid="gather-source-files"]').exists()).toBe(
			false,
		)
	})

	it('searches only the ticked sources with the chosen period, and closes when every pick is added', async () => {
		const { wrapper, axios } = await mountDialog()
		const { emit } = await import('@nextcloud/event-bus')

		// Untick integriq, untick files and tick it again.
		await wrapper
			.find('[data-testid="gather-source-microsoft365"] input')
			.setValue(false)
		await wrapper.find('[data-testid="gather-source-files"] input').setValue(false)
		expect(
			wrapper.find('[data-testid="gather-search"]').attributes('disabled'),
		).toBeDefined()
		await wrapper.find('[data-testid="gather-source-files"] input').setValue(true)
		await wrapper.find('[data-testid="gather-source-files"] input').setValue(true)

		await wrapper.find('[data-testid="gather-to"]').setValue('2025-12-31')
		await wrapper
			.find('[data-testid="gather-custodian"]')
			.setValue('Afdeling Vergunningen')
		await wrapper.find('[data-testid="gather-search"]').trigger('click')
		await flushPromises()

		const searchCalls = axios.get.mock.calls.filter(([url]) =>
			String(url).includes('search/providers/'),
		)
		expect(searchCalls).toEqual([
			[
				expect.stringContaining('search/providers/files/search'),
				{ params: { term: 'Stationsweg', limit: 50, until: '2025-12-31' } },
			],
		])
		expect(
			axios.post.mock.calls.some(([url]) =>
				String(url).endsWith('/woo/sources/search'),
			),
		).toBe(false)

		await wrapper.find('[data-testid="gather-pick-files-11"] input').setValue(true)
		await wrapper.find('[data-testid="gather-add"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenLastCalledWith(
			`/index.php/apps/dossiq/api/cases/${CASE_ID}/woo/sources/add`,
			{
				terms: 'Stationsweg',
				picks: [
					{
						source: 'files',
						custodian: 'Afdeling Vergunningen',
						key: '11',
						location: '/Team/notulen.pdf',
					},
				],
			},
		)
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('shows a notice code it has no sentence for as the code itself', async () => {
		const { wrapper } = await mountDialog({ notices: ['mailbox-archived'] })

		await wrapper.find('[data-testid="gather-search"]').trigger('click')
		await flushPromises()

		expect(
			wrapper.find('[data-testid="gather-results-microsoft365"]').text(),
		).toContain('mailbox-archived')
	})

	it('searches nothing without terms', async () => {
		const { wrapper, axios } = await mountDialog()
		await wrapper.find('[data-testid="gather-terms"]').setValue('   ')
		axios.get.mockClear()

		await wrapper.vm.search()

		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="gather-results-files"]').exists()).toBe(
			false,
		)
	})
})

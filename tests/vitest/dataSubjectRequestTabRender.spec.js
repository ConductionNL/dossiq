// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What DataSubjectRequestTab draws: every protected item in full under the
 * counts, the outcome note, the server's refusal with its rule, the run button
 * only once a preview exists, and the export's expired, assembling and
 * downloadable states.
 *
 * @spec openspec/specs/avg-processing-surface/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

function slotted (name, props = []) {
  return {
	name,
	props,
	emits: ['click'],
	setup(p, { slots, emit }) {
		return () => h('div', { class: name, onClick: () => emit('click') }, [slots.default?.()])
	},
}
}
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, s, vars = {}) => s.replace(/\{(\w+)\}/g, (_m, k) => String(vars[k] ?? `{${k}}`)),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: slotted('NcButton', ['disabled', 'variant', 'href']) }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: slotted('NcEmptyContent', ['name', 'description']) }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: slotted('NcNoteCard', ['type']) }))
vi.mock('../../src/services/dataSubjectRequestApi.js', () => ({
	fetchSubjectExportState: vi.fn(),
	previewErasure: vi.fn(),
	refusalOf: (error) => ({ message: error.message, rule: 'rule: ' + error.message }),
	requestSubjectExport: vi.fn(),
	runErasure: vi.fn(),
	subjectExportDownloadUrl: (id) => `/download/${id}`,
}))

const dsr = await import('../../src/services/dataSubjectRequestApi.js')
const { default: DataSubjectRequestTab } = await import('../../src/views/cases/components/DataSubjectRequestTab.vue')

/**
 * Mount the tab and let it load.
 *
 * @param {object} object The case.
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(object) {
	const wrapper = mount(DataSubjectRequestTab, { props: { objectId: 'c1', object } })
	await flushPromises()
	return wrapper
}

const buttons = (wrapper) => wrapper.findAllComponents({ name: 'NcButton' })
const notes = (wrapper) => wrapper.findAllComponents({ name: 'NcNoteCard' })

describe('DataSubjectRequestTab on an erasure', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers only the preview until one is taken', async () => {
		const wrapper = await mountTab({ dataSubjectRequestType: 'verwijdering' })

		expect(wrapper.find('h3').text()).toBe('Erasure')
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No preview yet')
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Take the preview'])
		expect(dsr.fetchSubjectExportState).not.toHaveBeenCalled()
	})

	it('lists the summed counts and every protected item in full', async () => {
		const wrapper = await mountTab({
			dataSubjectRequestType: 'verwijdering',
			erasureCounts: {
				erasable: { cases: 2, files: '3' },
				pseudonymised: { cases: 1 },
				protected: { cases: 1, files: 1 },
			},
			erasureProtected: [
				{ name: 'Besluit 2024-17', ground: 'Archiefwet', basis: 'art. 3', action: 'Kept until 2034' },
				{ name: 'Factuur 88', ground: 'Fiscale bewaarplicht', basis: 'AWR 52', action: 'Pseudonymise after 7 years' },
			],
		})

		expect(wrapper.findAll('.dsr-tab__counts li').map((li) => li.text().replace(/\s+/g, ' '))).toEqual([
			'Erasable: 5',
			'To pseudonymise: 1',
			'Protected: 2',
		])
		const rows = wrapper.findAll('.dsr-tab__protected li')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('strong').text()).toBe('Besluit 2024-17')
		expect(rows[1].findAll('span').map((s) => s.text())).toEqual(['Fiscale bewaarplicht', 'AWR 52'])
		expect(rows[1].find('em').text()).toBe('Pseudonymise after 7 years')
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Take the preview', 'Run the approved erasure'])
		expect(buttons(wrapper)[1].props('variant')).toBe('warning')
	})

	it('runs the erasure from its button and shows an unfinished outcome as a warning', async () => {
		dsr.runErasure.mockResolvedValue({ complete: false, withheld: [1, 2], refused: [], failed: [3] })
		const wrapper = await mountTab({ dataSubjectRequestType: 'verwijdering', erasureCounts: { erasable: { cases: 1 } } })

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(dsr.runErasure).toHaveBeenCalledWith('c1')
		const note = notes(wrapper)[0]
		expect(note.props('type')).toBe('warning')
		expect(note.text()).toBe('The erasure did not finish. 3 records still hold this person.')
	})

	it('shows a complete outcome as a success', async () => {
		const wrapper = await mountTab({ dataSubjectRequestType: 'verwijdering', erasureOutcome: { complete: true } })

		expect(notes(wrapper)[0].props('type')).toBe('success')
		expect(notes(wrapper)[0].text()).toBe('The platform reports the erasure complete.')
	})

	it('shows the server\'s refusal with its rule and frees the buttons', async () => {
		dsr.previewErasure.mockRejectedValue(new Error('not approved'))
		const wrapper = await mountTab({ dataSubjectRequestType: 'verwijdering' })

		await buttons(wrapper)[0].trigger('click')
		await flushPromises()

		const refusal = notes(wrapper)[0]
		expect(refusal.props('type')).toBe('warning')
		expect(refusal.text()).toContain('not approved')
		expect(refusal.find('.dsr-tab__rule').text()).toBe('rule: not approved')
		expect(buttons(wrapper)[0].props('disabled')).toBe(false)
	})
})

describe('DataSubjectRequestTab on other cases', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('says a case without a request has nothing to erase or export', async () => {
		const wrapper = await mountTab({ dataSubjectRequestType: 'klacht' })

		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('Not a data subject request')
		expect(buttons(wrapper)).toHaveLength(0)
		expect(dsr.fetchSubjectExportState).not.toHaveBeenCalled()
	})

	it('says the file is still being assembled while it cannot be downloaded', async () => {
		dsr.fetchSubjectExportState.mockResolvedValue({ exportId: 'e1', downloadable: false, expired: false })
		const wrapper = await mountTab({ dataSubjectRequestType: 'inzage' })

		expect(wrapper.find('h3').text()).toBe('Subject export')
		expect(wrapper.text()).toContain('The platform is still assembling the file.')
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Ask for the export'])
	})

	it('offers the download once the export is ready', async () => {
		dsr.fetchSubjectExportState.mockResolvedValue({ exportId: 'e2', downloadable: true, expired: false })
		const wrapper = await mountTab({ dataSubjectRequestType: 'correctie' })

		const download = buttons(wrapper)[1]
		expect(download.text()).toBe('Download the export')
		expect(download.props('href')).toBe('/download/e2')
		expect(download.props('variant')).toBe('primary')
		expect(wrapper.text()).not.toContain('still assembling')
	})

	it('tells the handler an expired export must be asked for again', async () => {
		dsr.fetchSubjectExportState.mockResolvedValue({ exportId: 'e3', downloadable: false, expired: true })
		const wrapper = await mountTab({ dataSubjectRequestType: 'inzage' })

		expect(notes(wrapper)[0].text()).toBe('This export expired. Ask for it again.')
		expect(wrapper.text()).not.toContain('still assembling')
	})

	it('asks for the export from its button and reads the new state', async () => {
		dsr.fetchSubjectExportState
			.mockResolvedValueOnce({ exportId: '', downloadable: false, expired: false })
			.mockResolvedValueOnce({ exportId: 'e4', downloadable: true, expired: false })
		const wrapper = await mountTab({ dataSubjectRequestType: 'inzage' })

		await buttons(wrapper)[0].trigger('click')
		await flushPromises()

		expect(dsr.requestSubjectExport).toHaveBeenCalledWith('c1')
		expect(buttons(wrapper)[1].props('href')).toBe('/download/e4')
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Overview cards a case type declares: read from the case's type when the
 * case loads or changes type, drawn as titled cards with their pill, intro,
 * rows and note, and nothing at all when the type declares none or cannot be
 * read.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

const { default: CaseOverviewSections } = await import('../../src/views/cases/components/CaseOverviewSections.vue')

const TYPE_URL = '/index.php/apps/openregister/api/objects/dossiq/caseType/'

const declared = [
	{
		id: 'complaint',
		title: 'Klacht',
		pillField: 'severity',
		intro: 'Zoals de indiener het beschreef.',
		columns: 2,
		rows: [
			{ label: 'Onderwerp', field: 'subject' },
			{ label: 'Afdeling', field: 'department' },
			{ label: 'Kanaal', value: 'Webformulier' },
		],
		note: 'Afhandeling binnen zes weken.',
	},
	{ id: 'empty', title: 'Leeg', rows: [] },
	{ id: 'untitled', rows: [{ label: 'X', value: 'x' }] },
]

/**
 * Mount the cards for a case.
 *
 * @param {object|null} objectData The case.
 * @return {Promise<object>} The wrapper.
 */
async function mountCards(objectData) {
	const wrapper = mount(CaseOverviewSections, { props: { objectData } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
})

describe('CaseOverviewSections', () => {
	it('reads the sections of the case type and draws each titled card', async () => {
		axios.get.mockResolvedValue({ data: { overviewSections: declared } })

		const wrapper = await mountCards({ caseType: 'ct 1', subject: 'Geluidsoverlast', properties: [{ name: 'severity', value: 'Hoog' }] })

		expect(axios.get).toHaveBeenCalledWith(TYPE_URL + 'ct%201')
		const cards = wrapper.findAll('[data-testid="case-overview-section"]')
		expect(cards).toHaveLength(2)

		const first = cards[0]
		expect(first.attributes('aria-labelledby')).toBe('case-section-complaint')
		expect(first.find('h3').text()).toBe('Klacht')
		expect(first.find('.case-overview-sections__pill').text()).toBe('Hoog')
		expect(first.findAll('.case-overview-sections__note').map((n) => n.text())).toEqual(['Zoals de indiener het beschreef.', 'Afhandeling binnen zes weken.'])
		expect(first.find('dl').classes()).toContain('case-overview-sections__rows--two')
		expect(first.findAll('dt').map((n) => n.text())).toEqual(['Onderwerp', 'Afdeling', 'Kanaal'])
		expect(first.findAll('dd').map((n) => n.text())).toEqual(['Geluidsoverlast', '—', 'Webformulier'])

		const second = cards[1]
		expect(second.find('h3').text()).toBe('Leeg')
		expect(second.find('dl').exists()).toBe(false)
		expect(second.find('.case-overview-sections__pill').exists()).toBe(false)
		expect(second.find('.case-overview-sections__note').exists()).toBe(false)
	})

	it('reads the type id from a case type object', async () => {
		axios.get.mockResolvedValue({ data: { overviewSections: [] } })

		await mountCards({ caseType: { id: 'ct-2', title: 'Klacht' } })

		expect(axios.get).toHaveBeenCalledWith(TYPE_URL + 'ct-2')
	})

	it('draws nothing when the type declares no sections or cannot be read', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const none = await mountCards({ caseType: 'ct-1' })
		expect(none.find('[data-testid="case-overview-sections"]').exists()).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('403'))
		const refused = await mountCards({ caseType: 'ct-1' })
		expect(refused.find('[data-testid="case-overview-sections"]').exists()).toBe(false)
	})

	it('asks nothing for a case without a type, and draws nothing without a case', async () => {
		const untyped = await mountCards({ title: 'Zonder type' })
		const missing = await mountCards(null)

		expect(axios.get).not.toHaveBeenCalled()
		expect(untyped.html()).not.toContain('case-overview-section')
		expect(missing.html()).not.toContain('case-overview-section')
	})

	it('reloads the sections when the case changes type, and clears them when it loses one', async () => {
		axios.get.mockResolvedValueOnce({ data: { overviewSections: [{ id: 'a', title: 'Eerste', rows: [] }] } })
		const wrapper = await mountCards({ caseType: 'ct-1' })
		expect(wrapper.find('h3').text()).toBe('Eerste')

		axios.get.mockResolvedValueOnce({ data: { overviewSections: [{ id: 'b', title: 'Tweede', rows: [] }] } })
		await wrapper.setProps({ objectData: { caseType: 'ct-2' } })
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(TYPE_URL + 'ct-2')
		expect(wrapper.find('h3').text()).toBe('Tweede')

		await wrapper.setProps({ objectData: { caseType: '' } })
		await flushPromises()
		expect(wrapper.find('[data-testid="case-overview-sections"]').exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(2)
	})
})

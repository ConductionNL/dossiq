// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakLocatie: the search field is labelled "Adres zoeken" above the field,
 * and the map is a preview of the chosen address without fit, locate or
 * fullscreen buttons.
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import CaseLocationDialog from '../../src/dialogs/CaseLocationDialog.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { results: [] } })), post: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

function mountDialog() {
	return mount(CaseLocationDialog, {
		props: { caseId: 'case-1' },
		global: {
			stubs: {
				CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				CnMapWidget: { name: 'CnMapWidget', props: ['fitControl', 'locateControl', 'fullscreenControl', 'zoomControl'], template: '<div class="map" />' },
				CnAddressSearch: { name: 'CnAddressSearch', props: ['inputLabel', 'labelOutside'], template: '<div class="search" />' },
				NcButton: { template: '<button><slot /></button>' },
				NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
				NcNoteCard: { template: '<div><slot /></div>' },
			},
			mocks: { $route: { params: {} } },
		},
	})
}

describe('the location dialog as the board draws it', () => {
	it('labels the search field Adres zoeken, above the field', () => {
		const search = mountDialog().findComponent({ name: 'CnAddressSearch' })
		expect(nl[search.props('inputLabel')]).toBe('Adres zoeken')
		// NcSelect draws its label above the field only when labelOutside is
		// false; true means the caller draws one, and nothing did.
		expect(search.props('labelOutside')).not.toBe(true)
	})

	it('draws the map as a preview without fit, locate or fullscreen buttons', () => {
		const map = mountDialog().findComponent({ name: 'CnMapWidget' })
		expect(map.props('fitControl')).toBe(false)
		expect(map.props('locateControl')).toBe(false)
		expect(map.props('fullscreenControl')).toBe(false)
		expect(map.props('zoomControl')).toBe(false)
	})
})

describe('the Locaties card as DqZaakMeer draws it', () => {
	// DqZaak and DqZaakMeer draw the card's map 120px high with zoom (+, -) and
	// fit-all buttons, and no locate or fullscreen button.
	it('is a short map with zoom and fit, without locate or fullscreen', () => {
		const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
		const page = layout.pages.find((entry) => entry.id === 'CaseDetail')
		const card = page.configAppend.widgets.find((widget) => widget.id === 'case-locations-card')
		expect(card.content).toMatchObject({ height: '120px', zoomControl: true, fitControl: true, locateControl: false, fullscreenControl: false })
	})
})

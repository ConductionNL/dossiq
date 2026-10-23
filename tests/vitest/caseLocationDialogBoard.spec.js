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
				CnMapWidget: { name: 'CnMapWidget', props: ['fitControl', 'locateControl', 'fullscreenControl'], template: '<div class="map" />' },
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
		expect(search.props('labelOutside')).toBe(true)
	})

	it('draws the map as a preview without fit, locate or fullscreen buttons', () => {
		const map = mountDialog().findComponent({ name: 'CnMapWidget' })
		expect(map.props('fitControl')).toBe(false)
		expect(map.props('locateControl')).toBe(false)
		expect(map.props('fullscreenControl')).toBe(false)
	})
})

// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Adding a location to a case (board DqZaakLocatie): the address comes from
 * the PDOK search, the registers are asked about it, and saving writes one
 * case-location on the case.
 *
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import CaseLocationDialog from '../../src/dialogs/CaseLocationDialog.vue'
import { caseLocationRecord, parcelOf } from '../../src/services/caseLocationApi.js'

const ROOT = path.resolve(__dirname, '../..')

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('../../src/services/wozApi.js', () => ({
	lookupWozValueByNummeraanduiding: vi.fn(() => Promise.resolve({
		wozObject: { value: 412000, waardepeildatum: '2026-01-01', gebruiksdoel: ['woonfunctie'] },
	})),
}))
vi.mock('../../src/services/brkApi.js', () => ({
	lookupParcel: vi.fn(() => Promise.resolve({
		parcel: { kadastraleAanduiding: 'Zuiddrecht K 1234', zakelijkGerechtigden: [{}, {}] },
	})),
}))

const CHOSEN = {
	result: {
		weergavenaam: 'Molenweg 4, 3312KL Zuiddrecht',
		nummeraanduiding_id: '0642200000012345',
		gekoppeld_perceel: ['ZDR00-K-1234'],
	},
	coordinates: { lat: 51.8, lng: 4.67 },
	address: 'Molenweg 4, 3312KL Zuiddrecht',
}

/**
 * Mount the dialog with the library dialog and map stubbed.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation((url) => Promise.resolve({
		data: String(url).includes('/case/')
			? { title: 'Kapvergunning Molenweg 4', identifier: '2026-0090' }
			: { results: [{ id: 'l1', title: 'Luchtfoto (PDOK)', url: 'https://x', layerName: 'a', isDefault: true }] },
	}))
	axios.post.mockResolvedValue({ data: { id: 'loc-1' } })
	return mount(CaseLocationDialog, {
		props: { caseId: 'case-1' },
		global: {
			stubs: {
				CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				CnMapWidget: { template: '<div class="map" />' },
				CnAddressSearch: { template: '<div class="search" />' },
				NcButton: { template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
				NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
				NcNoteCard: { template: '<div><slot /></div>' },
			},
			mocks: { $route: { params: {} } },
		},
	})
}

describe('the parcel reference of a PDOK result', () => {
	it('splits gemeente code, section and number', () => {
		expect(parcelOf(CHOSEN.result)).toEqual({
			kadastraleGemeenteCode: 'ZDR00',
			sectie: 'K',
			perceelnummer: '1234',
			label: 'ZDR00-K-1234',
		})
	})

	it('names none for a result without a parcel', () => {
		expect(parcelOf({})).toBeNull()
		expect(parcelOf({ gekoppeld_perceel: 'nonsense' })).toBeNull()
	})
})

describe('the case-location record', () => {
	it('carries the case, the address, the point and both register references', () => {
		expect(caseLocationRecord('case-1', CHOSEN)).toEqual({
			case: 'case-1',
			label: 'Molenweg 4, 3312KL Zuiddrecht',
			formattedAddress: 'Molenweg 4, 3312KL Zuiddrecht',
			latitude: 51.8,
			longitude: 4.67,
			addressDesignationId: '0642200000012345',
			parcelId: 'ZDR00-K-1234',
			source: 'pdok',
		})
	})
})

describe('CaseLocationDialog', () => {
	it('heads the dialog with the case it adds the location to', async () => {
		const wrapper = await mountDialog()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(wrapper.vm.eyebrow).toBe('Kapvergunning Molenweg 4, location')
	})

	it('will not save before an address is chosen', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.find('[data-testid="case-location-save"]').attributes('disabled')).toBeDefined()
	})

	it('shows what the registers say and saves one location on the case', async () => {
		const wrapper = await mountDialog()
		await wrapper.vm.onSelect(CHOSEN)
		await wrapper.vm.$nextTick()
		const cadastre = wrapper.find('[data-testid="case-location-cadastre"]').text()
		expect(cadastre).toContain('Zuiddrecht K 1234')
		expect(cadastre).toContain('2')
		expect(wrapper.find('[data-testid="case-location-bag"]').text()).toContain('woonfunctie')

		const axios = (await import('@nextcloud/axios')).default
		await wrapper.vm.save()
		const [url, body] = axios.post.mock.calls.at(-1)
		expect(url).toContain('/apps/openregister/api/objects/dossiq/case-location')
		expect(body.case).toBe('case-1')
		expect(body.addressDesignationId).toBe('0642200000012345')
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('draws a WFS layer as WFS, whatever the case of its stored type', async () => {
		const wrapper = await mountDialog()
		await new Promise((resolve) => setTimeout(resolve))
		wrapper.vm.layers = [{ id: 'w1', title: 'Bomen', type: 'WFS', url: 'https://x', layerName: 'b' }]
		wrapper.vm.shown = ['w1']
		expect(wrapper.vm.activeLayers[0].type).toBe('wfs')
	})

	it('is a registered modal the simple case page opens from Meer', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
		expect(registry).toContain('CaseLocationDialog: {')
		const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
		const overlay = simple.pages.find((page) => page.id === 'CaseDetail')
		const action = overlay.configAppend.headerActions.find((entry) => entry.id === 'add-location')
		expect(action).toMatchObject({ type: 'open-modal', target: 'CaseLocationDialog', group: 'Case' })
		expect(action.visibleWhen).toEqual({ field: '@self.archived', op: 'empty' })
	})
})

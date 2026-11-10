// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `caseLocationRecord`
 * (src/services/caseLocationApi.js): the record saved for a chosen and for a
 * typed address.
 *
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import { describe, expect, it } from 'vitest'
import { caseLocationRecord } from '../../src/services/caseLocationApi.js'

describe('case location record golden', () => {
	it('saves a chosen BAG address with its coordinates and parcel', () => {
		expect(caseLocationRecord('case-1', {
			result: { weergavenaam: 'Stationsweg 1', nummeraanduiding_id: '0518200000', gekoppeld_perceel: ['ZDR00-K-1234'] },
			coordinates: { lat: 52.1, lng: 4.3 },
			address: 'Stationsweg 1, Den Haag',
		})).toEqual({
			case: 'case-1',
			label: 'Stationsweg 1, Den Haag',
			formattedAddress: 'Stationsweg 1, Den Haag',
			latitude: 52.1,
			longitude: 4.3,
			addressDesignationId: '0518200000',
			parcelId: 'ZDR00-K-1234',
			source: 'bag',
		})
	})

	it('falls back to the result name, and to nothing', () => {
		expect(caseLocationRecord('c', { result: { weergavenaam: 'Naam' }, coordinates: { lat: 'x', lng: Infinity }, address: '' })).toEqual({
			case: 'c',
			label: 'Naam',
			formattedAddress: 'Naam',
			latitude: null,
			longitude: null,
			addressDesignationId: null,
			parcelId: null,
			source: 'free',
		})
	})

	it.each([[null], [undefined], [{}], [{ result: null, coordinates: null }]])('saves a typed address as free (%j)', (chosen) => {
		expect(caseLocationRecord('c', chosen)).toEqual({
			case: 'c',
			label: '',
			formattedAddress: '',
			latitude: null,
			longitude: null,
			addressDesignationId: null,
			parcelId: null,
			source: 'free',
		})
	})

	it('keeps a zero coordinate and an empty designation', () => {
		const record = caseLocationRecord('c', { result: { nummeraanduiding_id: '' }, coordinates: { lat: 0, lng: -0.5 }, address: 'A' })
		expect(record.latitude).toBe(0)
		expect(record.longitude).toBe(-0.5)
		expect(record.addressDesignationId).toBeNull()
		expect(record.source).toBe('free')
	})
})

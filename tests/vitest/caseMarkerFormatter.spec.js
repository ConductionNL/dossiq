/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `caseMarkerFormatter`
 * (src/services/mapFormatters.js): Point and Polygon geometry, every
 * rejection path, and the shape of the marker descriptor.
 */

import { describe, expect, it } from 'vitest'
import { caseMarkerFormatter } from '../../src/services/mapFormatters.js'

function caseWith(geometry) {
	return {
		id: 'c-1',
		title: 'Dakkapel',
		identifier: 'Z-001',
		status: 'open',
		geometry,
	}
}

describe('caseMarkerFormatter', () => {
	it('builds the full marker descriptor for a Point', () => {
		expect(caseMarkerFormatter(caseWith({ type: 'Point', coordinates: [5.1, 52.3] }))).toEqual({
			lat: 52.3,
			lon: 5.1,
			color: 'var(--color-status-info)',
			icon: 'map-marker',
			popup: { title: 'Dakkapel', subtitle: 'Z-001', status: 'open' },
			onClick: { route: 'CaseDetail', params: { id: 'c-1' } },
		})
	})

	it('uses the mean of the valid outer-ring pairs for a Polygon', () => {
		const marker = caseMarkerFormatter(caseWith({
			type: 'Polygon',
			coordinates: [[[4, 50], [6, 52], 'junk', [Number.NaN, 1], [8]], [[100, 100]]],
		}))
		expect(marker.lat).toBe(51)
		expect(marker.lon).toBe(5)
	})

	it.each([
		['no object', null],
		['a non-object', 'case'],
	])('returns null for %s', (_label, input) => {
		expect(caseMarkerFormatter(input)).toBeNull()
	})

	it.each([
		['missing geometry', undefined],
		['non-object geometry', 'POINT(5 52)'],
		['a Point with one coordinate', { type: 'Point', coordinates: [5] }],
		['a Point with non-finite coordinates', { type: 'Point', coordinates: [5, Number.POSITIVE_INFINITY] }],
		['a Point without coordinates array', { type: 'Point', coordinates: '5,52' }],
		['a Polygon without a ring', { type: 'Polygon', coordinates: ['ring'] }],
		['a Polygon without valid pairs', { type: 'Polygon', coordinates: [[['a', 'b'], [1]]] }],
		['a Polygon with an empty ring', { type: 'Polygon', coordinates: [[]] }],
		['a Polygon without coordinates array', { type: 'Polygon', coordinates: null }],
		['an unsupported geometry type', { type: 'LineString', coordinates: [[5, 52], [6, 53]] }],
	])('returns null for %s', (_label, geometry) => {
		expect(caseMarkerFormatter(caseWith(geometry))).toBeNull()
	})
})

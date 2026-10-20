/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `shapeMarkerFeatures` (src/services/mapFormatters.js), the
 * pure presentation step that turns OR point rows into the GeoJSON Feature
 * array `CnMapWidget` renders. It has no DOM / Leaflet dependency.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */

import { describe, expect, it } from 'vitest'

describe('shapeMarkerFeatures', () => {
	it('maps OR point rows to GeoJSON Point features ([lng, lat] order)', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const features = shapeMarkerFeatures([
			{ id: 'c1', label: 'Case one', lat: 52.1, lng: 5.2, status: 'open' },
		])
		expect(features).toHaveLength(1)
		expect(features[0].type).toBe('Feature')
		expect(features[0].geometry).toEqual({
			type: 'Point',
			coordinates: [5.2, 52.1],
		})
		expect(features[0].properties.caseId).toBe('c1')
		expect(features[0].properties.title).toBe('Case one')
		expect(features[0].properties.status).toBe('open')
	})

	it('carries a status colour + icon so markers are colour-coded', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const [blocked] = shapeMarkerFeatures([
			{ id: 'b', lat: 1, lng: 2, status: 'blocked' },
		])
		expect(blocked.properties.color).toBe('var(--color-status-error)')
		expect(blocked.properties.icon).toBe('alert-circle')
	})

	it('skips rows without finite coordinates (no throw on malformed data)', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const features = shapeMarkerFeatures([
			{ id: 'ok', lat: 52, lng: 5 },
			{ id: 'no-geo', lat: null, lng: null },
			{ id: 'nan', lat: 'x', lng: 'y' },
			null,
		])
		expect(features.map((f) => f.properties.caseId)).toEqual(['ok'])
	})

	it('returns an empty array for non-array input', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		expect(shapeMarkerFeatures(undefined)).toEqual([])
		expect(shapeMarkerFeatures(null)).toEqual([])
		expect(shapeMarkerFeatures({})).toEqual([])
	})

	it('stringifies a numeric id and tolerates a missing label', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const [f] = shapeMarkerFeatures([{ id: 42, lat: 1, lng: 2 }])
		expect(f.properties.caseId).toBe('42')
		expect(f.properties.title).toBe('')
	})
})

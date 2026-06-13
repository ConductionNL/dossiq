/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the cases-on-map data shaping helpers in
 * src/services/caseGeoService.js: FeatureCollection → CaseMap geometries,
 * filter query building, count summary, and GeoJSON export.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */

import { describe, it, expect } from 'vitest'
import {
	toMapGeometries,
	buildGeoQuery,
	summariseGeo,
	toExportGeoJson,
} from '../../src/services/caseGeoService.js'

const sampleCollection = {
	type: 'FeatureCollection',
	total: 10,
	filtered: 3,
	features: [
		{
			type: 'Feature',
			id: 'loc-1',
			geometry: { type: 'Point', coordinates: [5.1, 52.1] },
			properties: { caseId: 'c1', status: 'open', zaaktype: 'omgevingsvergunning', cluster: false },
		},
		{
			type: 'Feature',
			id: 'loc-2',
			geometry: { type: 'Point', coordinates: [5.2, 52.2] },
			properties: { caseId: 'c2', status: 'closed', zaaktype: 'subsidie', cluster: false },
		},
		{
			type: 'Feature',
			id: 'cluster-0',
			geometry: { type: 'Point', coordinates: [5.3, 52.3] },
			properties: { cluster: true, clusterCount: 8 },
		},
	],
}

describe('toMapGeometries', () => {
	it('maps every coordinate-bearing feature to a CaseMap geometry', () => {
		const geometries = toMapGeometries(sampleCollection)
		expect(geometries).toHaveLength(3)
		expect(geometries[0].type).toBe('Feature')
		expect(geometries[0].geometry.coordinates).toEqual([5.1, 52.1])
	})

	it('assigns a status colour to single cases and a primary colour to clusters', () => {
		const geometries = toMapGeometries(sampleCollection)
		expect(geometries[0].properties.markerColor).toBe('var(--color-status-info)')
		expect(geometries[2].properties.markerColor).toBe('var(--color-primary-element)')
	})

	it('returns an empty array for a missing or malformed collection', () => {
		expect(toMapGeometries(null)).toEqual([])
		expect(toMapGeometries({})).toEqual([])
		expect(toMapGeometries({ features: 'nope' })).toEqual([])
	})

	it('skips features without coordinates', () => {
		const geometries = toMapGeometries({
			features: [
				{ type: 'Feature', geometry: { type: 'Point' }, properties: {} },
				{ type: 'Feature', geometry: { type: 'Point', coordinates: [4, 51] }, properties: { status: 'open' } },
			],
		})
		expect(geometries).toHaveLength(1)
	})
})

describe('buildGeoQuery', () => {
	it('omits empty filters', () => {
		expect(buildGeoQuery({})).toBe('')
		expect(buildGeoQuery({ zaaktype: '', status: null })).toBe('')
	})

	it('serialises zaaktype, status and zoom', () => {
		const q = buildGeoQuery({ zaaktype: 'subsidie', status: 'open', zoom: 12 })
		const params = new URLSearchParams(q)
		expect(params.get('zaaktype')).toBe('subsidie')
		expect(params.get('status')).toBe('open')
		expect(params.get('zoom')).toBe('12')
	})

	it('serialises a complete viewport bounds as minLon,minLat,maxLon,maxLat', () => {
		const q = buildGeoQuery({ bounds: { north: 52.4, south: 52.3, east: 4.9, west: 4.8 } })
		expect(new URLSearchParams(q).get('bounds')).toBe('4.8,52.3,4.9,52.4')
	})

	it('omits incomplete bounds', () => {
		expect(buildGeoQuery({ bounds: { north: 52.4, south: 52.3 } })).toBe('')
	})
})

describe('summariseGeo', () => {
	it('counts cluster members toward the case total', () => {
		const summary = summariseGeo(sampleCollection)
		expect(summary.clusters).toBe(1)
		expect(summary.cases).toBe(10) // 2 singles + 8 in the cluster
		expect(summary.total).toBe(10)
		expect(summary.filtered).toBe(3)
	})

	it('handles an empty collection', () => {
		const summary = summariseGeo({ type: 'FeatureCollection', features: [] })
		expect(summary).toEqual({ total: 0, filtered: 0, clusters: 0, cases: 0 })
	})
})

describe('toExportGeoJson', () => {
	it('excludes cluster aggregates from the export', () => {
		const exported = JSON.parse(toExportGeoJson(sampleCollection))
		expect(exported.type).toBe('FeatureCollection')
		expect(exported.features).toHaveLength(2)
		expect(exported.features.every((f) => f.properties.cluster !== true)).toBe(true)
	})

	it('produces valid JSON for an empty collection', () => {
		const exported = JSON.parse(toExportGeoJson(null))
		expect(exported.features).toEqual([])
	})
})

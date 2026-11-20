/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A configured map layer as the map widget draws it, ArcGIS included.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */

import { describe, expect, it } from 'vitest'
import { arcgisTileUrl, mapLayerDefinition } from '../../src/utils/mapLayers.js'

describe('mapLayerDefinition', () => {
	it('draws an ArcGIS map service from its cached tiles, row before column', () => {
		expect(mapLayerDefinition({
			type: 'ArcGIS',
			url: 'https://gis.example.nl/arcgis/rest/services/Bomen/MapServer/',
			opacity: 0.5,
			attribution: 'Gemeente',
		})).toEqual({
			type: 'tile',
			url: 'https://gis.example.nl/arcgis/rest/services/Bomen/MapServer/tile/{z}/{y}/{x}',
			options: { opacity: 0.5, attribution: 'Gemeente' },
		})
	})

	it('needs no layer name for an ArcGIS layer', () => {
		expect(mapLayerDefinition({ type: 'arcgis', url: 'https://gis.example.nl/MapServer' })?.type).toBe('tile')
	})

	it('draws a WMS layer as transparent image tiles of its layer name', () => {
		expect(mapLayerDefinition({ type: 'WMS', url: 'https://service.pdok.nl/wms', layerName: 'pand', opacity: 0.7 })).toEqual({
			type: 'wms',
			url: 'https://service.pdok.nl/wms',
			options: { opacity: 0.7, layers: 'pand', format: 'image/png', transparent: true },
		})
	})

	it('reads a layer without a type as WMS, and a WFS layer as features', () => {
		expect(mapLayerDefinition({ url: 'https://x', layerName: 'a' })?.type).toBe('wms')
		expect(mapLayerDefinition({ type: 'WFS', url: 'https://x', layerName: 'b' })?.type).toBe('wfs')
	})

	it('leaves out a layer it cannot draw', () => {
		expect(mapLayerDefinition({ type: 'WMS', url: 'https://x' })).toBeNull()
		expect(mapLayerDefinition({ type: 'WMS', url: '  ', layerName: 'a' })).toBeNull()
		expect(mapLayerDefinition({ type: 'KML', url: 'https://x', layerName: 'a' })).toBeNull()
		expect(mapLayerDefinition(null)).toBeNull()
	})
})

describe('arcgisTileUrl', () => {
	it('drops trailing slashes before the tile path', () => {
		expect(arcgisTileUrl(' https://h/MapServer// ')).toBe('https://h/MapServer/tile/{z}/{y}/{x}')
	})
})

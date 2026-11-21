/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A configured map layer (`wmsLayer`) in the shape the library map widget
 * draws: a WMS service as image tiles, a WFS service as features, and an
 * ArcGIS map service from its cached tiles.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */

/**
 * The tile template of an ArcGIS MapServer: its cached tiles are addressed row before column.
 *
 * @param {string} url The MapServer URL, such as `https://host/arcgis/rest/services/Trees/MapServer`.
 * @return {string} The tile URL template.
 */
export function arcgisTileUrl(url) {
	return `${String(url).trim().replace(/\/+$/, '')}/tile/{z}/{y}/{x}`
}

/**
 * The map widget's definition of one configured layer, or null when it cannot be drawn.
 *
 * @param {object} layer The layer record: `{ type, url, layerName, opacity, attribution }`.
 * @return {object|null} `{ type, url, options }`, or null for a layer without a URL,
 *     a WMS or WFS layer without a layer name, or a type the map does not know.
 */
export function mapLayerDefinition(layer) {
	const url = typeof layer?.url === 'string' ? layer.url.trim() : ''
	if (url === '') {
		return null
	}

	const kind = String(layer.type ?? 'WMS').toLowerCase()
	const options = {}
	if (Number.isFinite(layer.opacity)) {
		options.opacity = layer.opacity
	}
	if (typeof layer.attribution === 'string' && layer.attribution !== '') {
		options.attribution = layer.attribution
	}

	if (kind === 'arcgis') {
		return { type: 'tile', url: arcgisTileUrl(url), options }
	}

	const layerName = typeof layer.layerName === 'string' ? layer.layerName.trim() : ''
	if (layerName === '' || (kind !== 'wms' && kind !== 'wfs')) {
		return null
	}

	return {
		type: kind,
		url,
		options: { ...options, layers: layerName, format: 'image/png', transparent: true },
	}
}

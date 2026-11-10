/**
 * Map marker formatters for manifest-driven `type: 'map'` pages.
 *
 * Registered into the lib's `mapFormatters` registry from `main.js`,
 * mirroring the `customComponents` resolution pattern. `CnMapPage`
 * looks up `config.marker.formatter` by name and invokes the resolved
 * function once per object in the result set.
 *
 * Each formatter receives a single domain object and returns either a
 * marker descriptor `{ lat, lon, color, icon, popup, onClick }` or
 * `null` to skip objects without usable geometry.
 *
 * @file src/services/mapFormatters.js
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Marker colour token per case status; unknown statuses fall back to
 * the max-contrast text colour.
 */
const STATUS_COLORS = new Map([
	['blocked', 'var(--color-status-error)'],
	['in_progress', 'var(--color-status-warning)'],
	['open', 'var(--color-status-info)'],
	['closed', 'var(--color-text-maxcontrast)'],
])

/**
 * Marker icon glyph per case status; unknown statuses fall back to
 * `map-marker`.
 */
const STATUS_ICONS = new Map([
	['blocked', 'alert-circle'],
	['in_progress', 'progress-clock'],
	['open', 'map-marker'],
	['closed', 'check-circle'],
])

/**
 * Status → NL Design System colour token. No hardcoded hex anywhere;
 * the lib resolves the CSS variable at render time so the same palette
 * adapts to light/dark/high-contrast themes.
 *
 * Priority for cluster colours (most severe first):
 *   blocked > in_progress > open > closed
 *
 * @param {string} status The case status.
 * @return {string} CSS variable reference.
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
 */
export function statusColor(status) {
	return STATUS_COLORS.get(status) ?? 'var(--color-text-maxcontrast)'
}

/**
 * Status → Material Design icon glyph name.
 *
 * @param {string} status The case status.
 * @return {string} Icon glyph name.
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
 */
export function statusIcon(status) {
	return STATUS_ICONS.get(status) ?? 'map-marker'
}

/**
 * Read `{ lat, lon }` from a GeoJSON Point's `[lon, lat]` coordinates.
 *
 * @param {Array} coordinates Point coordinates.
 * @return {{lat: number, lon: number}|null} Coordinates or null.
 */
function pointCoords(coordinates) {
	if (coordinates.length < 2) {
		return null
	}
	const [lon, lat] = coordinates
	if (Number.isFinite(lat) && Number.isFinite(lon)) {
		return { lat, lon }
	}
	return null
}

/**
 * Whether a ring entry is a `[lon, lat]` pair of finite numbers.
 *
 * @param {unknown} pair Ring entry.
 * @return {boolean} True for a usable pair.
 */
function isFinitePair(pair) {
	return Array.isArray(pair)
		&& pair.length >= 2
		&& Number.isFinite(pair[0])
		&& Number.isFinite(pair[1])
}

/**
 * Arithmetic-mean centroid of a polygon's outer ring, skipping unusable
 * entries.
 *
 * @param {unknown} ring Outer ring of a GeoJSON Polygon.
 * @return {{lat: number, lon: number}|null} Centroid or null.
 */
function ringCentroid(ring) {
	if (!Array.isArray(ring)) {
		return null
	}
	const pairs = ring.filter(isFinitePair)
	if (pairs.length === 0) {
		return null
	}
	return meanPosition(pairs)
}

/**
 * Arithmetic mean of a non-empty list of `[lon, lat]` pairs.
 *
 * @param {Array<Array<number>>} pairs Finite `[lon, lat]` pairs.
 * @return {{lat: number, lon: number}} Mean position.
 */
function meanPosition(pairs) {
	let sumLat = 0
	let sumLon = 0
	for (const pair of pairs) {
		sumLon += pair[0]
		sumLat += pair[1]
	}
	return { lat: sumLat / pairs.length, lon: sumLon / pairs.length }
}

/**
 * Centroid of a GeoJSON Polygon's outer ring.
 *
 * @param {Array} coordinates Polygon coordinates (rings).
 * @return {{lat: number, lon: number}|null} Centroid or null.
 */
function polygonCoords(coordinates) {
	return ringCentroid(coordinates[0])
}

/**
 * Coordinate reader per supported GeoJSON geometry type.
 */
const COORD_READERS = new Map([
	['Point', pointCoords],
	['Polygon', polygonCoords],
])

/**
 * Extract `{ lat, lon }` from a GeoJSON geometry. Supports:
 *
 *   - `Point`:   coordinates are `[lon, lat]` per RFC 7946.
 *   - `Polygon`: arithmetic-mean centroid of the outer ring (sufficient
 *                for pin placement — matches `case-location` REQ-LOC-03b).
 *
 * Any other geometry type, or missing/invalid input, returns `null`
 * so the caller can skip that object.
 *
 * @param {object} geometry GeoJSON geometry object.
 * @return {{lat: number, lon: number}|null} Coordinates or null.
 */
function extractCoords(geometry) {
	if (!geometry || typeof geometry !== 'object' || !Array.isArray(geometry.coordinates)) {
		return null
	}
	const read = COORD_READERS.get(geometry.type)
	return read ? read(geometry.coordinates) : null
}

/**
 * Project a case object onto a Leaflet marker descriptor.
 *
 * @param {object} caseObj Case object from OpenRegister.
 * @return {object|null}   Marker descriptor or `null` if no geometry.
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
 */
export function caseMarkerFormatter(caseObj) {
	if (!caseObj || typeof caseObj !== 'object') {
		return null
	}
	const coords = extractCoords(caseObj.geometry)
	if (!coords) {
		return null
	}
	return caseMarker(caseObj, coords)
}

/**
 * Marker descriptor for a case at the given coordinates.
 *
 * @param {object} caseObj Case object from OpenRegister.
 * @param {{lat: number, lon: number}} coords Marker position.
 * @return {object} Marker descriptor.
 */
function caseMarker(caseObj, coords) {
	return {
		lat: coords.lat,
		lon: coords.lon,
		color: statusColor(caseObj.status),
		icon: statusIcon(caseObj.status),
		popup: casePopup(caseObj),
		onClick: caseDetailLink(caseObj),
	}
}

/**
 * Popup content for a case marker.
 *
 * @param {object} caseObj Case object from OpenRegister.
 * @return {{title: string, subtitle: string, status: string}} Popup content.
 */
function casePopup(caseObj) {
	return {
		title: caseObj.title,
		subtitle: caseObj.identifier,
		status: caseObj.status,
	}
}

/**
 * Click-through route to the case detail page.
 *
 * @param {object} caseObj Case object from OpenRegister.
 * @return {{route: string, params: object}} Route descriptor.
 */
function caseDetailLink(caseObj) {
	return {
		route: 'CaseDetail',
		params: { id: caseObj.id },
	}
}

/**
 * Shape OpenRegister maps-overview point rows into the GeoJSON Feature array
 * that `@conduction/nextcloud-vue`'s `CnMapWidget` renders inline
 * (`markers.features[]`). Each OR point is `{ id, label, lat, lng, register,
 * schema, geometry, ...rest }`; OR has already done the geometry extraction
 * and RBAC scoping, so this is pure presentation: it carries the case id +
 * label through to `properties` for the popup / click-through and colours the
 * marker by status when the row exposes one.
 *
 * Defensive: rows without a finite `lat`/`lng` are skipped (OR never emits
 * them, but a malformed payload yields a blank map rather than a throw).
 *
 * @param {Array<object>} points OR maps-overview point rows.
 * @return {Array<object>} GeoJSON Point Feature array for CnMapWidget.
 * @spec openspec/specs/case-map-overview/spec.md
 */
export function shapeMarkerFeatures(points) {
	if (!Array.isArray(points)) {
		return []
	}
	return points
		.filter((p) => p && Number.isFinite(p.lat) && Number.isFinite(p.lng))
		.map(pointFeature)
}

/**
 * One OR maps-overview point row as a GeoJSON Point Feature.
 *
 * @param {object} p OR maps-overview point row with finite `lat`/`lng`.
 * @return {object} GeoJSON Point Feature for CnMapWidget.
 */
function pointFeature(p) {
	return {
		type: 'Feature',
		geometry: { type: 'Point', coordinates: [p.lng, p.lat] },
		properties: {
			caseId: p.id != null ? String(p.id) : null,
			title: p.label || '',
			status: p.status || null,
			color: statusColor(p.status),
			icon: statusIcon(p.status),
		},
	}
}

export default {
	caseMarkerFormatter,
	shapeMarkerFeatures,
}

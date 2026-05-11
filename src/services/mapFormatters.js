// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Map-formatter registry — shared by every Procest surface that renders
// the `MapComponent` wrapper around `CnMapWidget`. A formatter takes an
// input "location" object (case, address, generic geometry-bearing
// record) and returns a marker descriptor consumable by the lib widget:
//
//   {
//     lat:    Number,
//     lon:    Number,
//     color:  String,  // CSS variable token or inherited token
//     icon:   String,  // material-design-icons name
//     popup:  { title: String, body?: String },
//     onClick: Function (optional),
//   }
//
// Polygons are reduced to their arithmetic-mean centroid here so the
// MapComponent wrapper never has to know about geometry types.
//
// Resolution: `MapComponent` reads the registry via the `markerFormatter`
// prop (a name, not a function reference) so manifest-driven pages can
// configure formatters by string. The default formatter
// `caseMarkerFormatter` is appropriate for the bulk of Procest surfaces
// that show case-shaped objects.

/**
 * Compute the arithmetic-mean centroid of a polygon ring.
 *
 * @param {Array<Array<number>>} ring GeoJSON ring — array of [lon, lat] pairs.
 * @return {{lat: number, lon: number}|null} Centroid or null when ring is empty.
 */
function polygonCentroid(ring) {
	if (!Array.isArray(ring) || ring.length === 0) {
		return null
	}
	let lonSum = 0
	let latSum = 0
	for (const [lon, lat] of ring) {
		lonSum += lon
		latSum += lat
	}
	return {
		lon: lonSum / ring.length,
		lat: latSum / ring.length,
	}
}

/**
 * Extract a {lat, lon} centroid from a GeoJSON geometry. Points return
 * their own coordinates; polygons reduce to the centroid of their
 * outer ring; everything else returns null (caller should skip).
 *
 * @param {object|null} geometry GeoJSON geometry object.
 * @return {{lat: number, lon: number}|null}
 */
export function geometryCentroid(geometry) {
	if (!geometry || !geometry.type) {
		return null
	}
	if (geometry.type === 'Point' && Array.isArray(geometry.coordinates)) {
		const [lon, lat] = geometry.coordinates
		return { lat, lon }
	}
	if (geometry.type === 'Polygon' && Array.isArray(geometry.coordinates) && geometry.coordinates[0]) {
		return polygonCentroid(geometry.coordinates[0])
	}
	return null
}

/**
 * Default Procest formatter — turns a case-shaped object into a marker.
 *
 * Status-driven palette uses NL Design System tokens (the colour is
 * resolved by the lib widget against CSS variables; we just name the
 * token here so changes propagate from the theme, not from a hex).
 *
 * @param {object} location Case-shaped object with `geometry` and `status` fields.
 * @return {object|null} Marker descriptor or null when geometry missing.
 */
export function caseMarkerFormatter(location) {
	if (!location) {
		return null
	}
	const centroid = geometryCentroid(location.geometry)
	if (!centroid) {
		return null
	}
	const status = (location.status || '').toLowerCase()
	let color = 'var(--color-primary-element)'
	if (status === 'closed' || status === 'archived' || status === 'gesloten') {
		color = 'var(--color-success)'
	} else if (status === 'overdue' || status === 'verlopen') {
		color = 'var(--color-error)'
	} else if (status === 'pending' || status === 'in_progress' || status === 'in_behandeling') {
		color = 'var(--color-warning)'
	}
	return {
		lat: centroid.lat,
		lon: centroid.lon,
		color,
		icon: 'map-marker',
		popup: {
			title: location.title || location.name || location.id || '',
			body: location.summary || location.description || '',
		},
	}
}

/**
 * Generic location formatter — minimal, no status-driven palette.
 * Useful for address-style records that don't carry case status.
 *
 * @param {object} location Object with `geometry` and free-form `label`.
 * @return {object|null}
 */
export function locationMarkerFormatter(location) {
	if (!location) {
		return null
	}
	const centroid = geometryCentroid(location.geometry)
	if (!centroid) {
		return null
	}
	return {
		lat: centroid.lat,
		lon: centroid.lon,
		color: 'var(--color-primary-element)',
		icon: 'map-marker',
		popup: {
			title: location.label || location.title || '',
			body: location.body || '',
		},
	}
}

/**
 * Registry of named formatters. Keys are referenced by string from the
 * `MapComponent.markerFormatter` prop and from manifest entries.
 */
const mapFormatters = {
	caseMarkerFormatter,
	locationMarkerFormatter,
}

/**
 * Resolve a formatter by name, falling back to `caseMarkerFormatter`
 * when the name is unknown. Returning a default rather than throwing
 * keeps the map rendering — bad-name bugs surface as misformatted pins
 * rather than blank screens.
 *
 * @param {string} name Registry key.
 * @return {Function} Formatter function.
 */
export function resolveFormatter(name) {
	return mapFormatters[name] || mapFormatters.caseMarkerFormatter
}

export default mapFormatters

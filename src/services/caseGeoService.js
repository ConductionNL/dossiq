/**
 * Case-geo data shaping helpers for the cases-on-map view.
 *
 * Keeps the map rendering thin: all the data transformation between the
 * `/api/cases/geo` GeoJSON FeatureCollection and the `CaseMap` component's
 * `geometries` prop lives here as pure functions so it can be unit-tested
 * without a DOM or a Leaflet instance (gis-integration spec).
 *
 * @file src/services/caseGeoService.js
 *
 * @spec openspec/specs/gis-integration/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { statusColor } from './mapFormatters.js'

/**
 * Map endpoint path for the cases-on-map FeatureCollection.
 */
export const CASE_GEO_ENDPOINT = '/index.php/apps/procest/api/cases/geo'

/**
 * Convert a `/api/cases/geo` FeatureCollection into the `geometries` array the
 * `CaseMap` component renders. Cluster features keep their `clusterCount`;
 * single-case features carry status colour + case metadata for the popup.
 *
 * Defensive: a missing / malformed collection yields an empty array so the
 * map renders blank rather than throwing.
 *
 * @param {object} collection GeoJSON FeatureCollection from the API.
 * @return {Array<object>} Geometry descriptors for CaseMap.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
export function toMapGeometries(collection) {
	if (!collection || !Array.isArray(collection.features)) {
		return []
	}

	return collection.features
		.filter((feature) => feature && feature.geometry && Array.isArray(feature.geometry.coordinates))
		.map((feature) => {
			const props = feature.properties || {}
			const isCluster = props.cluster === true
			return {
				type: 'Feature',
				geometry: feature.geometry,
				properties: {
					...props,
					markerColor: isCluster
						? 'var(--color-primary-element)'
						: statusColor(props.status),
				},
			}
		})
}

/**
 * Build the query string for the cases-on-map endpoint from a filter object.
 * Empty / null filter values are omitted so the backend treats them as "all".
 *
 * @param {object} filters            The active filters.
 * @param {string} [filters.zaaktype] Case-type filter.
 * @param {string} [filters.status]   Status filter.
 * @param {number} [filters.zoom]     Current map zoom (drives clustering).
 * @param {object} [filters.bounds]   `{ north, south, east, west }` viewport.
 * @return {string} URL-encoded query string (without leading `?`).
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
export function buildGeoQuery(filters = {}) {
	const params = new URLSearchParams()

	if (filters.zaaktype) {
		params.set('zaaktype', String(filters.zaaktype))
	}
	if (filters.status) {
		params.set('status', String(filters.status))
	}
	if (Number.isFinite(filters.zoom)) {
		params.set('zoom', String(filters.zoom))
	}
	if (filters.bounds
		&& Number.isFinite(filters.bounds.west)
		&& Number.isFinite(filters.bounds.south)
		&& Number.isFinite(filters.bounds.east)
		&& Number.isFinite(filters.bounds.north)) {
		params.set('bounds', [
			filters.bounds.west,
			filters.bounds.south,
			filters.bounds.east,
			filters.bounds.north,
		].join(','))
	}

	return params.toString()
}

/**
 * Reduce a FeatureCollection to a `{ total, filtered, clusters, cases }`
 * summary for the view's count badge. Cluster features count their members.
 *
 * @param {object} collection GeoJSON FeatureCollection from the API.
 * @return {{total: number, filtered: number, clusters: number, cases: number}} Summary.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
export function summariseGeo(collection) {
	const features = (collection && Array.isArray(collection.features)) ? collection.features : []

	let clusters = 0
	let cases = 0
	for (const feature of features) {
		const props = (feature && feature.properties) || {}
		if (props.cluster === true) {
			clusters++
			cases += Number.isFinite(props.clusterCount) ? props.clusterCount : 0
		} else {
			cases++
		}
	}

	return {
		total: (collection && Number.isFinite(collection.total)) ? collection.total : cases,
		filtered: (collection && Number.isFinite(collection.filtered)) ? collection.filtered : cases,
		clusters,
		cases,
	}
}

/**
 * Serialise the currently-visible single-case features as a downloadable
 * GeoJSON FeatureCollection (cluster aggregates are excluded — only resolvable
 * individual cases are exported). Returns a pretty-printed JSON string.
 *
 * @param {object} collection GeoJSON FeatureCollection from the API.
 * @return {string} Pretty-printed GeoJSON.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
export function toExportGeoJson(collection) {
	const features = (collection && Array.isArray(collection.features)) ? collection.features : []
	const individual = features.filter((feature) => {
		const props = (feature && feature.properties) || {}
		return props.cluster !== true
	})

	return JSON.stringify({
		type: 'FeatureCollection',
		features: individual,
	}, null, 2)
}

export default {
	CASE_GEO_ENDPOINT,
	toMapGeometries,
	buildGeoQuery,
	summariseGeo,
	toExportGeoJson,
}

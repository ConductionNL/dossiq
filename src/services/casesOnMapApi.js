/**
 * OpenRegister maps-overview client for Procest's cases-on-map page.
 *
 * Consumes OpenRegister's page-level **maps-overview** integration surface
 * (ADR-022) — the leaf-foundation render contract landed on openregister
 * development (PR #154):
 *
 *   POST /apps/openregister/api/integrations/maps/overviews
 *        — declare / refresh a `map` page-widget for a register/schema. OR
 *          persists it on the IntegrationRegistry with a declarative
 *          base-layer config (PDOK WMTS by default, overridable).
 *   GET  /apps/openregister/api/integrations/maps/overviews/{register}/{schema}/points
 *        — query the RBAC-scoped marker point set. OR runs its canonical read
 *          path with `_rbac: true` for non-admins, extracts a representative
 *          `[lat,lng]` per object via its own GeoFeatureCollectionBuilder, and
 *          returns `{ points: [{ id, label, lat, lng, register, schema,
 *          geometry }], count }`. An anonymous / low-privilege caller only ever
 *          sees public-readable objects (fail-closed) — procest does NO bespoke
 *          geo query and NO bespoke RBAC of its own (ADR-005, no IDOR).
 *
 * Procest OWNS only the geo *data* contract (the `case` schema's geometry
 * field stays in procest's register); OR owns persistence, the geometry
 * extraction, RBAC scoping, and the base-layer config. The markers are then
 * drawn by `@conduction/nextcloud-vue`'s declarative `CnMapWidget` (which owns
 * the Leaflet engine) — procest embeds no Leaflet / WMS / WFS stack of its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/case-map-overview/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Stable key for procest's cases-on-map overview (one per app). */
export const CASES_ON_MAP_KEY = 'procest-cases-on-map'

const OVERVIEWS_URL = generateUrl(
	'/apps/openregister/api/integrations/maps/overviews',
)

/**
 * Declare (or refresh) procest's cases-on-map overview with OpenRegister's
 * maps-overview surface. Idempotent on `overviewKey` (OR upserts the widget).
 * Fire-and-forget: a registration failure never throws into the view — the
 * page still fetches points and degrades to its empty state.
 *
 * @param {object} [options] Overview options.
 * @param {string} [options.register] Register slug holding the cases (default `procest`).
 * @param {string} [options.schema] Schema slug for the case objects (default `case`).
 * @param {string} [options.title] Human-readable overview title.
 * @return {Promise<object|null>} The stored widget render contract, or null on failure.
 * @spec openspec/specs/case-map-overview/spec.md
 */
export async function registerCasesOnMapOverview({
	register = 'procest',
	schema = 'case',
	title = 'Cases on map',
} = {}) {
	try {
		const response = await axios.post(OVERVIEWS_URL, {
			overviewKey: CASES_ON_MAP_KEY,
			register,
			schema,
			title,
		})
		return response.data
	} catch (err) {
		console.warn(
			'[procest] maps-overview register failed for',
			CASES_ON_MAP_KEY,
			err,
		)
		return null
	}
}

/**
 * Fetch the RBAC-scoped marker point set for a register/schema from
 * OpenRegister's maps-overview points endpoint. Returns the raw point rows
 * (`{ id, label, lat, lng, register, schema, geometry }`); marker styling is
 * applied by the view via {@see shapeMarkerFeatures}. Returns an empty array
 * on any failure so the map renders blank rather than throwing.
 *
 * @param {object} [options] Query options.
 * @param {string} [options.register] Register slug (default `procest`).
 * @param {string} [options.schema] Schema slug (default `case`).
 * @param {object} [options.filters] Extra object filters (property=value) forwarded to OR.
 * @return {Promise<Array<object>>} The RBAC-scoped point rows (possibly empty).
 * @spec openspec/specs/case-map-overview/spec.md
 */
export async function fetchCasePoints({
	register = 'procest',
	schema = 'case',
	filters = {},
} = {}) {
	const url = `${OVERVIEWS_URL}/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/points`
	try {
		const response = await axios.get(url, { params: filters })
		const data = response.data || {}
		return Array.isArray(data.points) ? data.points : []
	} catch (err) {
		console.warn(
			'[procest] maps-overview points fetch failed for',
			register,
			schema,
			err,
		)
		return []
	}
}

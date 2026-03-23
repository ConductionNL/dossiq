/**
 * PDOK Locatieserver API client.
 *
 * Provides suggest (autocomplete), lookup, free-text search, and reverse geocoding
 * using the PDOK Locatieserver v3.1 API.
 *
 * @see https://api.pdok.nl/bzk/locatieserver/search/v3_1/
 */

const BASE_URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1'

let debounceTimer = null

/**
 * Suggest addresses as the user types (autocomplete).
 * Debounced at 200ms to avoid excessive API calls.
 *
 * @param {string} query The search query (min 3 characters)
 * @return {Promise<Array>} Array of suggestion objects
 */
export async function suggest(query) {
	if (!query || query.length < 3) {
		return []
	}

	return new Promise((resolve, reject) => {
		clearTimeout(debounceTimer)
		debounceTimer = setTimeout(async () => {
			try {
				const params = new URLSearchParams({
					q: query,
					rows: 10,
				})
				const response = await fetch(`${BASE_URL}/suggest?${params}`)
				if (!response.ok) {
					throw new Error(`PDOK suggest failed: ${response.status}`)
				}
				const data = await response.json()
				resolve(data.response?.docs || [])
			} catch (error) {
				reject(error)
			}
		}, 200)
	})
}

/**
 * Look up a specific result by its ID (returned from suggest).
 *
 * @param {string} id The PDOK object ID
 * @return {Promise<object|null>} The full result object with geometry
 */
export async function lookup(id) {
	if (!id) {
		return null
	}

	const params = new URLSearchParams({ id })
	const response = await fetch(`${BASE_URL}/lookup?${params}`)
	if (!response.ok) {
		throw new Error(`PDOK lookup failed: ${response.status}`)
	}
	const data = await response.json()
	return data.response?.docs?.[0] || null
}

/**
 * Free-text search for addresses/locations.
 *
 * @param {string} query The search query
 * @param {number} rows  Max results (default 10)
 * @return {Promise<Array>} Array of result objects
 */
export async function free(query, rows = 10) {
	if (!query) {
		return []
	}

	const params = new URLSearchParams({ q: query, rows: String(rows) })
	const response = await fetch(`${BASE_URL}/free?${params}`)
	if (!response.ok) {
		throw new Error(`PDOK free search failed: ${response.status}`)
	}
	const data = await response.json()
	return data.response?.docs || []
}

/**
 * Reverse geocode coordinates to find the nearest address.
 *
 * @param {number} lat Latitude (WGS84)
 * @param {number} lng Longitude (WGS84)
 * @return {Promise<object|null>} The nearest address object
 */
export async function reverse(lat, lng) {
	const params = new URLSearchParams({
		type: 'adres',
		lat: String(lat),
		lon: String(lng),
		rows: '1',
	})
	const response = await fetch(`${BASE_URL}/reverse?${params}`)
	if (!response.ok) {
		throw new Error(`PDOK reverse failed: ${response.status}`)
	}
	const data = await response.json()
	return data.response?.docs?.[0] || null
}

/**
 * Extract WGS84 coordinates from a PDOK result's centroide_ll field.
 * The field is in WKT format: "POINT(lng lat)".
 *
 * @param {object} result A PDOK result object
 * @return {{ lat: number, lng: number }|null} Coordinates or null
 */
export function extractCoordinates(result) {
	if (!result?.centroide_ll) {
		return null
	}
	const match = result.centroide_ll.match(/POINT\(([^ ]+) ([^ ]+)\)/)
	if (!match) {
		return null
	}
	return {
		lng: parseFloat(match[1]),
		lat: parseFloat(match[2]),
	}
}

/**
 * Format a PDOK result as a human-readable address string.
 *
 * @param {object} result A PDOK result object
 * @return {string} Formatted address
 */
export function formatAddress(result) {
	if (!result) {
		return ''
	}
	return result.weergavenaam || result.display || ''
}

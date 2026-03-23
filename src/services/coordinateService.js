/**
 * Coordinate transformation service.
 *
 * Converts between Rijksdriehoekscoordinaten (RD / EPSG:28992) and
 * WGS84 (EPSG:4326) using Proj4js.
 */

import proj4 from 'proj4'

// Define the RD New projection (Amersfoort / RD New, EPSG:28992)
proj4.defs(
	'EPSG:28992',
	'+proj=sterea +lat_0=52.1561605555556 +lon_0=5.38763888888889 '
	+ '+k=0.9999079 +x_0=155000 +y_0=463000 +ellps=bessel '
	+ '+towgs84=565.4171,50.3319,465.5524,-0.398957,0.343988,-1.87740,4.0725 +units=m +no_defs',
)

/**
 * Check if coordinates appear to be in RD (EPSG:28992) format.
 * RD coordinates have x in range [0, 300000] and y in range [300000, 625000].
 *
 * @param {number} x The x coordinate (or longitude)
 * @param {number} y The y coordinate (or latitude)
 * @return {boolean} True if coordinates appear to be RD
 */
export function isRDCoordinate(x, y) {
	return x >= 0 && x <= 300000 && y >= 300000 && y <= 625000
}

/**
 * Convert RD (EPSG:28992) coordinates to WGS84 (EPSG:4326).
 *
 * @param {number} x RD x coordinate
 * @param {number} y RD y coordinate
 * @return {{ lat: number, lng: number }} WGS84 coordinates
 */
export function rdToWgs84(x, y) {
	const [lng, lat] = proj4('EPSG:28992', 'EPSG:4326', [x, y])
	return { lat, lng }
}

/**
 * Convert WGS84 (EPSG:4326) coordinates to RD (EPSG:28992).
 *
 * @param {number} lat Latitude
 * @param {number} lng Longitude
 * @return {{ x: number, y: number }} RD coordinates
 */
export function wgs84ToRd(lat, lng) {
	const [x, y] = proj4('EPSG:4326', 'EPSG:28992', [lng, lat])
	return { x, y }
}

/**
 * Ensure GeoJSON coordinates are in WGS84 format.
 * If the coordinates appear to be in RD format, they are converted.
 *
 * @param {object} geojson A GeoJSON geometry object
 * @param {string} crs     Optional CRS identifier (e.g., "EPSG:28992")
 * @return {object} GeoJSON geometry in WGS84
 */
export function ensureWgs84(geojson, crs = null) {
	if (!geojson || !geojson.type) {
		return geojson
	}

	const needsConversion = crs === 'EPSG:28992'
		|| (geojson.type === 'Point' && isRDCoordinate(geojson.coordinates[0], geojson.coordinates[1]))

	if (!needsConversion) {
		return geojson
	}

	return {
		...geojson,
		coordinates: convertCoordinates(geojson.coordinates, geojson.type),
	}
}

/**
 * Recursively convert coordinate arrays from RD to WGS84.
 *
 * @param {Array} coords    Coordinate array (nested for polygons)
 * @param {string} geoType  GeoJSON geometry type
 * @return {Array} Converted coordinates
 */
function convertCoordinates(coords, geoType) {
	if (geoType === 'Point') {
		const { lng, lat } = rdToWgs84(coords[0], coords[1])
		return [lng, lat]
	}
	if (geoType === 'LineString' || geoType === 'MultiPoint') {
		return coords.map(c => {
			const { lng, lat } = rdToWgs84(c[0], c[1])
			return [lng, lat]
		})
	}
	if (geoType === 'Polygon' || geoType === 'MultiLineString') {
		return coords.map(ring =>
			ring.map(c => {
				const { lng, lat } = rdToWgs84(c[0], c[1])
				return [lng, lat]
			}),
		)
	}
	if (geoType === 'MultiPolygon') {
		return coords.map(polygon =>
			polygon.map(ring =>
				ring.map(c => {
					const { lng, lat } = rdToWgs84(c[0], c[1])
					return [lng, lat]
				}),
			),
		)
	}
	return coords
}

/**
 * GIS store module for Procest.
 *
 * Manages map layer configurations, spatial filter state, and reverse geocode cache.
 * Uses the objectStore for CRUD operations on MapLayer objects in OpenRegister.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'
import { reverse, formatAddress } from '../../services/pdokService.js'

export const useGisStore = defineStore('gis', {
	state: () => ({
		/** Configured map layers (from OpenRegister). */
		layers: [],
		/** IDs of currently active overlay layers. */
		activeOverlays: [],
		/** Spatial filter geometry (GeoJSON Polygon or null). */
		selectedArea: null,
		/** Cases matching the spatial filter. */
		selectedCases: [],
		/** Spatial filter mode: 'rectangle', 'polygon', 'wijk', or null. */
		filterMode: null,
		/** Reverse geocode cache: { "lat,lng": "address string" }. */
		reverseGeocodeCache: {},
		/** Loading state. */
		loading: false,
	}),

	getters: {
		overlayLayers(state) {
			return state.layers.filter(l => !l.isBaseLayer)
		},
		baseLayers(state) {
			return state.layers.filter(l => l.isBaseLayer)
		},
	},

	actions: {
		/**
		 * Fetch all MapLayer objects from OpenRegister.
		 */
		async fetchLayers() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection('mapLayer', {})
				this.layers = response || []
			} catch {
				this.layers = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new MapLayer object.
		 *
		 * @param {object} layerData The layer configuration
		 * @return {object} The created layer
		 */
		async createLayer(layerData) {
			const objectStore = useObjectStore()
			const created = await objectStore.saveObject('mapLayer', layerData)
			await this.fetchLayers()
			return created
		},

		/**
		 * Update an existing MapLayer object.
		 *
		 * @param {string} id        The layer ID
		 * @param {object} layerData The updated configuration
		 * @return {object} The updated layer
		 */
		async updateLayer(id, layerData) {
			const objectStore = useObjectStore()
			const updated = await objectStore.saveObject('mapLayer', { id, ...layerData })
			await this.fetchLayers()
			return updated
		},

		/**
		 * Delete a MapLayer object.
		 *
		 * @param {string} id The layer ID
		 */
		async deleteLayer(id) {
			const objectStore = useObjectStore()
			await objectStore.deleteObject('mapLayer', id)
			await this.fetchLayers()
		},

		/**
		 * Set the spatial filter area.
		 *
		 * @param {object|null} geometry GeoJSON geometry or null to clear
		 * @param {string}      mode     Filter mode identifier
		 */
		setSpatialFilter(geometry, mode = null) {
			this.selectedArea = geometry
			this.filterMode = mode
		},

		/**
		 * Clear the spatial filter.
		 */
		clearSpatialFilter() {
			this.selectedArea = null
			this.selectedCases = []
			this.filterMode = null
		},

		/**
		 * Get a cached reverse geocode result, or fetch from PDOK.
		 *
		 * @param {number} lat Latitude
		 * @param {number} lng Longitude
		 * @return {Promise<string>} The formatted address
		 */
		async reverseGeocode(lat, lng) {
			const key = `${lat.toFixed(5)},${lng.toFixed(5)}`

			if (this.reverseGeocodeCache[key]) {
				return this.reverseGeocodeCache[key]
			}

			try {
				const result = await reverse(lat, lng)
				const address = formatAddress(result)
				this.reverseGeocodeCache[key] = address
				return address
			} catch {
				return ''
			}
		},
	},
})

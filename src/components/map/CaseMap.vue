<template>
	<div
		ref="mapContainer"
		class="case-map"
		role="application"
		:aria-label="t('procest', 'Map with case locations')"
		tabindex="0"
		@keydown="onKeydown">
		<div ref="mapElement" class="case-map__leaflet" />
		<MapLegend v-if="showLegend" />
	</div>
</template>

<script>
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import { ensureWgs84 } from '../../services/coordinateService.js'
import MapLegend from './MapLegend.vue'

// Fix Leaflet default icon paths broken by webpack
import iconUrl from 'leaflet/dist/images/marker-icon.png'
import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png'
import shadowUrl from 'leaflet/dist/images/marker-shadow.png'

delete L.Icon.Default.prototype._getIconUrl
L.Icon.Default.mergeOptions({ iconUrl, iconRetinaUrl, shadowUrl })

// PDOK tile layer definitions
const PDOK_LAYERS = {
	brt: {
		name: 'PDOK BRT Achtergrondkaart',
		url: 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png',
		attribution: 'Kaartgegevens &copy; <a href="https://www.kadaster.nl">Kadaster</a>',
		maxZoom: 19,
	},
	brtGrijs: {
		name: 'PDOK BRT Grijs',
		url: 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/grijs/EPSG:3857/{z}/{x}/{y}.png',
		attribution: 'Kaartgegevens &copy; <a href="https://www.kadaster.nl">Kadaster</a>',
		maxZoom: 19,
	},
	luchtfoto: {
		name: 'PDOK Luchtfoto',
		url: 'https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0/Actueel_orthoHR/EPSG:3857/{z}/{x}/{y}.jpeg',
		attribution: 'Luchtfoto &copy; <a href="https://www.kadaster.nl">Kadaster</a>',
		maxZoom: 19,
	},
}

// Netherlands center and default zoom
const NL_CENTER = [52.1326, 5.2913]
const NL_ZOOM = 7

export default {
	name: 'CaseMap',
	components: { MapLegend },
	props: {
		/** Initial center as [lat, lng]. Defaults to Netherlands center. */
		center: {
			type: Array,
			default: () => NL_CENTER,
		},
		/** Initial zoom level. */
		zoom: {
			type: Number,
			default: NL_ZOOM,
		},
		/** Array of GeoJSON geometry objects to display. Each can have a `properties` object. */
		geometries: {
			type: Array,
			default: () => [],
		},
		/** CRS of the incoming geometries (e.g., "EPSG:28992" for RD). */
		crs: {
			type: String,
			default: null,
		},
		/** Whether to auto-fit bounds to displayed geometries. */
		autoFit: {
			type: Boolean,
			default: true,
		},
		/** Whether to show the status color legend. */
		showLegend: {
			type: Boolean,
			default: false,
		},
		/** Whether to enable marker clustering. */
		clustering: {
			type: Boolean,
			default: false,
		},
		/** Additional WMS/WFS overlay layers (array of MapLayer config objects). */
		overlayLayers: {
			type: Array,
			default: () => [],
		},
		/** Height of the map container. */
		height: {
			type: String,
			default: '500px',
		},
	},
	emits: ['click', 'marker-click', 'bounds-changed'],
	data() {
		return {
			map: null,
			baseLayers: {},
			markerClusterGroup: null,
			geoJsonLayer: null,
			overlayLayerInstances: {},
		}
	},
	watch: {
		geometries: {
			/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
			handler() {
				this.renderGeometries()
			},
			deep: true,
		},
		overlayLayers: {
			/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
			handler() {
				this.updateOverlayLayers()
			},
			deep: true,
		},
	},
	mounted() {
		this.initMap()
	},
	/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
	beforeDestroy() {
		if (this.map) {
			this.map.remove()
			this.map = null
		}
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		initMap() {
			this.map = L.map(this.$refs.mapElement, {
				center: this.center,
				zoom: this.zoom,
				zoomControl: true,
				attributionControl: true,
			})

			// Add PDOK base layers
			Object.entries(PDOK_LAYERS).forEach(([key, def]) => {
				this.baseLayers[def.name] = L.tileLayer(def.url, {
					attribution: def.attribution,
					maxZoom: def.maxZoom,
				})
			})

			// Add default base layer
			this.baseLayers[PDOK_LAYERS.brt.name].addTo(this.map)

			// Add layer control
			L.control.layers(this.baseLayers, {}, { position: 'topright' }).addTo(this.map)

			// Handle map events
			this.map.on('click', (e) => {
				this.$emit('click', { lat: e.latlng.lat, lng: e.latlng.lng })
			})

			this.map.on('moveend', () => {
				const bounds = this.map.getBounds()
				this.$emit('bounds-changed', {
					north: bounds.getNorth(),
					south: bounds.getSouth(),
					east: bounds.getEast(),
					west: bounds.getWest(),
				})
			})

			// Handle tile errors gracefully
			this.baseLayers[PDOK_LAYERS.brt.name].on('tileerror', () => {
				// Silent — map remains interactive with grey tiles
			})

			// Initialize marker cluster group if enabled
			if (this.clustering) {
				this.markerClusterGroup = L.markerClusterGroup({
					iconCreateFunction: this.createClusterIcon,
					maxClusterRadius: 50,
					spiderfyOnMaxZoom: true,
					showCoverageOnHover: false,
				})
				this.map.addLayer(this.markerClusterGroup)
			}

			// Render initial geometries
			this.renderGeometries()

			// Update overlay layers
			this.updateOverlayLayers()

			// Fix map size if container was hidden
			this.$nextTick(() => {
				this.map.invalidateSize()
			})
		},

		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		renderGeometries() {
			if (!this.map) return

			// Clear existing geometry layers
			if (this.geoJsonLayer) {
				this.map.removeLayer(this.geoJsonLayer)
			}
			if (this.markerClusterGroup) {
				this.markerClusterGroup.clearLayers()
			}

			if (!this.geometries || this.geometries.length === 0) return

			// Convert geometries to WGS84 if needed
			const features = this.geometries
				.filter(g => g && g.type)
				.map(g => {
					const geometry = ensureWgs84(g.geometry || g, this.crs)
					return {
						type: 'Feature',
						geometry,
						properties: g.properties || {},
					}
				})

			const featureCollection = {
				type: 'FeatureCollection',
				features,
			}

			this.geoJsonLayer = L.geoJSON(featureCollection, {
				pointToLayer: (feature, latlng) => {
					const color = feature.properties?.markerColor || '#2196F3'
					return L.circleMarker(latlng, {
						radius: 8,
						fillColor: color,
						color: '#fff',
						weight: 2,
						opacity: 1,
						fillOpacity: 0.8,
					})
				},
				style: (feature) => {
					const color = feature.properties?.markerColor || '#2196F3'
					return {
						color,
						weight: 2,
						fillColor: color,
						fillOpacity: 0.2,
					}
				},
				onEachFeature: (feature, layer) => {
					layer.on('click', () => {
						this.$emit('marker-click', feature.properties)
					})
				},
			})

			if (this.clustering && this.markerClusterGroup) {
				this.markerClusterGroup.addLayer(this.geoJsonLayer)
			} else {
				this.geoJsonLayer.addTo(this.map)
			}

			// Auto-fit bounds
			if (this.autoFit && features.length > 0) {
				const target = this.clustering ? this.markerClusterGroup : this.geoJsonLayer
				const bounds = target.getBounds()
				if (bounds.isValid()) {
					this.map.fitBounds(bounds, { padding: [50, 50] })
				}
			}
		},

		/**
		 * @param cluster
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		createClusterIcon(cluster) {
			const count = cluster.getChildCount()
			let className = 'case-map-cluster case-map-cluster--green'
			if (count > 100) {
				className = 'case-map-cluster case-map-cluster--red'
			} else if (count > 50) {
				className = 'case-map-cluster case-map-cluster--orange'
			} else if (count > 10) {
				className = 'case-map-cluster case-map-cluster--yellow'
			}
			return L.divIcon({
				html: `<span>${count}</span>`,
				className,
				iconSize: L.point(40, 40),
			})
		},

		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		updateOverlayLayers() {
			if (!this.map) return

			// Remove existing overlays
			Object.values(this.overlayLayerInstances).forEach(layer => {
				this.map.removeLayer(layer)
			})
			this.overlayLayerInstances = {}

			// Add configured overlays
			this.overlayLayers.forEach(config => {
				let layer = null

				if (config.layerType === 'wms') {
					layer = L.tileLayer.wms(config.url, {
						layers: config.layers || '',
						format: config.format || 'image/png',
						transparent: true,
						attribution: config.attribution || '',
						opacity: config.opacity ?? 1.0,
					})
				} else if (config.layerType === 'tile') {
					layer = L.tileLayer(config.url, {
						attribution: config.attribution || '',
						opacity: config.opacity ?? 1.0,
						maxZoom: config.maxZoom || 19,
					})
				}

				if (layer && config.isDefault) {
					layer.addTo(this.map)
				}

				if (layer) {
					this.overlayLayerInstances[config.title || config.url] = layer
				}
			})
		},

		/** Expose the Leaflet map instance for parent components. */
		getMap() {
			return this.map
		},

		/** Force a map size recalculation. */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		invalidateSize() {
			if (this.map) {
				this.map.invalidateSize()
			}
		},

		/**
		 * @param event
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		onKeydown(event) {
			if (!this.map) return
			const panStep = 100
			switch (event.key) {
			case 'ArrowUp':
				this.map.panBy([0, -panStep])
				event.preventDefault()
				break
			case 'ArrowDown':
				this.map.panBy([0, panStep])
				event.preventDefault()
				break
			case 'ArrowLeft':
				this.map.panBy([-panStep, 0])
				event.preventDefault()
				break
			case 'ArrowRight':
				this.map.panBy([panStep, 0])
				event.preventDefault()
				break
			case '+':
			case '=':
				this.map.zoomIn()
				event.preventDefault()
				break
			case '-':
				this.map.zoomOut()
				event.preventDefault()
				break
			}
		},
	},
}
</script>

<style scoped>
.case-map {
	position: relative;
	width: 100%;
}

.case-map__leaflet {
	width: 100%;
	height: v-bind(height);
	z-index: 0;
}
</style>

<style scoped>
/* Cluster marker styles — Leaflet appends to body, so use :deep to escape scoping */
:deep(.case-map-cluster) {
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	color: #fff;
	font-weight: bold;
	font-size: 14px;
	box-shadow: 0 2px 6px rgba(0, 0, 0, .3);
}

:deep(.case-map-cluster--green) {
	background: #4caf50;
}

:deep(.case-map-cluster--yellow) {
	background: #ff9800;
}

:deep(.case-map-cluster--orange) {
	background: #f57c00;
}

:deep(.case-map-cluster--red) {
	background: #f44336;
}
</style>

<template>
	<div class="geo-viewer">
		<template v-if="hasGeometry">
			<CaseMap
				ref="map"
				:geometries="geometries"
				:overlay-layers="overlayLayers"
				:center="center"
				:zoom="zoom"
				:auto-fit="true"
				:clustering="false"
				:show-legend="false"
				:height="height" />
		</template>
		<NcEmptyContent
			v-else
			:name="t('procest', 'No location set')"
			:description="t('procest', 'This case has no geographic location yet.')">
			<template #icon>
				<MapMarkerOffIcon :size="48" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import MapMarkerOffIcon from 'vue-material-design-icons/MapMarkerOff.vue'
import { useGisStore } from '../../store/modules/gis.js'

const CaseMap = () => import(/* webpackChunkName: "map" */ './CaseMap.vue')

const NL_CENTER = [52.1326, 5.2913]

/**
 * GeoViewer — read-only embedded map of a single case location.
 *
 * A thin wrapper over `CaseMap`: it accepts an already-resolved GeoJSON
 * geometry (Point/Polygon) plus optional overlay layers and renders a
 * non-editable map centred on the location. Location editing is done
 * elsewhere (LocationPicker); this component only displays. When no geometry
 * is present it shows an empty-state rather than an empty map.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
export default {
	name: 'GeoViewer',
	components: { CaseMap, NcEmptyContent, MapMarkerOffIcon },
	props: {
		/** GeoJSON geometry (Point/Polygon/MultiPolygon) or null. */
		geometry: {
			type: Object,
			default: null,
		},
		/** Optional properties for the rendered feature (status drives colour). */
		properties: {
			type: Object,
			default: () => ({}),
		},
		/** Map height. */
		height: {
			type: String,
			default: '400px',
		},
		/** Initial zoom level when a Point geometry is present. */
		zoom: {
			type: Number,
			default: 15,
		},
		/** Whether to load configured WMS/WFS overlay layers from the gis store. */
		withOverlays: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		return {
			gisStore: useGisStore(),
		}
	},
	computed: {
		/**
		 * Whether a usable geometry is present.
		 *
		 * @return {boolean} True when geometry has coordinates.
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		hasGeometry() {
			return !!(this.geometry
				&& this.geometry.type
				&& Array.isArray(this.geometry.coordinates)
				&& this.geometry.coordinates.length > 0)
		},
		/**
		 * Geometry list passed to CaseMap.
		 *
		 * @return {Array<object>} Single-feature array (or empty).
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		geometries() {
			if (!this.hasGeometry) {
				return []
			}
			return [{
				type: 'Feature',
				geometry: this.geometry,
				properties: this.properties || {},
			}]
		},
		/**
		 * Centre coordinate for a Point geometry, falling back to NL centre.
		 *
		 * @return {Array<number>} `[lat, lng]`.
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		center() {
			if (this.hasGeometry
				&& this.geometry.type === 'Point'
				&& this.geometry.coordinates.length >= 2) {
				return [this.geometry.coordinates[1], this.geometry.coordinates[0]]
			}
			return NL_CENTER
		},
		/**
		 * Active overlay layers from the gis store (graceful: empty on failure).
		 *
		 * @return {Array<object>} Overlay layer configs.
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		overlayLayers() {
			if (!this.withOverlays) {
				return []
			}
			return this.gisStore.overlayLayers || []
		},
	},
	/** @spec openspec/specs/gis-integration/spec.md */
	async mounted() {
		if (this.withOverlays && (this.gisStore.layers || []).length === 0) {
			// Degrades silently when OpenRegister / layer config is unavailable.
			try {
				await this.gisStore.fetchLayers()
			} catch {
				// Map still renders with base layers only.
			}
		}
	},
}
</script>

<style scoped>
.geo-viewer {
	width: 100%;
}
</style>

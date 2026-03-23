<template>
	<div class="spatial-filter">
		<div class="spatial-filter__tools">
			<NcButton
				:type="activeMode === 'rectangle' ? 'primary' : 'secondary'"
				@click="startRectangle">
				{{ t('procest', 'Select area') }}
			</NcButton>
			<NcButton
				:type="activeMode === 'polygon' ? 'primary' : 'secondary'"
				@click="startPolygon">
				{{ t('procest', 'Draw polygon') }}
			</NcButton>
			<NcButton
				v-if="activeMode"
				type="tertiary-no-background"
				@click="clearSelection">
				{{ t('procest', 'Clear selection') }}
			</NcButton>
		</div>
		<div v-if="selectedArea" class="spatial-filter__info">
			<p>{{ t('procest', '{count} cases in selection', { count: selectedCaseCount }) }}</p>
		</div>
	</div>
</template>

<script>
import L from 'leaflet'
import 'leaflet-draw'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'SpatialFilter',
	components: { NcButton },
	props: {
		/** The Leaflet map instance to draw on. */
		map: {
			type: Object,
			default: null,
		},
		/** Number of cases matching the spatial selection. */
		selectedCaseCount: {
			type: Number,
			default: 0,
		},
	},
	emits: ['selection-change', 'clear'],
	data() {
		return {
			activeMode: null,
			selectedArea: null,
			drawControl: null,
			drawnItems: null,
		}
	},
	watch: {
		map(newMap) {
			if (newMap && !this.drawnItems) {
				this.initDrawLayer()
			}
		},
	},
	mounted() {
		if (this.map) {
			this.initDrawLayer()
		}
	},
	beforeDestroy() {
		this.cleanup()
	},
	methods: {
		initDrawLayer() {
			this.drawnItems = new L.FeatureGroup()
			this.map.addLayer(this.drawnItems)

			this.map.on(L.Draw.Event.CREATED, (e) => {
				this.drawnItems.clearLayers()
				this.drawnItems.addLayer(e.layer)
				const geojson = e.layer.toGeoJSON()
				this.selectedArea = geojson.geometry
				this.$emit('selection-change', this.selectedArea)
			})
		},

		startRectangle() {
			this.activeMode = 'rectangle'
			this.cleanup()
			this.initDrawLayer()
			const handler = new L.Draw.Rectangle(this.map)
			handler.enable()
		},

		startPolygon() {
			this.activeMode = 'polygon'
			this.cleanup()
			this.initDrawLayer()
			const handler = new L.Draw.Polygon(this.map, { allowIntersection: false })
			handler.enable()
		},

		clearSelection() {
			this.activeMode = null
			this.selectedArea = null
			if (this.drawnItems) {
				this.drawnItems.clearLayers()
			}
			this.$emit('clear')
		},

		cleanup() {
			if (this.drawControl && this.map) {
				this.map.removeControl(this.drawControl)
			}
		},
	},
}
</script>

<style scoped>
.spatial-filter__tools {
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}

.spatial-filter__info {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>

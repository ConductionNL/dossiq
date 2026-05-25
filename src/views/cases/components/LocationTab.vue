<template>
	<div class="location-tab">
		<template v-if="hasGeometry">
			<div class="location-tab__content">
				<div class="location-tab__map-area">
					<CaseMap
						ref="caseMap"
						:geometries="[mapGeometry]"
						:auto-fit="true"
						:center="mapCenter"
						:zoom="15"
						height="400px" />
				</div>
				<div class="location-tab__sidebar">
					<h4>{{ t('procest', 'Location details') }}</h4>

					<div v-if="address" class="location-tab__detail">
						<label>{{ t('procest', 'Address') }}</label>
						<span>{{ address }}</span>
					</div>

					<div v-if="geometry.type === 'Point'" class="location-tab__detail">
						<label>{{ t('procest', 'Coordinates') }}</label>
						<span>{{ geometry.coordinates[1].toFixed(6) }}, {{ geometry.coordinates[0].toFixed(6) }}</span>
					</div>

					<div v-if="geometry.type === 'Polygon' && area > 0" class="location-tab__detail">
						<label>{{ t('procest', 'Area') }}</label>
						<span>{{ formatArea(area) }}</span>
					</div>

					<div v-if="bagData" class="location-tab__bag">
						<h4>{{ t('procest', 'BAG Information') }}</h4>
						<div v-if="bagData.bouwjaar" class="location-tab__detail">
							<label>{{ t('procest', 'Construction year') }}</label>
							<span>{{ bagData.bouwjaar }}</span>
						</div>
						<div v-if="bagData.oppervlakte" class="location-tab__detail">
							<label>{{ t('procest', 'Floor area') }}</label>
							<span>{{ bagData.oppervlakte }} m&sup2;</span>
						</div>
						<div v-if="bagData.gebruiksdoel" class="location-tab__detail">
							<label>{{ t('procest', 'Usage type') }}</label>
							<span>{{ bagData.gebruiksdoel }}</span>
						</div>
						<div v-if="bagData.status" class="location-tab__detail">
							<label>{{ t('procest', 'Status') }}</label>
							<span>{{ bagData.status }}</span>
						</div>
					</div>

					<NcButton
						v-if="!isReadOnly"
						class="location-tab__edit-btn"
						@click="showPicker = true">
						{{ t('procest', 'Change location') }}
					</NcButton>
				</div>
			</div>
		</template>

		<template v-else>
			<div class="location-tab__empty">
				<p>{{ t('procest', 'No location set') }}</p>
				<NcButton
					v-if="!isReadOnly"
					type="primary"
					@click="showPicker = true">
					{{ t('procest', 'Add location') }}
				</NcButton>
			</div>
		</template>

		<LocationPicker
			v-if="showPicker"
			:initial-geometry="geometry"
			@save="onLocationSave"
			@cancel="showPicker = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useGisStore } from '../../../store/modules/gis.js'

// Lazy-load heavy map components
const CaseMap = () => import(/* webpackChunkName: "map" */ '../../../components/map/CaseMap.vue')
const LocationPicker = () => import(/* webpackChunkName: "map" */ '../../../components/map/LocationPicker.vue')

export default {
	name: 'LocationTab',
	components: { NcButton, CaseMap, LocationPicker },
	props: {
		/** The case's geometry field (GeoJSON object or JSON string). */
		geometry: {
			type: [Object, String],
			default: null,
		},
		/** Whether the case is read-only. */
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update-geometry'],
	data() {
		return {
			showPicker: false,
			address: '',
			bagData: null,
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		parsedGeometry() {
			if (!this.geometry) return null
			if (typeof this.geometry === 'string') {
				try {
					return JSON.parse(this.geometry)
				} catch {
					return null
				}
			}
			return this.geometry
		},
		hasGeometry() {
			return this.parsedGeometry && this.parsedGeometry.type
		},
		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		mapCenter() {
			const geo = this.parsedGeometry
			if (!geo) return [52.1326, 5.2913]
			if (geo.type === 'Point') {
				return [geo.coordinates[1], geo.coordinates[0]]
			}
			// For polygons, use centroid
			return this.calculateCentroid(geo)
		},
		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		mapGeometry() {
			return {
				geometry: this.parsedGeometry,
				properties: {},
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		area() {
			const geo = this.parsedGeometry
			if (!geo || geo.type !== 'Polygon') return 0
			return this.calculateArea(geo.coordinates[0])
		},
	},
	watch: {
		geometry: {
			immediate: true,
			/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
			handler() {
				this.loadAddress()
			},
		},
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		async loadAddress() {
			const geo = this.parsedGeometry
			if (!geo) {
				this.address = ''
				return
			}
			const gisStore = useGisStore()
			const center = this.mapCenter
			this.address = await gisStore.reverseGeocode(center[0], center[1])
		},

		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		onLocationSave(newGeometry) {
			this.showPicker = false
			this.$emit('update-geometry', newGeometry)
		},

		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		calculateCentroid(geo) {
			if (geo.type === 'Point') {
				return [geo.coordinates[1], geo.coordinates[0]]
			}
			if (geo.type === 'Polygon') {
				const ring = geo.coordinates[0]
				let latSum = 0
				let lngSum = 0
				ring.forEach(([lng, lat]) => {
					latSum += lat
					lngSum += lng
				})
				return [latSum / ring.length, lngSum / ring.length]
			}
			return [52.1326, 5.2913]
		},

		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		calculateArea(ring) {
			// Shoelace formula for approximate area in m2
			if (!ring || ring.length < 3) return 0
			let area = 0
			for (let i = 0; i < ring.length - 1; i++) {
				const [x1, y1] = ring[i]
				const [x2, y2] = ring[i + 1]
				area += x1 * y2 - x2 * y1
			}
			// Convert from degrees to approximate square meters
			return Math.abs(area / 2) * 111320 * 111320 * Math.cos((ring[0][1] * Math.PI) / 180)
		},

		/** @spec openspec/changes/retrofit-2026-05-25-case-location/tasks.md */
		formatArea(sqm) {
			if (sqm > 10000) {
				return `${(sqm / 10000).toFixed(2)} ha`
			}
			return `${Math.round(sqm)} m\u00B2`
		},
	},
}
</script>

<style scoped>
.location-tab__content {
	display: flex;
	gap: 16px;
}

.location-tab__map-area {
	flex: 1;
	min-width: 0;
}

.location-tab__sidebar {
	width: 280px;
	flex-shrink: 0;
}

.location-tab__sidebar h4 {
	margin: 0 0 12px;
	font-size: 14px;
	font-weight: 600;
}

.location-tab__detail {
	margin-bottom: 8px;
}

.location-tab__detail label {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.location-tab__detail span {
	font-size: 14px;
}

.location-tab__bag {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.location-tab__edit-btn {
	margin-top: 16px;
}

.location-tab__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px;
	color: var(--color-text-maxcontrast);
}

.location-tab__empty p {
	margin-bottom: 16px;
	font-size: 14px;
}

@media (max-width: 768px) {
	.location-tab__content {
		flex-direction: column;
	}

	.location-tab__sidebar {
		width: 100%;
	}
}
</style>

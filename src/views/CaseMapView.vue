<template>
	<div class="case-map-overview">
		<div class="case-map-overview__header">
			<h2>{{ t('procest', 'Case Map') }}</h2>
			<div class="case-map-overview__filters">
				<!-- Case type filter -->
				<NcSelect
					v-model="selectedCaseTypes"
					:options="caseTypeOptions"
					:placeholder="t('procest', 'All case types')"
					label="title"
					track-by="id"
					multiple
					class="case-map-overview__filter"
					@input="applyFilters" />

				<!-- Status filter -->
				<div class="case-map-overview__status-toggles">
					<NcCheckboxRadioSwitch
						:checked="showActive"
						@update:checked="v => { showActive = v; applyFilters() }">
						{{ t('procest', 'Active') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="showOverdue"
						@update:checked="v => { showOverdue = v; applyFilters() }">
						{{ t('procest', 'Overdue') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked="showClosed"
						@update:checked="v => { showClosed = v; applyFilters() }">
						{{ t('procest', 'Closed') }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- My cases toggle -->
				<NcCheckboxRadioSwitch
					:checked="onlyMyCases"
					@update:checked="v => { onlyMyCases = v; applyFilters() }">
					{{ t('procest', 'My cases') }}
				</NcCheckboxRadioSwitch>

				<span v-if="activeFilterCount > 0" class="case-map-overview__badge">
					{{ t('procest', '{count} filters active', { count: activeFilterCount }) }}
				</span>
			</div>
		</div>

		<div class="case-map-overview__body">
			<CaseMap
				ref="caseMap"
				:geometries="mapGeometries"
				:clustering="true"
				:show-legend="true"
				:overlay-layers="activeLayers"
				height="calc(100vh - 200px)"
				@marker-click="onMarkerClick"
				@bounds-changed="onBoundsChanged" />

			<SpatialFilter
				v-if="$refs.caseMap"
				:map="$refs.caseMap.getMap()"
				:selected-case-count="spatialSelectedCount"
				class="case-map-overview__spatial"
				@selection-change="onSpatialSelection"
				@clear="onSpatialClear" />
		</div>
	</div>
</template>

<script>
import { NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import { useGisStore } from '../store/modules/gis.js'

const CaseMap = () => import(/* webpackChunkName: "map" */ '../components/map/CaseMap.vue')
const SpatialFilter = () => import(/* webpackChunkName: "map" */ '../components/map/SpatialFilter.vue')

export default {
	name: 'CaseMapView',
	components: { NcSelect, NcCheckboxRadioSwitch, CaseMap, SpatialFilter },
	data() {
		return {
			cases: [],
			caseTypes: [],
			selectedCaseTypes: [],
			showActive: true,
			showOverdue: true,
			showClosed: false,
			onlyMyCases: false,
			loading: false,
			spatialSelectedCount: 0,
		}
	},
	computed: {
		caseTypeOptions() {
			return this.caseTypes
		},
		filteredCases() {
			const currentUser = OC?.currentUser || ''
			return this.cases.filter(c => {
				// Geometry filter: only cases with location
				if (!c.geometry) return false

				// Case type filter
				if (this.selectedCaseTypes.length > 0) {
					const typeIds = this.selectedCaseTypes.map(ct => ct.id)
					if (!typeIds.includes(c.caseType)) return false
				}

				// Status filter
				const category = this.getStatusCategory(c)
				if (category === 'active' && !this.showActive) return false
				if (category === 'overdue' && !this.showOverdue) return false
				if (category === 'closed' && !this.showClosed) return false

				// My cases filter
				if (this.onlyMyCases && c.assignee !== currentUser) return false

				return true
			})
		},
		mapGeometries() {
			return this.filteredCases.map(c => {
				let geometry = c.geometry
				if (typeof geometry === 'string') {
					try {
						geometry = JSON.parse(geometry)
					} catch {
						return null
					}
				}
				return {
					geometry,
					properties: {
						id: c.id,
						title: c.title,
						identifier: c.identifier,
						status: c.status,
						statusCategory: this.getStatusCategory(c),
						assignee: c.assignee,
						caseTypeName: this.getCaseTypeName(c.caseType),
						markerColor: this.getMarkerColor(c),
					},
				}
			}).filter(Boolean)
		},
		activeLayers() {
			const gisStore = useGisStore()
			return gisStore.overlayLayers.filter(l => l.isDefault)
		},
		activeFilterCount() {
			let count = 0
			if (this.selectedCaseTypes.length > 0) count++
			if (!this.showActive || !this.showOverdue || this.showClosed) count++
			if (this.onlyMyCases) count++
			return count
		},
	},
	async created() {
		await this.loadData()
	},
	methods: {
		async loadData() {
			this.loading = true
			const objectStore = useObjectStore()
			const gisStore = useGisStore()

			try {
				const [caseResult, typeResult] = await Promise.all([
					objectStore.fetchCollection('case', {}),
					objectStore.fetchCollection('caseType', {}),
				])
				this.cases = caseResult || []
				this.caseTypes = typeResult || []
				await gisStore.fetchLayers()
			} finally {
				this.loading = false
			}
		},

		applyFilters() {
			// Filters are reactive via computed properties — no action needed
		},

		getStatusCategory(caseObj) {
			if (caseObj.endDate) return 'closed'
			if (caseObj.deadline) {
				const deadline = new Date(caseObj.deadline)
				const now = new Date()
				const fiveDaysFromNow = new Date(now.getTime() + 5 * 24 * 60 * 60 * 1000)
				if (deadline < now) return 'overdue'
				if (deadline < fiveDaysFromNow) return 'nearDeadline'
			}
			return 'active'
		},

		getMarkerColor(caseObj) {
			const category = this.getStatusCategory(caseObj)
			switch (category) {
			case 'closed': return '#4CAF50'
			case 'overdue': return '#F44336'
			case 'nearDeadline': return '#FF9800'
			default: return '#2196F3'
			}
		},

		getCaseTypeName(typeId) {
			const ct = this.caseTypes.find(t => t.id === typeId)
			return ct?.title || ''
		},

		onMarkerClick(properties) {
			if (properties?.id) {
				this.$router.push({ name: 'CaseDetail', params: { id: properties.id } })
			}
		},

		onBoundsChanged() {
			// Could implement viewport-based loading here for large datasets
		},

		onSpatialSelection(geometry) {
			// Point-in-polygon filtering
			this.spatialSelectedCount = this.filteredCases.filter(c => {
				return this.isPointInPolygon(c, geometry)
			}).length
		},

		onSpatialClear() {
			this.spatialSelectedCount = 0
		},

		isPointInPolygon(caseObj, polygon) {
			if (!caseObj.geometry || !polygon) return false
			let geo = caseObj.geometry
			if (typeof geo === 'string') {
				try { geo = JSON.parse(geo) } catch { return false }
			}
			if (geo.type !== 'Point') return false
			const [lng, lat] = geo.coordinates
			return this.pointInPolygon([lng, lat], polygon.coordinates[0])
		},

		pointInPolygon(point, ring) {
			let inside = false
			for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
				const xi = ring[i][0]; const yi = ring[i][1]
				const xj = ring[j][0]; const yj = ring[j][1]
				const intersect = ((yi > point[1]) !== (yj > point[1]))
					&& (point[0] < (xj - xi) * (point[1] - yi) / (yj - yi) + xi)
				if (intersect) inside = !inside
			}
			return inside
		},
	},
}
</script>

<style scoped>
.case-map-overview {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.case-map-overview__header {
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.case-map-overview__header h2 {
	margin: 0 0 12px;
}

.case-map-overview__filters {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.case-map-overview__filter {
	min-width: 200px;
}

.case-map-overview__status-toggles {
	display: flex;
	gap: 8px;
}

.case-map-overview__badge {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
}

.case-map-overview__body {
	flex: 1;
	position: relative;
}

.case-map-overview__spatial {
	position: absolute;
	top: 12px;
	right: 12px;
	z-index: 1000;
	background: var(--color-main-background);
	padding: 8px 12px;
	border-radius: 8px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
}
</style>

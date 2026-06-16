<template>
	<div class="cases-on-map">
		<div class="cases-on-map__sidebar">
			<h2>{{ t('procest', 'Cases on map') }}</h2>
			<p class="cases-on-map__summary">
				{{ t('procest', 'Showing {filtered} of {total} located cases', { filtered: features.length, total: total }) }}
			</p>

			<NcSelect
				v-model="filterCaseType"
				:input-label="t('procest', 'Case type')"
				:options="caseTypeOptions"
				:placeholder="t('procest', 'All case types')"
				:clearable="true"
				class="cases-on-map__filter"
				@input="reload" />

			<NcSelect
				v-model="filterStatus"
				:input-label="t('procest', 'Status')"
				:options="statusOptions"
				:placeholder="t('procest', 'All statuses')"
				:clearable="true"
				class="cases-on-map__filter"
				@input="reload" />

			<div v-if="degraded" class="cases-on-map__notice">
				<AlertIcon :size="20" />
				<span>{{ t('procest', 'Map data could not be loaded. Showing what is available.') }}</span>
			</div>
		</div>

		<div class="cases-on-map__map">
			<CnMapWidget
				:markers="markers"
				:clustering="true"
				:auto-fit="features.length > 0"
				height="100%"
				@marker-click="onMarkerClick" />
			<NcLoadingIcon v-if="loading" :size="40" class="cases-on-map__loading" />
		</div>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import { CnMapWidget } from '@conduction/nextcloud-vue'
import { registerCasesOnMapOverview, fetchCasePoints } from '../services/casesOnMapApi.js'
import { shapeMarkerFeatures } from '../services/mapFormatters.js'

/**
 * CasesOnMapView — full-screen multi-object cases-on-map overview.
 *
 * MIGRATED to OpenRegister's page-level maps-overview leaf (ADR-022, OR #154):
 *
 * - On mount it DECLARES the `cases-on-map` overview with OR's maps-overview
 *   surface (POST /api/integrations/maps/overviews) and FETCHES the RBAC-scoped
 *   marker points from OR (GET .../maps/overviews/{register}/{schema}/points).
 *   OR owns the geometry extraction + RBAC scoping (fail-closed, no IDOR) and
 *   the declarative base-layer config (PDOK WMTS).
 * - The markers are rendered by the library's declarative `CnMapWidget`, which
 *   owns the Leaflet engine, clustering, and tile layers. Procest embeds NO
 *   Leaflet / WMS / WFS stack of its own — the bespoke `CaseMap` /
 *   `src/components/map/*` plumbing and the `/api/cases/geo` endpoint are gone.
 * - This view owns only data shaping (status colour via `shapeMarkerFeatures`)
 *   and the case-type / status filters, which it forwards to OR as object
 *   filters. Degrades gracefully when OR is unavailable.
 *
 * @spec openspec/specs/case-map-overview/spec.md
 */
export default {
	name: 'CasesOnMapView',
	components: { CnMapWidget, NcSelect, NcLoadingIcon, AlertIcon },
	props: {
		/** OpenRegister register slug holding the cases. @type {string} */
		register: {
			type: String,
			default: 'procest',
		},
		/** OpenRegister schema slug for the case objects. @type {string} */
		schema: {
			type: String,
			default: 'case',
		},
	},
	data() {
		return {
			points: [],
			total: 0,
			loading: false,
			degraded: false,
			filterCaseType: null,
			filterStatus: null,
			statusOptions: ['open', 'in_progress', 'blocked', 'closed'],
			caseTypeOptions: [],
		}
	},
	computed: {
		/**
		 * GeoJSON Point features for CnMapWidget, shaped from the OR point set.
		 *
		 * @return {Array<object>} GeoJSON Feature array.
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		features() {
			return shapeMarkerFeatures(this.points)
		},
		/**
		 * CnMapWidget marker config — inline features + popup field. The
		 * features already carry the status colour from `shapeMarkerFeatures`.
		 *
		 * @return {object} CnMapWidget `markers` prop.
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		markers() {
			return {
				features: this.features,
				popupField: 'title',
				clustering: true,
			}
		},
	},
	/**
	 * Declare the overview once (idempotent on the OR side), then load points.
	 *
	 * @return {void}
	 * @spec openspec/specs/case-map-overview/spec.md
	 */
	mounted() {
		registerCasesOnMapOverview({ register: this.register, schema: this.schema })
		this.reload()
	},
	methods: {
		/**
		 * Fetch the RBAC-scoped case points from OR for the active filters.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		async reload() {
			this.loading = true
			this.degraded = false
			const filters = {}
			if (this.filterCaseType) {
				filters.caseType = this.filterCaseType
			}
			if (this.filterStatus) {
				filters.status = this.filterStatus
			}
			try {
				const points = await fetchCasePoints({ register: this.register, schema: this.schema, filters })
				this.points = points
				if (!this.filterCaseType && !this.filterStatus) {
					this.total = points.length
				}
			} catch {
				this.degraded = true
				this.points = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate to the case detail when a marker is clicked.
		 *
		 * @param {object} payload `{ feature, latlng }` from CnMapWidget.
		 * @return {void}
		 * @spec openspec/specs/case-map-overview/spec.md
		 */
		onMarkerClick(payload) {
			const caseId = payload?.feature?.properties?.caseId
			if (!caseId) {
				return
			}
			this.$router?.push({ name: 'CaseDetail', params: { id: caseId } })
		},
	},
}
</script>

<style scoped>
.cases-on-map {
	display: flex;
	height: 100%;
	width: 100%;
}

.cases-on-map__sidebar {
	width: 300px;
	min-width: 240px;
	padding: 16px;
	overflow-y: auto;
	border-inline-end: 1px solid var(--color-border);
}

.cases-on-map__summary {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.cases-on-map__filter {
	width: 100%;
	margin-bottom: 12px;
}

.cases-on-map__notice {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	margin: 12px 0;
	border-radius: var(--border-radius);
	background: var(--color-warning, var(--color-background-hover));
}

.cases-on-map__map {
	position: relative;
	flex: 1;
	min-width: 0;
}

.cases-on-map__loading {
	position: absolute;
	top: 16px;
	inset-inline-end: 16px;
	z-index: 1000;
}

@media (max-width: 700px) {
	.cases-on-map {
		flex-direction: column;
	}

	.cases-on-map__sidebar {
		width: 100%;
		border-inline-end: none;
		border-bottom: 1px solid var(--color-border);
	}
}
</style>

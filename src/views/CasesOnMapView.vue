<template>
	<div class="cases-on-map">
		<div class="cases-on-map__sidebar">
			<h2>{{ t('procest', 'Cases on map') }}</h2>
			<p class="cases-on-map__summary">
				{{ t('procest', 'Showing {filtered} of {total} located cases', { filtered: summary.filtered, total: summary.total }) }}
			</p>

			<NcSelect
				v-model="filterZaaktype"
				:input-label="t('procest', 'Case type')"
				:options="zaaktypeOptions"
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

			<NcButton
				:disabled="summary.cases === 0"
				class="cases-on-map__export"
				@click="exportGeoJson">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('procest', 'Export visible cases (GeoJSON)') }}
			</NcButton>
		</div>

		<div class="cases-on-map__map">
			<CaseMap
				ref="map"
				:geometries="geometries"
				:clustering="false"
				:auto-fit="geometries.length > 0"
				:show-legend="true"
				height="100%"
				@marker-click="onMarkerClick"
				@bounds-changed="onBoundsChanged" />
			<NcLoadingIcon v-if="loading" :size="40" class="cases-on-map__loading" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import { toMapGeometries, buildGeoQuery, summariseGeo, toExportGeoJson } from '../services/caseGeoService.js'

const CaseMap = () => import(/* webpackChunkName: "map" */ '../components/map/CaseMap.vue')

/**
 * CasesOnMapView — full-screen cases-on-map dashboard.
 *
 * Fetches the clustered `/api/cases/geo` FeatureCollection, shapes it into
 * CaseMap geometries via the pure `caseGeoService` helpers, and offers
 * zaaktype/status filtering plus a GeoJSON export of the visible cases.
 * Map rendering is delegated to CaseMap; this view owns only data + filters.
 * Degrades gracefully when the backend / PDOK tiles are unavailable.
 *
 * @spec openspec/specs/gis-integration/spec.md
 */
export default {
	name: 'CasesOnMapView',
	components: { CaseMap, NcButton, NcSelect, NcLoadingIcon, AlertIcon, DownloadIcon },
	data() {
		return {
			collection: null,
			loading: false,
			degraded: false,
			filterZaaktype: null,
			filterStatus: null,
			zoom: 7,
			bounds: null,
			statusOptions: ['open', 'in_progress', 'blocked', 'closed'],
			zaaktypeOptions: [],
		}
	},
	computed: {
		/**
		 * Geometry descriptors for CaseMap.
		 *
		 * @return {Array<object>} Geometries.
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		geometries() {
			return toMapGeometries(this.collection)
		},
		/**
		 * Count summary for the badge.
		 *
		 * @return {object} `{ total, filtered, clusters, cases }`.
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		summary() {
			return summariseGeo(this.collection)
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		/**
		 * Fetch the cases-on-map FeatureCollection for the active filters.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		async reload() {
			this.loading = true
			this.degraded = false
			const query = buildGeoQuery({
				zaaktype: this.filterZaaktype,
				status: this.filterStatus,
				zoom: this.zoom,
				bounds: this.bounds,
			})
			try {
				const url = generateUrl('/apps/procest/api/cases/geo') + (query ? `?${query}` : '')
				const response = await axios.get(url)
				this.collection = response.data
				this.collectZaaktypes()
			} catch {
				// Graceful degradation — keep last good data, flag the banner.
				this.degraded = true
				if (!this.collection) {
					this.collection = { type: 'FeatureCollection', features: [], total: 0, filtered: 0 }
				}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Harvest distinct zaaktypes from the loaded features for the filter.
		 *
		 * @return {void}
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		collectZaaktypes() {
			const seen = new Set(this.zaaktypeOptions)
			for (const feature of (this.collection?.features || [])) {
				const zaaktype = feature?.properties?.zaaktype
				if (zaaktype) {
					seen.add(zaaktype)
				}
			}
			this.zaaktypeOptions = [...seen]
		},
		/**
		 * Navigate to the case detail (skips cluster markers).
		 *
		 * @param {object} properties Clicked feature properties.
		 * @return {void}
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		onMarkerClick(properties) {
			if (properties?.cluster === true || !properties?.caseId) {
				return
			}
			this.$router?.push({ name: 'CaseDetail', params: { id: properties.caseId } })
		},
		/**
		 * Update the viewport bounds and re-query (server-side bbox filter).
		 *
		 * @param {object} bounds `{ north, south, east, west }`.
		 * @return {void}
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		onBoundsChanged(bounds) {
			this.bounds = bounds
		},
		/**
		 * Download the currently-visible single cases as a GeoJSON file.
		 *
		 * @return {void}
		 * @spec openspec/specs/gis-integration/spec.md
		 */
		exportGeoJson() {
			const json = toExportGeoJson(this.collection)
			const blob = new Blob([json], { type: 'application/geo+json' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = 'procest-cases.geojson'
			link.click()
			URL.revokeObjectURL(link.href)
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

.cases-on-map__export {
	margin-top: 16px;
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

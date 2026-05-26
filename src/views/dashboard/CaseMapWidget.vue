<template>
	<div class="case-map-widget">
		<CaseMap
			v-if="geometries.length > 0"
			:geometries="geometries"
			:clustering="true"
			height="400px"
			@marker-click="onMarkerClick" />
		<div v-else class="case-map-widget__empty">
			<p>{{ t('procest', 'No cases with location data') }}</p>
		</div>
	</div>
</template>

<script>
import { useObjectStore } from '../../store/modules/object.js'

const CaseMap = () => import(/* webpackChunkName: "map" */ '../../components/map/CaseMap.vue')

export default {
	name: 'CaseMapWidget',
	components: { CaseMap },
	data() {
		return {
			cases: [],
		}
	},
	computed: {
		/** @spec openspec/specs/case-map-overview/spec.md */
		geometries() {
			return this.cases
				.filter(c => c.geometry)
				.map(c => {
					let geometry = c.geometry
					if (typeof geometry === 'string') {
						try { geometry = JSON.parse(geometry) } catch { return null }
					}
					return {
						geometry,
						properties: {
							id: c.id,
							title: c.title,
							identifier: c.identifier,
							markerColor: this.getColor(c),
						},
					}
				})
				.filter(Boolean)
		},
	},
	/** @spec openspec/specs/case-map-overview/spec.md */
	async created() {
		const objectStore = useObjectStore()
		const currentUser = OC?.currentUser || ''
		try {
			const result = await objectStore.fetchCollection('case', {})
			const allCases = result || []
			// Show current user's assigned cases
			this.cases = allCases.filter(c => c.assignee === currentUser && c.geometry)
		} catch {
			this.cases = []
		}
	},
	methods: {
		/** @spec openspec/specs/case-map-overview/spec.md */
		getColor(caseObj) {
			if (caseObj.endDate) return '#4CAF50'
			if (caseObj.deadline) {
				const deadline = new Date(caseObj.deadline)
				if (deadline < new Date()) return '#F44336'
			}
			return '#2196F3'
		},
		/** @spec openspec/specs/case-map-overview/spec.md */
		onMarkerClick(properties) {
			if (properties?.id) {
				this.$router?.push({ name: 'CaseDetail', params: { id: properties.id } })
			}
		},
	},
}
</script>

<style scoped>
.case-map-widget {
	min-height: 400px;
}

.case-map-widget__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 400px;
	color: var(--color-text-maxcontrast);
}
</style>

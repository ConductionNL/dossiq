<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="parafeer-routes-tab">
		<div class="parafeer-routes-tab__header">
			<NcButton type="primary" @click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('procest', 'Nieuwe route') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent v-else-if="!routes.length"
			:name="t('procest', 'Geen parafeerroutes geconfigureerd')"
			:description="t('procest', 'Voeg een route toe om voorstellen door een vaste accorderingslijn te laten lopen.')">
			<template #icon>
				<RoutesClock :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="parafeer-routes-tab__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Naam') }}</th>
					<th>{{ t('procest', 'Type voorstel') }}</th>
					<th>{{ t('procest', 'Zaaktype') }}</th>
					<th>{{ t('procest', 'Stappen') }}</th>
					<th>{{ t('procest', 'Standaard') }}</th>
					<th class="parafeer-routes-tab__col-actions">
						{{ t('procest', 'Acties') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="route in routes" :key="route.id || route.uuid">
					<td><strong>{{ route.name }}</strong></td>
					<td>
						<CnStatusBadge :status="formatVoorstelType(route.voorstelType)" type="info" />
					</td>
					<td>{{ formatCaseType(route.caseType) }}</td>
					<td>{{ getStepCount(route) }}</td>
					<td>
						<CnStatusBadge v-if="route.isDefault"
							:status="t('procest', 'Standaard')"
							type="success" />
					</td>
					<td>
						<NcButton type="tertiary"
							:title="t('procest', 'Bewerken')"
							@click="openEdit(route)">
							<template #icon>
								<Pencil :size="18" />
							</template>
						</NcButton>
						<NcButton type="tertiary"
							:title="t('procest', 'Verwijderen')"
							@click="confirmDelete(route)">
							<template #icon>
								<Delete :size="18" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<ParafeerRouteDialog :open="dialogOpen"
			:route="editingRoute"
			:case-types="caseTypes"
			@saved="onSaved"
			@close="closeDialog" />

		<NcDialog v-if="deleteCandidate"
			:name="t('procest', 'Parafeerroute verwijderen?')"
			@closing="deleteCandidate = null">
			<p>
				{{ t('procest', 'Weet u zeker dat u de route "{name}" wilt verwijderen?', { name: deleteCandidate.name }) }}
			</p>
			<template #actions>
				<NcButton @click="deleteCandidate = null">
					{{ t('procest', 'Annuleren') }}
				</NcButton>
				<NcButton type="error" :disabled="deleting" @click="doDelete">
					{{ deleting ? t('procest', 'Verwijderen...') : t('procest', 'Verwijderen') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import RoutesClock from 'vue-material-design-icons/RoutesClock.vue'
import ParafeerRouteDialog from './ParafeerRouteDialog.vue'
import parafeerRouteApi from '../../../services/parafeerRouteApi.js'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'ParafeerRoutesTab',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		CnStatusBadge,
		Plus,
		Pencil,
		Delete,
		RoutesClock,
		ParafeerRouteDialog,
	},
	data() {
		return {
			loading: true,
			routes: [],
			caseTypes: [],
			dialogOpen: false,
			editingRoute: null,
			deleteCandidate: null,
			deleting: false,
			errorMessage: '',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	async created() {
		await this.refresh()
	},
	methods: {
		async refresh() {
			this.loading = true
			this.errorMessage = ''
			try {
				const [routes, caseTypes] = await Promise.all([
					parafeerRouteApi.listRoutes(),
					this.fetchCaseTypes(),
				])
				this.routes = routes
				this.caseTypes = caseTypes
			} catch (err) {
				console.error('Failed to load parafeerroutes', err)
				this.errorMessage = this.t('procest', 'Kon parafeerroutes niet ophalen')
			} finally {
				this.loading = false
			}
		},
		async fetchCaseTypes() {
			try {
				const result = await this.objectStore.fetchCollection('caseType', { _limit: 100 })
				return Array.isArray(result) ? result : (result?.results || [])
			} catch (err) {
				console.error('Failed to load case types', err)
				return []
			}
		},
		openCreate() {
			this.editingRoute = null
			this.dialogOpen = true
		},
		openEdit(route) {
			this.editingRoute = route
			this.dialogOpen = true
		},
		closeDialog() {
			this.dialogOpen = false
			this.editingRoute = null
		},
		async onSaved() {
			this.closeDialog()
			await this.refresh()
		},
		confirmDelete(route) {
			this.deleteCandidate = route
		},
		async doDelete() {
			if (!this.deleteCandidate) return
			const id = this.deleteCandidate.id || this.deleteCandidate.uuid
			this.deleting = true
			this.errorMessage = ''
			try {
				await parafeerRouteApi.deleteRoute(id)
				this.deleteCandidate = null
				await this.refresh()
			} catch (err) {
				const status = err?.response?.status
				if (status === 409) {
					this.errorMessage = this.t('procest', 'Route is in gebruik door actieve voorstellen')
				} else {
					this.errorMessage = this.t('procest', 'Verwijderen mislukt')
				}
				console.error('parafeerroute delete failed', err)
			} finally {
				this.deleting = false
			}
		},
		formatVoorstelType(type) {
			const labels = {
				dt_advies: this.t('procest', 'DT-advies'),
				collegeadvies: this.t('procest', 'Collegeadvies'),
				raadsvoorstel: this.t('procest', 'Raadsvoorstel'),
			}
			return labels[type] || type || '-'
		},
		formatCaseType(id) {
			if (!id) return this.t('procest', 'Alle zaaktypen')
			const match = this.caseTypes.find(ct => ct.id === id || ct.uuid === id)
			return match?.title || id
		},
		getStepCount(route) {
			if (typeof route.steps === 'string') {
				try { return JSON.parse(route.steps || '[]').length } catch { return 0 }
			}
			return Array.isArray(route.steps) ? route.steps.length : 0
		},
	},
}
</script>

<style scoped>
.parafeer-routes-tab__header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}

.parafeer-routes-tab__table {
	width: 100%;
	border-collapse: collapse;
}

.parafeer-routes-tab__table th,
.parafeer-routes-tab__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
	vertical-align: middle;
}

.parafeer-routes-tab__col-actions {
	width: 1%;
	white-space: nowrap;
}
</style>

<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="vth-dashboard">
		<!-- Header -->
		<div class="vth-dashboard__header">
			<h2>{{ t('procest', 'VTH Dashboard — Omgevingsvergunningen') }}</h2>
			<div class="vth-dashboard__actions">
				<NcButton type="tertiary" @click="$router.push({ name: 'Dashboard' })">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('procest', 'Dashboard') }}
				</NcButton>
			</div>
		</div>

		<!-- Filter bar -->
		<div class="vth-dashboard__filters">
			<NcSelect v-model="filters.dsoStatus"
				:options="statusOptions"
				:placeholder="t('procest', 'All statuses')"
				:input-label="t('procest', 'Status filter')"
				input-id="status-filter"
				@update:model-value="loadDashboard" />
			<NcSelect v-model="filters.procedureType"
				:options="procedureOptions"
				:placeholder="t('procest', 'All procedure types')"
				:input-label="t('procest', 'Procedure type filter')"
				input-id="procedure-filter"
				@update:model-value="loadDashboard" />
			<NcSelect v-model="filters.deadlineRange"
				:options="deadlineRangeOptions"
				:placeholder="t('procest', 'Any deadline')"
				:input-label="t('procest', 'Deadline filter')"
				input-id="deadline-filter"
				@update:model-value="loadDashboard" />
			<NcTextField v-model="filters.gemeenteCode"
				:label="t('procest', 'Gemeente code')"
				@change="loadDashboard" />
		</div>

		<!-- Results table -->
		<div v-if="loading" class="vth-dashboard__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="error" class="vth-dashboard__error">
			<NcEmptyContent :title="t('procest', 'Could not load dashboard')" :description="error">
				<template #icon>
					<AlertCircleOutline :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<div v-else-if="cases.length === 0" class="vth-dashboard__empty">
			<NcEmptyContent :title="t('procest', 'No omgevingsvergunningen found')"
				:description="t('procest', 'Adjust the filters or wait for new aanvragen from DSO.')">
				<template #icon>
					<BriefcaseOutline :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<table v-else class="vth-dashboard__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Case') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Procedure') }}</th>
					<th>{{ t('procest', 'Deadline') }}</th>
					<th>{{ t('procest', 'Bevoegd gezag') }}</th>
					<th>{{ t('procest', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="zaak in cases" :key="zaak.uuid || zaak.id">
					<td class="vth-dashboard__case-title">
						{{ zaak.title || '—' }}
					</td>
					<td>
						<span :class="statusClass(zaak.dsoStatus)">
							{{ zaak.dsoStatus || '—' }}
						</span>
					</td>
					<td>{{ zaak.procedureType || '—' }}</td>
					<td>
						<span :class="deadlineClass(zaak.deadlineDatum)">
							{{ formatDeadline(zaak.deadlineDatum) }}
						</span>
					</td>
					<td>{{ zaak.bevoegdGezag || '—' }}</td>
					<td>
						<NcButton type="secondary"
							:aria-label="t('procest', 'Open case detail')"
							@click="openDetail(zaak)">
							{{ t('procest', 'Open') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Detail modal -->
		<DsoCaseDetail v-if="selectedCase"
			:zaak="selectedCase"
			@close="selectedCase = null"
			@transition="onTransition" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import DsoCaseDetail from '../../dialogs/DsoCaseDetail.vue'

export default {
	name: 'VthDashboard',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		AlertCircleOutline,
		ArrowLeft,
		BriefcaseOutline,
		DsoCaseDetail,
	},
	data() {
		return {
			cases: [],
			loading: false,
			error: null,
			selectedCase: null,
			filters: {
				dsoStatus: null,
				procedureType: null,
				deadlineRange: null,
				gemeenteCode: '',
			},
			statusOptions: [
				{ label: t('procest', 'Ingediend'), value: 'ingediend' },
				{ label: t('procest', 'In behandeling'), value: 'in_behandeling' },
				{ label: t('procest', 'Verleend'), value: 'verleend' },
				{ label: t('procest', 'Geweigerd'), value: 'geweigerd' },
				{ label: t('procest', 'Ingetrokken'), value: 'ingetrokken' },
			],
			procedureOptions: [
				{ label: t('procest', 'Reguliere procedure (8 wk)'), value: 'reguliere' },
				{ label: t('procest', 'Uitgebreide procedure (26 wk)'), value: 'uitgebreide' },
			],
			deadlineRangeOptions: [
				{ label: t('procest', 'Overdue'), value: 'overdue' },
				{ label: t('procest', 'Due today'), value: 'today' },
				{ label: t('procest', 'Due this week'), value: 'week' },
			],
		}
	},
	mounted() {
		this.loadDashboard()
	},
	methods: {
		t,
		async loadDashboard() {
			this.loading = true
			this.error = null
			try {
				const params = {}
				if (this.filters.dsoStatus?.value) {
					params.dsoStatus = this.filters.dsoStatus.value
				}

				if (this.filters.procedureType?.value) {
					params.procedureType = this.filters.procedureType.value
				}

				if (this.filters.deadlineRange?.value) {
					params.deadlineRange = this.filters.deadlineRange.value
				}

				if (this.filters.gemeenteCode) {
					params.gemeenteCode = this.filters.gemeenteCode
				}

				const { data } = await axios.get(
					generateUrl('/apps/procest/api/dso/dashboard'),
					{ params },
				)
				this.cases = data.results || []
			} catch (e) {
				this.error = t('procest', 'Failed to load dashboard data')
			} finally {
				this.loading = false
			}
		},
		openDetail(zaak) {
			this.selectedCase = zaak
		},
		onTransition(updatedZaak) {
			const idx = this.cases.findIndex(z => (z.uuid || z.id) === (updatedZaak.uuid || updatedZaak.id))
			if (idx !== -1) {
				this.cases.splice(idx, 1, updatedZaak)
			}

			this.selectedCase = updatedZaak
		},
		formatDeadline(deadlineDatum) {
			if (!deadlineDatum) {
				return '—'
			}

			return new Date(deadlineDatum).toLocaleDateString('nl-NL')
		},
		deadlineClass(deadlineDatum) {
			if (!deadlineDatum) {
				return ''
			}

			const deadline = new Date(deadlineDatum)
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const daysLeft = Math.ceil((deadline - today) / (1000 * 60 * 60 * 24))
			if (daysLeft < 0) {
				return 'deadline-indicator deadline-indicator--overdue'
			}

			if (daysLeft <= 5) {
				return 'deadline-indicator deadline-indicator--critical'
			}

			if (daysLeft <= 14) {
				return 'deadline-indicator deadline-indicator--warning'
			}

			return 'deadline-indicator deadline-indicator--ok'
		},
		statusClass(status) {
			return 'status-badge status-badge--' + (status || 'unknown').replace('_', '-')
		},
	},
}
</script>

<style scoped>
.vth-dashboard {
	padding: 16px;
}

.vth-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.vth-dashboard__filters {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.vth-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.vth-dashboard__table th,
.vth-dashboard__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.deadline-indicator--overdue { color: var(--color-error); font-weight: bold; }
.deadline-indicator--critical { color: var(--color-error); }
.deadline-indicator--warning { color: var(--color-warning); }
.deadline-indicator--ok { color: var(--color-success); }

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
}
</style>

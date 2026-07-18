<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
-->
<template>
	<div class="vth-dashboard">
		<div class="vth-dashboard__header">
			<h2>{{ t('procest', 'VTH Dashboard — Omgevingsvergunningen') }}</h2>
		</div>

		<!-- Filter bar -->
		<div class="vth-dashboard__filters">
			<NcSelect
				v-model="filters.status"
				:options="statusOptions"
				:placeholder="t('procest', 'Filter by status')"
				:input-label="t('procest', 'Status')"
				label="label"
				track-by="value"
				:multiple="true"
				class="vth-filter" />
			<NcSelect
				v-model="filters.procedureType"
				:options="procedureTypeOptions"
				:placeholder="t('procest', 'Procedure type')"
				:input-label="t('procest', 'Procedure type')"
				label="label"
				track-by="value"
				class="vth-filter" />
			<NcTextField
				v-model="filters.gemeenteCode"
				:label="t('procest', 'Gemeentecode')"
				:placeholder="t('procest', '0363')"
				class="vth-filter vth-filter--small" />
			<NcTextField
				v-model="filters.activiteitgroep"
				:label="t('procest', 'Activiteitgroep')"
				:placeholder="t('procest', 'bouwactiviteiten')"
				class="vth-filter" />
			<NcButton @click="loadCases">
				{{ t('procest', 'Apply filters') }}
			</NcButton>
			<NcButton type="secondary" @click="resetFilters">
				{{ t('procest', 'Reset') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="vth-dashboard__loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('procest', 'Loading omgevingsvergunningen...') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="error" class="vth-dashboard__error">
			<span>{{ error }}</span>
		</div>

		<!-- Cases table -->
		<div v-else class="vth-dashboard__table-wrapper">
			<table class="vth-dashboard__table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Identifier') }}</th>
						<th>{{ t('procest', 'Titel') }}</th>
						<th>{{ t('procest', 'Status') }}</th>
						<th>{{ t('procest', 'Procedure') }}</th>
						<th>{{ t('procest', 'Bevoegd gezag') }}</th>
						<th>{{ t('procest', 'Deadline') }}</th>
						<th>{{ t('procest', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="cases.length === 0">
						<td colspan="7" class="vth-dashboard__empty">
							{{ t('procest', 'No omgevingsvergunningen found.') }}
						</td>
					</tr>
					<tr v-for="zaak in cases" :key="zaak.id" class="vth-dashboard__row">
						<td>{{ zaak.identifier || zaak.id }}</td>
						<td>{{ zaak.title }}</td>
						<td>
							<span :class="'vth-status vth-status--' + getStatusSlug(zaak)">
								{{ getStatusLabel(zaak) }}
							</span>
						</td>
						<td>{{ zaak.procedureType || '—' }}</td>
						<td>{{ zaak.bevoegdGezag || '—' }}</td>
						<td>
							<span :class="getDeadlineClass(zaak)">
								{{ formatDeadline(zaak.deadlineDatum) }}
							</span>
						</td>
						<td>
							<NcButton type="tertiary" @click="openDetail(zaak)">
								{{ t('procest', 'Open') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Detail modal -->
		<DsoCaseDetail
			v-if="selectedCase"
			:zaak="selectedCase"
			@close="selectedCase = null"
			@transition="onCaseUpdated" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DsoCaseDetail from '../../dialogs/DsoCaseDetail.vue'

export default {
	name: 'VthDashboard',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		DsoCaseDetail,
	},
	data() {
		return {
			cases: [],
			loading: false,
			error: null,
			selectedCase: null,
			filters: {
				status: [],
				procedureType: null,
				gemeenteCode: '',
				activiteitgroep: '',
				regelkwalificatie: '',
			},
			statusOptions: [
				{ label: t('procest', 'Ingediend'), value: 'ingediend' },
				{ label: t('procest', 'In behandeling'), value: 'in_behandeling' },
				{ label: t('procest', 'Verleend'), value: 'verleend' },
				{ label: t('procest', 'Geweigerd'), value: 'geweigerd' },
				{ label: t('procest', 'Ingetrokken'), value: 'ingetrokken' },
			],
			procedureTypeOptions: [
				{ label: t('procest', 'Reguliere procedure (8 weken)'), value: 'reguliere' },
				{ label: t('procest', 'Uitgebreide procedure (26 weken)'), value: 'uitgebreide' },
			],
		}
	},
	mounted() {
		this.loadCases()
	},
	methods: {
		t,
		async loadCases() {
			this.loading = true
			this.error = null
			try {
				const params = {}
				if (this.filters.status && this.filters.status.length > 0) {
					params.status = this.filters.status.map((s) => s.value).join(',')
				}
				if (this.filters.procedureType) {
					params.procedureType = this.filters.procedureType.value
				}
				if (this.filters.gemeenteCode) {
					params.gemeenteCode = this.filters.gemeenteCode
				}
				if (this.filters.activiteitgroep) {
					params.activiteitgroep = this.filters.activiteitgroep
				}

				const url = generateUrl('/apps/procest/api/dso/dashboard')
				const response = await axios.get(url, { params })
				this.cases = response.data.cases || response.data || []
			} catch (err) {
				this.error = t('procest', 'Failed to load omgevingsvergunningen: {message}', {
					message: err?.response?.data?.message || err.message,
				})
			} finally {
				this.loading = false
			}
		},
		resetFilters() {
			this.filters = {
				status: [],
				procedureType: null,
				gemeenteCode: '',
				activiteitgroep: '',
				regelkwalificatie: '',
			}
			this.loadCases()
		},
		openDetail(zaak) {
			this.selectedCase = zaak
		},
		onCaseUpdated(updatedZaak) {
			const idx = this.cases.findIndex((z) => z.id === updatedZaak.id)
			if (idx !== -1) {
				this.cases.splice(idx, 1, updatedZaak)
			}
			this.selectedCase = updatedZaak
		},
		getStatusLabel(zaak) {
			const statusMap = {
				ingediend: t('procest', 'Ingediend'),
				in_behandeling: t('procest', 'In behandeling'),
				verleend: t('procest', 'Verleend'),
				geweigerd: t('procest', 'Geweigerd'),
				ingetrokken: t('procest', 'Ingetrokken'),
			}
			return statusMap[zaak.dsoStatus] || zaak.dsoStatus || '—'
		},
		getStatusSlug(zaak) {
			return (zaak.dsoStatus || 'unknown').replace(/_/g, '-')
		},
		getDeadlineClass(zaak) {
			if (!zaak.deadlineDatum) return 'vth-deadline vth-deadline--none'
			const today = new Date()
			const deadline = new Date(zaak.deadlineDatum)
			const diffDays = Math.ceil((deadline - today) / (1000 * 60 * 60 * 24))
			if (diffDays < 0) return 'vth-deadline vth-deadline--overdue'
			if (diffDays <= 10) return 'vth-deadline vth-deadline--critical'
			if (diffDays <= 20) return 'vth-deadline vth-deadline--warning'
			return 'vth-deadline vth-deadline--ok'
		},
		formatDeadline(datum) {
			if (!datum) return '—'
			return new Date(datum).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.vth-dashboard {
	padding: 20px;
}
.vth-dashboard__header h2 {
	margin-bottom: 16px;
}
.vth-dashboard__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 16px;
}
.vth-filter {
	min-width: 180px;
}
.vth-filter--small {
	min-width: 100px;
}
.vth-dashboard__loading,
.vth-dashboard__error {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 24px;
}
.vth-dashboard__table-wrapper {
	overflow-x: auto;
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
.vth-dashboard__empty {
	text-align: center;
	color: var(--color-text-lighter);
	padding: 32px !important;
}
.vth-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
}
.vth-status--ingediend { background: var(--color-info); color: #fff; }
.vth-status--in-behandeling { background: var(--color-warning); color: #fff; }
.vth-status--verleend { background: var(--color-success); color: #fff; }
.vth-status--geweigerd { background: var(--color-error); color: #fff; }
.vth-status--ingetrokken { background: var(--color-text-lighter); color: #fff; }
.vth-deadline--ok { color: var(--color-success); }
.vth-deadline--warning { color: var(--color-warning); font-weight: bold; }
.vth-deadline--critical { color: var(--color-error); font-weight: bold; }
.vth-deadline--overdue { color: var(--color-error); font-weight: bold; text-decoration: underline; }
</style>

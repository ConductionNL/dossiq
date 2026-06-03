<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="vth-dashboard">
		<div class="vth-dashboard__filters">
			<NcSelect
				v-model="filters.status"
				:label="t('procest', 'Status')"
				:options="statusOptions"
				:clearable="true"
				@update:modelValue="onFilterChange" />
			<NcSelect
				v-model="filters.procedureType"
				:label="t('procest', 'Procedure type')"
				:options="procedureOptions"
				:clearable="true"
				@update:modelValue="onFilterChange" />
			<NcTextField
				:value="filters.bevoegdGezag"
				:label="t('procest', 'Bevoegd gezag')"
				@update:value="v => { filters.bevoegdGezag = v; onFilterChange() }" />
		</div>

		<div v-if="loading" class="vth-dashboard__loading">
			<NcLoadingIcon />
		</div>

		<div v-else-if="error" class="vth-dashboard__error">
			{{ error }}
		</div>

		<div v-else class="vth-dashboard__table-wrap">
			<table class="vth-dashboard__table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Case') }}</th>
						<th>{{ t('procest', 'Procedure') }}</th>
						<th>{{ t('procest', 'Status') }}</th>
						<th>{{ t('procest', 'Bevoegd gezag') }}</th>
						<th>{{ t('procest', 'Deadline') }}</th>
						<th>{{ t('procest', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="zaak in zaken" :key="zaak.id" class="vth-dashboard__row">
						<td>{{ zaak.title }}</td>
						<td>{{ zaak.procedureType }}</td>
						<td>
							<span :class="'status-badge status-badge--' + zaak.status">
								{{ zaak.status }}
							</span>
						</td>
						<td>{{ zaak.bevoegdGezag }}</td>
						<td>
							<span :class="deadlineClass(zaak.deadlineDatum)">
								{{ zaak.deadlineDatum || '—' }}
							</span>
						</td>
						<td>
							<NcButton
								type="tertiary"
								@click="openDetail(zaak)">
								{{ t('procest', 'Open') }}
							</NcButton>
						</td>
					</tr>
					<tr v-if="zaken.length === 0">
						<td colspan="6" class="vth-dashboard__empty">
							{{ t('procest', 'No omgevingsvergunningen found.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<DsoCaseDetail
			v-if="selectedZaak"
			:zaak="selectedZaak"
			@close="selectedZaak = null"
			@updated="loadDashboard" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import DsoCaseDetail from './DsoCaseDetail.vue'

export default {
	name: 'VthDashboard',
	components: { NcButton, NcLoadingIcon, NcSelect, NcTextField, DsoCaseDetail },

	data() {
		return {
			zaken: [],
			loading: false,
			error: null,
			selectedZaak: null,
			filters: {
				status: null,
				procedureType: null,
				bevoegdGezag: '',
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
				if (this.filters.status?.value) params.status = this.filters.status.value
				if (this.filters.procedureType?.value) params.procedureType = this.filters.procedureType.value
				if (this.filters.bevoegdGezag) params.bevoegdGezag = this.filters.bevoegdGezag

				const url = generateUrl('/apps/procest/api/dso/dashboard')
				const { data } = await axios.get(url, { params })
				this.zaken = data.results || []
			} catch (err) {
				this.error = t('procest', 'Failed to load dashboard data.')
				console.error('VthDashboard: load error', err)
			} finally {
				this.loading = false
			}
		},

		onFilterChange() {
			this.loadDashboard()
		},

		openDetail(zaak) {
			this.selectedZaak = zaak
		},

		deadlineClass(deadlineDatum) {
			if (!deadlineDatum) return ''
			const today = new Date()
			const deadline = new Date(deadlineDatum)
			const diffDays = Math.ceil((deadline - today) / (1000 * 60 * 60 * 24))
			if (diffDays < 0) return 'deadline-badge deadline-badge--overdue'
			if (diffDays <= 5) return 'deadline-badge deadline-badge--critical'
			if (diffDays <= 14) return 'deadline-badge deadline-badge--warning'
			return 'deadline-badge deadline-badge--ok'
		},
	},
}
</script>

<style scoped>
.vth-dashboard__filters {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 0;
}
.vth-dashboard__table-wrap {
	overflow-x: auto;
}
.vth-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}
.vth-dashboard__table th,
.vth-dashboard__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}
.vth-dashboard__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
}
.status-badge {
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
}
.status-badge--ingediend { background: var(--color-info); color: #fff; }
.status-badge--in_behandeling { background: var(--color-warning); color: #000; }
.status-badge--verleend { background: var(--color-success); color: #fff; }
.status-badge--geweigerd { background: var(--color-error); color: #fff; }
.status-badge--ingetrokken { background: var(--color-text-maxcontrast); color: #fff; }
.deadline-badge--ok { color: var(--color-success); }
.deadline-badge--warning { color: var(--color-warning); font-weight: bold; }
.deadline-badge--critical { color: var(--color-error); font-weight: bold; }
.deadline-badge--overdue { color: var(--color-error); font-weight: bold; text-decoration: underline; }
</style>

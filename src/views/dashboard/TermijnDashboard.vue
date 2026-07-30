<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="termijn-dashboard">
		<div class="termijn-dashboard__header">
			<h2>{{ t('procest', 'Deadline monitoring') }}</h2>
			<div class="termijn-dashboard__controls">
				<NcSelect
					:model-value="selectedZaaktype"
					:options="zaaktypeOptions"
					:input-label="t('procest', 'Filter by case type')"
					:placeholder="t('procest', 'All case types')"
					@update:model-value="onZaaktypeChange" />
				<NcButton type="secondary" @click="load">
					<template #icon>
						<Refresh :size="18" />
					</template>
					{{ t('procest', 'Refresh') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="!loading && kpi" class="termijn-dashboard__grid">
			<div class="kpi-card kpi-card--neutral">
				<div class="kpi-card__label">
					{{ t('procest', 'Total cases (in period)') }}
				</div>
				<div class="kpi-card__value">
					{{ kpi.totalZaken }}
				</div>
			</div>
			<div class="kpi-card kpi-card--good">
				<div class="kpi-card__label">
					{{ t('procest', 'Within term') }}
				</div>
				<div class="kpi-card__value">
					{{ percent(kpi.withinTermijnPercent) }}
				</div>
			</div>
			<div class="kpi-card kpi-card--neutral">
				<div class="kpi-card__label">
					{{ t('procest', 'Avg duration (days)') }}
				</div>
				<div class="kpi-card__value">
					{{ kpi.avgDurationDays }}
				</div>
			</div>
			<div class="kpi-card kpi-card--warn">
				<div class="kpi-card__label">
					{{ t('procest', 'Overruns') }}
				</div>
				<div class="kpi-card__value">
					{{ kpi.overrunCount }}
				</div>
			</div>
			<div class="kpi-card kpi-card--alert">
				<div class="kpi-card__label">
					{{ t('procest', 'Dwangsom total (€)') }}
				</div>
				<div class="kpi-card__value">
					{{ euro(kpi.dwangsomTotal) }}
				</div>
			</div>
			<div class="kpi-card kpi-card--meta">
				<div class="kpi-card__label">
					{{ t('procest', 'Last updated') }}
				</div>
				<div class="kpi-card__value kpi-card__value--small">
					{{ kpi.lastUpdated || '—' }}
				</div>
			</div>
		</div>

		<div v-if="!loading" class="termijn-dashboard__section">
			<h3>{{ t('procest', 'Quarterly report') }}</h3>
			<div class="termijn-dashboard__report-controls">
				<NcTextField
					:model-value="quarter"
					:label="t('procest', 'Quarter (YYYY-Qn)')"
					:placeholder="t('procest', 'e.g. 2026-Q2')"
					@update:model-value="v => quarter = v" />
				<NcButton type="primary" @click="loadQuarterly">
					{{ t('procest', 'Load report') }}
				</NcButton>
				<NcButton :disabled="!quarterly" type="secondary" @click="downloadQuarterCsv">
					<template #icon>
						<FileExport :size="18" />
					</template>
					{{ t('procest', 'Export CSV') }}
				</NcButton>
			</div>

			<div v-if="quarterly && quarterly.perType" class="termijn-dashboard__table-wrap">
				<table class="termijn-dashboard__table">
					<thead>
						<tr>
							<th>{{ t('procest', 'Zaaktype') }}</th>
							<th>{{ t('procest', 'Total') }}</th>
							<th>{{ t('procest', 'Within deadline') }}</th>
							<th>{{ t('procest', 'Overruns') }}</th>
							<th>{{ t('procest', 'Avg. duration') }}</th>
							<th>{{ t('procest', 'Extensions') }}</th>
							<th>{{ t('procest', 'Notices of default') }}</th>
							<th>{{ t('procest', 'Total penalty payment') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, key) in quarterly.perType" :key="key">
							<td>{{ key }}</td>
							<td>{{ row.totaal || 0 }}</td>
							<td>{{ percent(row.binnenTermijnPercent || 0) }}</td>
							<td>{{ row.overschrijdingen || 0 }}</td>
							<td>{{ row.gemiddeldeDoorlooptijd || 0 }}</td>
							<td>{{ row.verlengingen || 0 }}</td>
							<td>{{ row.ingebrekestellingen || 0 }}</td>
							<td>{{ euro(row.dwangsomTotal || 0) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div v-if="!loading" class="termijn-dashboard__section">
			<h3>{{ t('procest', 'Annual dwangsom audit') }}</h3>
			<div class="termijn-dashboard__report-controls">
				<NcTextField
					:model-value="String(year)"
					:label="t('procest', 'Year')"
					@update:model-value="v => year = Number(v) || year" />
				<NcButton type="primary" @click="loadAnnual">
					{{ t('procest', 'Load audit') }}
				</NcButton>
			</div>
			<div v-if="annual" class="termijn-dashboard__summary">
				<strong>{{ t('procest', 'Total dwangsom in {y}:', { y: annual.jaar }) }}</strong>
				{{ euro((annual.summary && annual.summary.totalCents) ? annual.summary.totalCents / 100 : 0) }}
				<span class="termijn-dashboard__pill">{{ t('procest', '{n} payments', { n: annual.summary?.count || 0 }) }}</span>
				<span v-if="(annual.warnings || []).length > 0" class="termijn-dashboard__pill termijn-dashboard__pill--warn">
					{{ t('procest', '{n} data warnings', { n: annual.warnings.length }) }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FileExport from 'vue-material-design-icons/FileExport.vue'

export default {
	name: 'TermijnDashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Refresh,
		FileExport,
	},
	data() {
		return {
			loading: false,
			error: null,
			kpi: null,
			quarter: this.currentQuarter(),
			year: new Date().getFullYear(),
			quarterly: null,
			annual: null,
			zaaktypeFilter: '',
			zaaktypeOptions: [],
		}
	},
	computed: {
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md */
		selectedZaaktype() {
			if (!this.zaaktypeFilter) return null
			return this.zaaktypeOptions.find(o => o.id === this.zaaktypeFilter) || null
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md */
		currentQuarter() {
			const d = new Date()
			const q = Math.floor(d.getMonth() / 3) + 1
			return `${d.getFullYear()}-Q${q}`
		},
		/**
		 * @param v
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
		 */
		percent(v) {
			const n = Number(v) || 0
			return `${n.toFixed(1)} %`
		},
		/**
		 * @param v
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
		 */
		euro(v) {
			const n = Number(v) || 0
			return n.toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })
		},
		/**
		 * @param opt
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
		 */
		onZaaktypeChange(opt) {
			this.zaaktypeFilter = opt ? opt.id : ''
			this.load()
		},
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const params = {}
				if (this.zaaktypeFilter) params.zaaktype = this.zaaktypeFilter
				const res = await axios.get(
					generateUrl('/apps/procest/api/termijn/dashboard/kpi'),
					{ params },
				)
				this.kpi = res.data || null
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load KPI')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md */
		async loadQuarterly() {
			if (!this.quarter) return
			try {
				const res = await axios.get(
					generateUrl('/apps/procest/api/termijn/reports/kwartaal'),
					{ params: { periode: this.quarter } },
				)
				this.quarterly = res.data
				// Backfill zaaktype filter options.
				if (this.quarterly && this.quarterly.perType) {
					this.zaaktypeOptions = Object.keys(this.quarterly.perType).map(k => ({ id: k, label: k }))
				}
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load quarterly report')
			}
		},
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md */
		async loadAnnual() {
			try {
				const res = await axios.get(
					generateUrl('/apps/procest/api/termijn/reports/jaarrekening'),
					{ params: { jaar: this.year } },
				)
				this.annual = res.data
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load annual audit')
			}
		},
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md */
		downloadQuarterCsv() {
			if (!this.quarterly || !this.quarterly.perType) return
			const headers = ['zaaktype', 'totaal', 'binnenTermijnPercent', 'overschrijdingen', 'gemDoorlooptijd', 'verlengingen', 'ingebrekestellingen', 'dwangsomTotal']
			const lines = [headers.join(',')]
			for (const [k, row] of Object.entries(this.quarterly.perType)) {
				lines.push([
					k,
					row.totaal || 0,
					row.binnenTermijnPercent || 0,
					row.overschrijdingen || 0,
					row.gemiddeldeDoorlooptijd || 0,
					row.verlengingen || 0,
					row.ingebrekestellingen || 0,
					row.dwangsomTotal || 0,
				].join(','))
			}
			const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' })
			const url = window.URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `termijn-${this.quarter}.csv`
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			window.URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.termijn-dashboard {
	padding: 16px;
	max-width: 1200px;
	margin: 0 auto;
}

.termijn-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	flex-wrap: wrap;
}

.termijn-dashboard__controls {
	display: flex;
	gap: 8px;
	align-items: center;
}

.termijn-dashboard__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
	margin-bottom: 24px;
}

.kpi-card {
	padding: 16px;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.kpi-card--good { border-left: 4px solid var(--color-success); }
.kpi-card--warn { border-left: 4px solid var(--color-warning); }
.kpi-card--alert { border-left: 4px solid var(--color-error); }
.kpi-card--neutral { border-left: 4px solid var(--color-primary-element); }
.kpi-card--meta { border-left: 4px solid var(--color-border-dark); }

.kpi-card__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-bottom: 6px;
}

.kpi-card__value {
	font-size: 24px;
	font-weight: 600;
}

.kpi-card__value--small {
	font-size: 14px;
	font-weight: 400;
}

.termijn-dashboard__section {
	margin-top: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.termijn-dashboard__report-controls {
	display: flex;
	gap: 12px;
	align-items: end;
	margin-bottom: 12px;
	flex-wrap: wrap;
}

.termijn-dashboard__table-wrap {
	overflow-x: auto;
}

.termijn-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.termijn-dashboard__table th,
.termijn-dashboard__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.termijn-dashboard__table th {
	background: var(--color-background-dark);
	font-weight: 500;
}

.termijn-dashboard__summary {
	display: flex;
	align-items: center;
	gap: 12px;
}

.termijn-dashboard__pill {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.termijn-dashboard__pill--warn {
	background: var(--color-warning);
	color: var(--color-main-background);
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="iv3-report-dashboard">
		<div class="iv3-report-dashboard__header">
			<h2>{{ t('procest', 'IV3 cost report') }}</h2>
			<p class="iv3-report-dashboard__intro">
				{{ t('procest', 'Quarterly case cost breakdown per IV3 taakveld, for the CBS Informatie voor Derden submission.') }}
			</p>
		</div>

		<div class="iv3-report-dashboard__controls">
			<NcTextField
				:value="String(year)"
				:label="t('procest', 'Year')"
				@update:value="v => year = Number(v) || year" />
			<NcSelect
				:value="selectedQuarter"
				:options="quarterOptions"
				:input-label="t('procest', 'Quarter')"
				@input="v => quarter = v ? v.id : quarter" />
			<NcButton type="primary" :disabled="loading" @click="load">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('procest', 'Load report') }}
			</NcButton>
			<NcButton :disabled="!report || downloading" type="secondary" @click="downloadCsv">
				<template #icon>
					<FileExport :size="18" />
				</template>
				{{ t('procest', 'Export CSV') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="report" class="iv3-report-dashboard__table-wrap">
			<table class="iv3-report-dashboard__table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Taakveld') }}</th>
						<th>{{ t('procest', 'Cases') }}</th>
						<th>{{ t('procest', 'Total cost') }}</th>
						<th>{{ t('procest', 'Leges income') }}</th>
						<th>{{ t('procest', 'Avg. cost per case') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, code) in report.perTaakveld" :key="code">
						<td>{{ code }} — {{ row.taakveldLabel }}</td>
						<td>{{ row.caseCount }}</td>
						<td>{{ euro(row.totalCosts) }}</td>
						<td>{{ euro(row.totalLegesIncome) }}</td>
						<td>{{ euro(row.avgCostPerCase) }}</td>
					</tr>
					<tr v-if="report.uncategorized" class="iv3-report-dashboard__row--uncategorized">
						<td>{{ t('procest', 'Uncategorized') }}</td>
						<td>{{ report.uncategorized.caseCount }}</td>
						<td>{{ euro(report.uncategorized.totalCosts) }}</td>
						<td>{{ euro(report.uncategorized.totalLegesIncome) }}</td>
						<td>{{ euro(report.uncategorized.avgCostPerCase) }}</td>
					</tr>
					<tr v-if="isEmptyReport">
						<td colspan="5" class="iv3-report-dashboard__empty">
							{{ t('procest', 'No cost activity recorded for this quarter.') }}
						</td>
					</tr>
				</tbody>
			</table>
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
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import FileExport from 'vue-material-design-icons/FileExport.vue'

export default {
	name: 'Iv3ReportDashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		FileExport,
	},
	data() {
		const now = new Date()
		return {
			loading: false,
			downloading: false,
			error: null,
			report: null,
			year: now.getFullYear(),
			quarter: Math.floor(now.getMonth() / 3) + 1,
		}
	},
	computed: {
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.1 */
		quarterOptions() {
			return [1, 2, 3, 4].map(q => ({ id: q, label: t('procest', 'Q{q}', { q }) }))
		},
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.1 */
		selectedQuarter() {
			return this.quarterOptions.find(o => o.id === this.quarter) || null
		},
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.1 */
		isEmptyReport() {
			if (!this.report) return false
			return Object.keys(this.report.perTaakveld || {}).length === 0 && !this.report.uncategorized
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		/**
		 * @param {number} v The amount in EUR.
		 * @return {string} Locale-formatted currency string.
		 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.1
		 */
		euro(v) {
			const n = Number(v) || 0
			return n.toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })
		},
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.1 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const res = await axios.get(
					generateUrl('/apps/procest/api/reports/iv3'),
					{ params: { year: this.year, quarter: this.quarter, format: 'json' } },
				)
				this.report = res.data || null
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load IV3 report')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.1 */
		async downloadCsv() {
			this.downloading = true
			try {
				const url = generateUrl('/apps/procest/api/reports/iv3')
				const { data } = await axios.get(url, {
					params: { year: this.year, quarter: this.quarter, format: 'csv' },
					responseType: 'blob',
				})
				const objectUrl = window.URL.createObjectURL(data)
				const link = document.createElement('a')
				link.href = objectUrl
				link.download = `iv3-report-${this.year}-Q${this.quarter}.csv`
				link.click()
				window.URL.revokeObjectURL(objectUrl)
			} catch (e) {
				showError(t('procest', 'CSV export failed'))
			} finally {
				this.downloading = false
			}
		},
	},
}
</script>

<style scoped>
.iv3-report-dashboard {
	padding: 16px;
	max-width: 1200px;
	margin: 0 auto;
}

.iv3-report-dashboard__header {
	margin-bottom: 16px;
}

.iv3-report-dashboard__intro {
	color: var(--color-text-maxcontrast);
}

.iv3-report-dashboard__controls {
	display: flex;
	gap: 12px;
	align-items: end;
	margin-bottom: 20px;
	flex-wrap: wrap;
}

.iv3-report-dashboard__table-wrap {
	overflow-x: auto;
}

.iv3-report-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.iv3-report-dashboard__table th,
.iv3-report-dashboard__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.iv3-report-dashboard__table th {
	background: var(--color-background-dark);
	font-weight: 500;
}

.iv3-report-dashboard__row--uncategorized {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.iv3-report-dashboard__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Per-case-type doorlooptijd breakdown — a sortable table of average actual
	processing time, SLA target, compliance rate, and case count per case type,
	with a traffic-light status dot. Extracted from the monolithic
	DoorlooptijdDashboard.vue. Owns its own column-sort state (the sort logic
	is locked by Vitest). Behaviour is identical to the inline markup it
	replaces.

	Spec: openspec/specs/doorlooptijd-dashboard/spec.md
-->
<template>
	<div class="performance-table-card">
		<h3>{{ t('procest', 'Performance by Case Type') }}</h3>
		<table class="performance-table">
			<thead>
				<tr>
					<th class="sortable" scope="col" @click="sortTable('name')">
						{{ t('procest', 'Case Type') }}
						<span v-if="sortColumn === 'name'" class="sort-indicator">
							{{ sortDirection === 'asc' ? '▲' : '▼' }}
						</span>
					</th>
					<th
						class="sortable numeric"
						scope="col"
						@click="sortTable('targetDays')">
						{{ t('procest', 'Target (days)') }}
						<span
							v-if="sortColumn === 'targetDays'"
							class="sort-indicator">
							{{ sortDirection === 'asc' ? '▲' : '▼' }}
						</span>
					</th>
					<th
						class="sortable numeric"
						scope="col"
						@click="sortTable('avgActualDays')">
						{{ t('procest', 'Avg Actual (days)') }}
						<span
							v-if="sortColumn === 'avgActualDays'"
							class="sort-indicator">
							{{ sortDirection === 'asc' ? '▲' : '▼' }}
						</span>
					</th>
					<th
						class="sortable numeric"
						scope="col"
						@click="sortTable('complianceRate')">
						{{ t('procest', 'Compliance %') }}
						<span
							v-if="sortColumn === 'complianceRate'"
							class="sort-indicator">
							{{ sortDirection === 'asc' ? '▲' : '▼' }}
						</span>
					</th>
					<th
						class="sortable numeric"
						scope="col"
						@click="sortTable('total')">
						{{ t('procest', 'Cases') }}
						<span v-if="sortColumn === 'total'" class="sort-indicator">
							{{ sortDirection === 'asc' ? '▲' : '▼' }}
						</span>
					</th>
					<th scope="col">{{ t('procest', 'Status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in sortedPerformanceData" :key="row.id">
					<td>{{ row.name }}</td>
					<td class="numeric">
						{{ row.targetDays !== null ? row.targetDays : '—' }}
					</td>
					<td class="numeric">
						{{ row.avgActualDays !== null ? row.avgActualDays : '—' }}
					</td>
					<td class="numeric">
						{{
							row.complianceRate !== null
								? row.complianceRate + '%'
								: '—'
						}}
					</td>
					<td class="numeric">
						{{ row.total }}
					</td>
					<td>
						<span
							class="status-dot"
							:class="'status-dot--' + row.status" />
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { sortPerformanceRows } from './chartShaping.js'

export default {
	name: 'CaseTypeBreakdown',
	props: {
		/**
		 * Per-case-type rows as produced by computePerformanceTable(): each row
		 * has { id, name, targetDays, avgActualDays, complianceRate, total, status }.
		 */
		performanceData: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			sortColumn: 'complianceRate',
			sortDirection: 'asc',
		}
	},

	computed: {
		/**
		 * Performance rows sorted by the active column/direction. Null values
		 * always sort last; strings use locale compare, numbers numeric compare.
		 *
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		sortedPerformanceData() {
			return sortPerformanceRows(
				this.performanceData,
				this.sortColumn,
				this.sortDirection,
			)
		},
	},

	methods: {
		/**
		 * Toggle sort direction when the active column is re-clicked; otherwise
		 * switch to the new column ascending.
		 *
		 * @param {string} column - The column key to sort by.
		 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
		 */
		sortTable(column) {
			if (this.sortColumn === column) {
				this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortColumn = column
				this.sortDirection = 'asc'
			}
		},
	},
}
</script>

<style scoped>
.performance-table-card {
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.performance-table-card h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.performance-table {
	width: 100%;
	border-collapse: collapse;
}

.performance-table th,
.performance-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.performance-table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.performance-table th.sortable {
	cursor: pointer;
	user-select: none;
}

.performance-table th.sortable:hover {
	color: var(--color-main-text);
}

.performance-table .numeric {
	text-align: right;
}

.sort-indicator {
	margin-left: 4px;
	font-size: 10px;
}

.status-dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}

.status-dot--good {
	background: var(--color-success);
}

.status-dot--warning {
	background: var(--color-warning);
}

.status-dot--critical {
	background: var(--color-error);
}

.status-dot--no-target {
	background: var(--color-text-maxcontrast);
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Bottleneck ranking table.

	A plain table, not an nc-vue list leaf: the rows are an ad-hoc computed shape
	(case type x status x score) with no register schema behind them, so no
	object-list leaf applies. Same reasoning the deadline report tables use.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="pm-bottleneck-widget">
		<p class="pm-bottleneck-widget__hint">
			{{
				t(
					'procest',
					'Ranked by median dwell time × case volume — the statuses most worth investigating first.',
				)
			}}
		</p>
		<NcLoadingIcon v-if="pmLoading" :size="24" />
		<div v-else-if="rows.length > 0" class="pm-bottleneck-widget__scroll">
			<table class="pm-bottleneck-widget__table">
				<thead>
					<tr>
						<th scope="col">{{ t('procest', 'Case type') }}</th>
						<th scope="col">{{ t('procest', 'Status') }}</th>
						<th scope="col">{{ t('procest', 'Median hours') }}</th>
						<th scope="col">{{ t('procest', 'Visits') }}</th>
						<th scope="col">{{ t('procest', 'Score') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, idx) in rows" :key="idx">
						<td>{{ row.caseTypeTitle }}</td>
						<td>{{ row.statusName }}</td>
						<td>{{ row.medianHours }}</td>
						<td>{{ row.visitCount }}</td>
						<td>{{ row.score }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="pm-bottleneck-widget__empty">
			{{ t('procest', 'No bottleneck data for the selected period.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { pmWidgetMixin } from './pmWidgetMixin.js'
import { buildBottleneckRows } from './processMiningShaping.js'

export default {
	name: 'PmBottleneckTableWidget',
	components: { NcLoadingIcon },
	mixins: [pmWidgetMixin],
	computed: {
		/**
		 * @return {Array<object>} Top-10 bottleneck rows across case types.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		rows() {
			return buildBottleneckRows(this.pmCaseTypes, 10)
		},
	},

	methods: { t },
}
</script>

<style scoped>
.pm-bottleneck-widget__hint,
.pm-bottleneck-widget__empty {
	color: var(--color-text-maxcontrast);
}

/* Wide tables scroll inside the widget rather than widening the grid. */
.pm-bottleneck-widget__scroll {
	overflow-x: auto;
}

.pm-bottleneck-widget__table {
	width: 100%;
	border-collapse: collapse;
}

.pm-bottleneck-widget__table th,
.pm-bottleneck-widget__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>

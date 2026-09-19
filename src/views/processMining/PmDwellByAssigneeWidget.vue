<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Dwell time by the person who held it.

	THE SAME INTERVALS AS THE PHASE TABLE, KEYED DIFFERENTLY. The server groups
	one reconstruction two ways, so this table and the one beside it can never
	disagree about the same case. Nothing here recomputes anything.

	A plain table, not an nc-vue list leaf: the rows are an ad-hoc computed
	shape with no register schema behind them, the same reasoning the
	bottleneck table records.

	WHY A SWITCH AND NOT A SECOND PAGE. The clock and the grouping are two ways
	of reading one report, and ADR-112 says a report is one page: a second page
	would be a second set of filters somebody has to keep in step.

	@spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
-->
<template>
	<div class="pm-assignee-widget">
		<p class="pm-assignee-widget__hint">
			{{
				t(
					'dossiq',
					'The same time the phase table counts, grouped by who held the case. Time no status record attributed is listed as not recorded.',
				)
			}}
		</p>
		<NcLoadingIcon v-if="pmLoading" :size="24" />
		<div v-else-if="rows.length > 0" class="pm-assignee-widget__scroll">
			<table
				class="pm-assignee-widget__table"
				data-testid="pm-dwell-by-assignee">
				<thead>
					<tr>
						<th scope="col">{{ t('dossiq', 'Handler') }}</th>
						<th scope="col">{{ headlineLabel }}</th>
						<th scope="col">{{ wallLabel }}</th>
						<th scope="col">{{ t('dossiq', 'Visits') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, idx) in rows" :key="idx">
						<td>{{ row.actorLabel }}</td>
						<td>{{ row.workingHours }}</td>
						<td>{{ row.wallHours }}</td>
						<td>{{ row.visitCount }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="pm-assignee-widget__empty">
			{{ t('dossiq', 'No dwell time by handler for the selected period.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { pmWidgetMixin } from './pmWidgetMixin.js'
import {
	buildAssigneeRows,
	wallHoursLabel,
	workingHoursLabel,
} from './processMiningShaping.js'

export default {
	name: 'PmDwellByAssigneeWidget',
	components: { NcLoadingIcon },
	mixins: [pmWidgetMixin],
	computed: {
		/**
		 * @return {Array<object>} One row per handler, longest first.
		 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
		 */
		rows() {
			return buildAssigneeRows(this.pmPrimaryCaseType?.dwellByAssignee, (s) =>
				t('dossiq', s),
			)
		},

		/**
		 * The headline duration column's title, naming its clock.
		 *
		 * @return {string} The title.
		 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
		 */
		headlineLabel() {
			return workingHoursLabel(this.pmStore.clock, (s) => t('dossiq', s))
		},

		/**
		 * @return {string} The second column's title, always the wall clock.
		 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
		 */
		wallLabel() {
			return wallHoursLabel((s) => t('dossiq', s))
		},
	},

	methods: { t },
}
</script>

<style scoped>
.pm-assignee-widget__hint,
.pm-assignee-widget__empty {
	color: var(--color-text-maxcontrast);
}

/* Wide tables scroll inside the widget rather than widening the grid. */
.pm-assignee-widget__scroll {
	overflow-x: auto;
}

.pm-assignee-widget__table {
	width: 100%;
	border-collapse: collapse;
}

.pm-assignee-widget__table th,
.pm-assignee-widget__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>

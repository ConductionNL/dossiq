<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Weekly throughput line chart. Title comes from the widget frame.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="pm-chart-widget">
		<NcLoadingIcon v-if="pmLoading" :size="24" />
		<div v-else-if="series.length > 0" class="pm-chart-widget__container">
			<CnChartWidget
				type="line"
				:height="280"
				:series="series"
				:categories="categories"
				:options="options" />
		</div>
		<p v-else class="pm-chart-widget__empty">
			{{ t('procest', 'No completed cases in the selected range') }}
		</p>
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { pmWidgetMixin } from './pmWidgetMixin.js'
import { buildThroughputCategories, buildThroughputSeries } from './processMiningShaping.js'

export default {
	name: 'PmThroughputChartWidget',
	components: { CnChartWidget, NcLoadingIcon },
	mixins: [pmWidgetMixin],
	computed: {
		/** @return {Array} Line series of cases closed per week. */
		series() {
			return buildThroughputSeries(this.pmStore.throughputTrend, t('procest', 'Cases closed'))
		},

		/** @return {Array<string>} Week labels along the x-axis. */
		categories() {
			return buildThroughputCategories(this.pmStore.throughputTrend)
		},

		/** @return {object} ApexCharts options. */
		options() {
			return {
				yaxis: {
					min: 0,
					forceNiceScale: true,
					labels: { formatter: (val) => Math.round(val) },
				},

				colors: ['var(--color-primary)'],
				stroke: { curve: 'smooth', width: 3 },
				markers: { size: 4 },
			}
		},
	},

	methods: { t },
}
</script>

<style scoped>
.pm-chart-widget__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: var(--default-grid-baseline, 4px) 0;
}
</style>

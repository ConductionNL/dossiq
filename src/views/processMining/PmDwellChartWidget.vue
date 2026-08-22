<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Dwell-time-by-status bar chart. The widget frame supplies the title; this
	component renders only the chart, per the one-heading-per-page rule.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="pm-chart-widget">
		<NcLoadingIcon v-if="pmLoading" :size="24" />
		<div v-else-if="series.length > 0" class="pm-chart-widget__container">
			<CnChartWidget
				type="bar"
				:height="280"
				:series="series"
				:categories="categories"
				:options="options" />
		</div>
		<p v-else class="pm-chart-widget__empty">
			{{ t('procest', 'No dwell-time data available') }}
		</p>
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { pmWidgetMixin } from './pmWidgetMixin.js'
import { buildDwellCategories, buildDwellSeries } from './processMiningShaping.js'

export default {
	name: 'PmDwellChartWidget',
	components: { CnChartWidget, NcLoadingIcon },
	mixins: [pmWidgetMixin],
	computed: {
		/** @return {Array} Bar series for the scoped case type. */
		series() {
			return buildDwellSeries(this.pmPrimaryCaseType?.dwellTime, t('procest', 'Median hours'))
		},

		/** @return {Array<string>} Status names along the x-axis. */
		categories() {
			return buildDwellCategories(this.pmPrimaryCaseType?.dwellTime)
		},

		/** @return {object} ApexCharts options. */
		options() {
			return {
				plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
				xaxis: { title: { text: t('procest', 'Status') } },
				yaxis: { title: { text: t('procest', 'Median hours') } },
				colors: ['var(--color-warning)'],
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

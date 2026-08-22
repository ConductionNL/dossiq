<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Compliance charts (donut / histogram / trend / throughput). Thin wrapper —
	ComplianceCharts.vue is unchanged; only its host moved.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="dt-charts-widget">
		<NcLoadingIcon v-if="dtLoading" :size="24" />
		<ComplianceCharts
			v-else
			:slaData="slaData"
			:distributionData="distributionData"
			:trendData="trendData"
			:throughputData="throughputData" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ComplianceCharts from '../components/ComplianceCharts.vue'
import { dtWidgetMixin } from './dtWidgetMixin.js'

export default {
	name: 'DtChartsWidget',
	components: { ComplianceCharts, NcLoadingIcon },
	mixins: [dtWidgetMixin],
	computed: {
		/** @return {object} SLA compliance block. */
		slaData() { return this.dtStore.slaData(this.dtPreset, this.dtCaseType) },
		/** @return {object} Processing-time distribution block. */
		distributionData() { return this.dtStore.distributionData(this.dtPreset, this.dtCaseType) },
		/** @return {object} Monthly trend block. */
		trendData() { return this.dtStore.trendData(this.dtPreset, this.dtCaseType) },
		/** @return {object} Weekly throughput block. */
		throughputData() { return this.dtStore.throughputData(this.dtPreset, this.dtCaseType) },
	},
}
</script>

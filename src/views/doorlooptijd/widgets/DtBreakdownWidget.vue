<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Per-case-type performance breakdown. Thin wrapper — CaseTypeBreakdown.vue
	unchanged.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="dt-breakdown-widget">
		<NcLoadingIcon v-if="dtLoading" :size="24" />
		<CaseTypeBreakdown v-else :performanceData="performanceData" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CaseTypeBreakdown from '../components/CaseTypeBreakdown.vue'
import { dtWidgetMixin } from './dtWidgetMixin.js'

export default {
	name: 'DtBreakdownWidget',
	components: { CaseTypeBreakdown, NcLoadingIcon },
	mixins: [dtWidgetMixin],
	computed: {
		/** @return {Array<object>} Per-case-type performance rows. */
		performanceData() {
			return this.dtStore.performanceData(this.dtPreset, this.dtCaseType)
		},
	},
}
</script>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	At-risk cases table. Thin wrapper — DeadlineCaseTable.vue unchanged.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="dt-atrisk-widget">
		<NcLoadingIcon v-if="dtLoading" :size="24" />
		<DeadlineCaseTable v-else :cases="atRisk" @selectCase="openCase" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DeadlineCaseTable from '../components/DeadlineCaseTable.vue'
import { dtWidgetMixin } from './dtWidgetMixin.js'

export default {
	name: 'DtAtRiskWidget',
	components: { DeadlineCaseTable, NcLoadingIcon },
	mixins: [dtWidgetMixin],
	computed: {
		/** @return {Array<object>} Open cases within 25% of their deadline. */
		atRisk() {
			return this.dtStore.atRiskCases(this.dtCaseType)
		},
	},
}
</script>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Headline KPI tiles for the process-mining dashboard.

	Renders no page heading: the heading belongs to the dashboard page, and a
	widget that draws its own is the dashboard-in-dashboard antipattern this
	change exists to remove (hydra#316).

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="pm-kpi-widget">
		<NcLoadingIcon v-if="pmLoading" :size="24" />
		<template v-else>
			<CnKpiGrid :columns="4">
				<CnStatsBlock
					:title="t('dossiq', 'Cases analysed')"
					:count="kpiSummary.totalCases"
					:showZeroCount="true"
					:countLabel="t('dossiq', 'cases')"
					variant="primary" />
				<CnStatsBlock
					:title="t('dossiq', 'Case types')"
					:count="kpiSummary.caseTypeCount"
					:showZeroCount="true"
					:countLabel="t('dossiq', 'case types')"
					variant="default" />
				<CnStatsBlock
					:title="t('dossiq', 'Overall rework rate')"
					countLabel=""
					variant="warning">
					<template #value>
						{{ kpiSummary.overallReworkPercent }}%
					</template>
				</CnStatsBlock>
				<CnStatsBlock
					:title="t('dossiq', 'Top bottleneck')"
					countLabel=""
					variant="error">
					<template #value>
						{{ topBottleneckLabel }}
					</template>
				</CnStatsBlock>
			</CnKpiGrid>

			<NcNoteCard v-if="kpiSummary.overallReworkPercent >= 20" type="warning">
				{{
					t(
						'procest',
						'{percent}% of recorded transitions revisit a status the case had already left — a high rework rate usually means guard conditions or handler routing need a closer look.',
						{ percent: kpiSummary.overallReworkPercent },
					)
				}}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { pmWidgetMixin } from './pmWidgetMixin.js'
import { buildKpiSummary } from './processMiningShaping.js'

export default {
	name: 'PmKpiWidget',
	components: { CnKpiGrid, CnStatsBlock, NcLoadingIcon, NcNoteCard },
	mixins: [pmWidgetMixin],
	computed: {
		/**
		 * @return {object} Headline figures for the current report.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		kpiSummary() {
			return buildKpiSummary(this.pmStore.report)
		},

		/**
		 * @return {string} "Status (Nh)" for the worst bottleneck, or an em dash.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		topBottleneckLabel() {
			const top = this.kpiSummary.topBottleneck
			if (!top) return '—'
			return `${top.statusName} (${top.medianHours}h)`
		},
	},

	methods: { t },
}
</script>

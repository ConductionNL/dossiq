<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Case-type filter for the deadline-monitoring dashboard header.

	Same reason as the process-mining one: `config.pageFilters` only accepts a
	static options array, and these options come from the API.

	Fixes a pre-existing bug. The dashboard this replaced populated its
	`zaaktypeOptions` ONLY inside `loadQuarterly()`, which it never called on
	mount — so the filter rendered and stayed empty until a user happened to load
	a quarterly report. `ensureZaaktypeOptions()` loads the current quarter once
	so the filter has options on first paint.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<NcSelect
		class="td-case-type-filter"
		:modelValue="selected"
		:options="options"
		:inputLabel="t('dossiq', 'Filter by case type')"
		:placeholder="t('dossiq', 'All case types')"
		@update:modelValue="onChange" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useTermijnDashboardStore } from '../../store/modules/termijnDashboard.js'

export default {
	name: 'TdCaseTypeFilter',
	components: { NcSelect },

	inject: {
		workspace: { from: 'cnWorkspaceContext', default: () => ({}) },
	},

	computed: {
		/**
		 * @return {object} The shared deadline-monitoring store.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		tdStore() {
			return useTermijnDashboardStore()
		},

		/**
		 * @return {Array<object>} Selectable case types.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		options() {
			return this.tdStore.zaaktypeOptions
		},

		/**
		 * @return {object|null} The option matching the context, or null.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		selected() {
			const current = this.workspace?.caseType
			return this.options.find((o) => o.id === current) || null
		},
	},

	mounted() {
		this.tdStore.ensureZaaktypeOptions()
	},

	methods: {
		t,
		/**
		 * Write the selection into the reactive workspace context.
		 *
		 * @param {object|null} opt The chosen option, or null to clear.
		 * @return {void}
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		onChange(opt) {
			if (this.workspace) {
				this.workspace.caseType = opt ? opt.id : null
			}
		},
	},
}
</script>

<style scoped>
.td-case-type-filter {
	min-width: 220px;
}
</style>

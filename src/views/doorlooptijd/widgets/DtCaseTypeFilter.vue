<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Case-type filter for the processing-time dashboard header.

	Only case types that actually declare a processing deadline are offered:
	filtering to a type with no SLA yields a dashboard that can say nothing.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<NcSelect
		class="dt-case-type-filter"
		:modelValue="selected"
		:options="options"
		:inputLabel="t('procest', 'Filter by case type')"
		:placeholder="t('procest', 'All case types')"
		@update:modelValue="onChange" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useDoorlooptijdStore } from '../../../store/modules/doorlooptijd.js'

export default {
	name: 'DtCaseTypeFilter',
	components: { NcSelect },

	inject: {
		workspace: { from: 'cnWorkspaceContext', default: () => ({}) },
	},

	computed: {
		/** @return {object} The shared processing-time store. */
		dtStore() { return useDoorlooptijdStore() },
		/** @return {Array<object>} Case types that declare an SLA. */
		options() {
			return this.dtStore.caseTypesWithSla.map((ct) => ({ id: ct.id, label: ct.title || ct.name }))
		},

		/** @return {object|null} The option matching the context, or null. */
		selected() {
			const cur = this.workspace?.caseType
			return this.options.find((o) => o.id === cur) || null
		},
	},

	mounted() {
		this.dtStore.load()
	},

	methods: {
		t,
		/**
		 * Write the selection into the reactive workspace context.
		 *
		 * @param {object|null} opt The chosen option, or null to clear.
		 * @return {void}
		 */
		onChange(opt) {
			if (this.workspace) this.workspace.caseType = opt ? opt.id : null
		},
	},
}
</script>

<style scoped>
.dt-case-type-filter { min-width: 220px; }
</style>

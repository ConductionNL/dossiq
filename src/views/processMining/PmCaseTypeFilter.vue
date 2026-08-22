<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Case-type filter for the process-mining dashboard header.

	The period selector is a declarative `config.pageFilters` entry, because its
	options are a fixed list. Case types are not: they are register objects, and
	`pageFilters` only accepts a static `options` array. So this one control is a
	`header-actions` slot component that writes the same reactive workspace
	context a page filter would — every widget still reads `@workspace.caseType`
	and neither knows nor cares which mechanism set it.

	The gap is in the vocabulary, not here: `pageFilters` needs an options source
	bound to a register/schema. Recorded against nc-vue rather than worked around
	by giving each widget its own filter.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<NcSelect
		class="pm-case-type-filter"
		:modelValue="selected"
		:options="options"
		:inputLabel="t('procest', 'Filter by case type')"
		:placeholder="t('procest', 'All case types')"
		@update:modelValue="onChange" />
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { translate as t } from '@nextcloud/l10n'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'PmCaseTypeFilter',
	components: { NcSelect },

	inject: {
		workspace: { from: 'cnWorkspaceContext', default: () => ({}) },
	},

	data() {
		return { caseTypes: [] }
	},

	computed: {
		/** @return {object} The shared object store. */
		objectStore() {
			return useObjectStore()
		},
		/** @return {Array<object>} Selectable case types. */
		options() {
			return this.caseTypes.map((ct) => ({ id: ct.id, label: ct.title || ct.name }))
		},
		/** @return {object|null} The option matching the context, or null. */
		selected() {
			const current = this.workspace?.caseType
			return this.options.find((o) => o.id === current) || null
		},
	},

	async mounted() {
		try {
			// `caseType` is only registered on the object store once
			// initializeStores() has resolved the app config, and a widget can
			// mount before App.vue's boot call finishes. The dashboard this
			// replaced skipped this await and swallowed the resulting
			// "Object type \"caseType\" is not registered" error, so its
			// case-type filter was silently empty on every load — the filter
			// looked present and did nothing. initializeStores() is idempotent.
			await initializeStores()
			this.caseTypes = (await this.objectStore.fetchCollection('caseType', { _limit: 100 })) || []
		} catch (err) {
			// Still non-fatal after the await: an unreachable config leaves the
			// filter empty, which is the same result as choosing no filter.
			this.caseTypes = []
		}
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
			if (this.workspace) {
				this.workspace.caseType = opt ? opt.id : null
			}
		},
	},
}
</script>

<style scoped>
.pm-case-type-filter {
	min-width: 220px;
}
</style>

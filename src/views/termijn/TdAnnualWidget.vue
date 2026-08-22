<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Annual dwangsom audit summary. Year input is widget-scoped; the `<h3>` comes
	from the widget frame.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="td-annual-widget">
		<div class="td-annual-widget__controls">
			<NcTextField
				:modelValue="String(year)"
				:label="t('procest', 'Year')"
				@update:modelValue="onYear" />
			<NcButton variant="primary" @click="load">
				{{ t('procest', 'Load audit') }}
			</NcButton>
		</div>
		<div v-if="annual" class="td-annual-widget__summary">
			<strong>{{ t('procest', 'Total dwangsom in {y}:', { y: annual.jaar }) }}</strong>
			{{ euro(totalEuros) }}
			<span class="td-annual-widget__pill">
				{{ t('procest', '{n} payments', { n: annual.summary?.count || 0 }) }}
			</span>
			<span
				v-if="(annual.warnings || []).length > 0"
				class="td-annual-widget__pill td-annual-widget__pill--warn">
				{{ t('procest', '{n} data warnings', { n: annual.warnings.length }) }}
			</span>
		</div>
		<p v-else class="td-annual-widget__empty">
			{{ t('procest', 'Choose a year and load the audit.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { tdWidgetMixin } from './tdWidgetMixin.js'

export default {
	name: 'TdAnnualWidget',
	components: { NcButton, NcTextField },
	mixins: [tdWidgetMixin],
	data() {
		return { year: new Date().getFullYear() }
	},

	computed: {
		/** @return {object|null} The loaded annual audit. */
		annual() {
			return this.tdStore.annual
		},

		/** @return {number} Total dwangsom in euros (the API reports cents). */
		totalEuros() {
			const cents = this.annual?.summary?.totalCents
			return cents ? cents / 100 : 0
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		/**
		 * Keep the year numeric; ignore input that is not a number.
		 *
		 * @param {string} v Raw field value.
		 * @return {void}
		 */
		onYear(v) {
			this.year = Number(v) || this.year
		},

		/** Load the audit for the entered year. */
		load() {
			this.tdStore.loadAnnual(this.year)
		},
	},
}
</script>

<style scoped>
.td-annual-widget__controls {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.td-annual-widget__pill {
	display: inline-block;
	margin-left: 8px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 100px);
	background: var(--color-background-dark);
}

.td-annual-widget__pill--warn {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.td-annual-widget__empty {
	color: var(--color-text-maxcontrast);
}
</style>

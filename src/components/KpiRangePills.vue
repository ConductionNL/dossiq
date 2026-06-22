<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div
		class="kpi-range-pills"
		role="group"
		:aria-label="t('procest', 'Date range')">
		<button
			v-for="opt in options"
			:key="opt.id"
			type="button"
			class="kpi-range-pills__pill"
			:class="{ 'kpi-range-pills__pill--active': opt.id === value }"
			:aria-pressed="opt.id === value ? 'true' : 'false'"
			@click.stop.prevent="$emit('input', opt.id)">
			{{ opt.label }}
		</button>
	</div>
</template>

<script>
import { rangeOptions } from '../utils/dateRange.js'

export default {
	name: 'KpiRangePills',
	props: {
		/** Currently selected range id (week|month|quarter|year|all). */
		value: { type: String, default: 'all' },
		/** Pill options; defaults to the standard reporting presets. */
		options: { type: Array, default: () => rangeOptions() },
	},
	emits: ['input'],
}
</script>

<style scoped>
.kpi-range-pills {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 6px;
}

.kpi-range-pills__pill {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	border-radius: var(--border-radius-pill, 16px);
	padding: 1px 8px;
	font-size: 11px;
	line-height: 18px;
	cursor: pointer;
	min-height: 0;
	min-width: 0;
}

.kpi-range-pills__pill:hover {
	border-color: var(--color-primary-element);
}

.kpi-range-pills__pill--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 600;
}

.kpi-range-pills__pill:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}
</style>

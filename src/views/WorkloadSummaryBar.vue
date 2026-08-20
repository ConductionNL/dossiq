<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div v-if="handlers.length > 0" class="workload-summary">
		<span class="workload-summary__title">{{
			t('procest', 'Team workload')
		}}</span>
		<ul class="workload-summary__list">
			<li
				v-for="row in handlers"
				:key="row.handler"
				class="workload-summary__row">
				<span class="workload-summary__handler">{{ row.handler }}</span>
				<span class="workload-summary__bar-track">
					<span
						class="workload-summary__bar-fill"
						:style="{ width: barWidth(row.openCaseCount) + '%' }" />
				</span>
				<span class="workload-summary__count">{{ row.openCaseCount }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
/**
 * Compact per-handler open-case workload bar for coordinators, rendered on
 * My Work only when GET /api/work-queue/workload succeeds (the caller is a
 * coordinator). Renders nothing when there is no data — the parent silently
 * swallows a 403 for non-coordinators, so this component never needs to
 * distinguish "no access" from "no data".
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export default {
	name: 'WorkloadSummaryBar',

	props: {
		/** Per-handler open-case counts, e.g. [{ handler, openCaseCount }]. */
		handlers: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		/** The highest open-case count across all handlers, used to scale bars. */
		maxCount() {
			return this.handlers.reduce(
				(max, row) => Math.max(max, row.openCaseCount || 0),
				0,
			)
		},
	},

	methods: {
		/**
		 * Compute a handler's bar width as a percentage of the busiest handler.
		 *
		 * @param {number} count The handler's open-case count.
		 * @return {number} A percentage (0–100).
		 */
		barWidth(count) {
			if (this.maxCount <= 0) {
				return 0
			}
			return Math.round((count / this.maxCount) * 100)
		},
	},
}
</script>

<style scoped lang="scss">
.workload-summary {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px 16px;
	margin-bottom: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
}

.workload-summary__title {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.workload-summary__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.workload-summary__row {
	display: grid;
	grid-template-columns: 8rem 1fr 2rem;
	align-items: center;
	gap: 8px;
}

.workload-summary__handler {
	overflow: hidden;
	font-size: 0.85rem;
	color: var(--color-main-text);
	text-overflow: ellipsis;
	white-space: nowrap;
}

.workload-summary__bar-track {
	display: block;
	height: 8px;
	background: var(--color-background-darker);
	border-radius: var(--border-radius-pill, 16px);
	overflow: hidden;
}

.workload-summary__bar-fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
	border-radius: var(--border-radius-pill, 16px);
}

.workload-summary__count {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-align: end;
}
</style>

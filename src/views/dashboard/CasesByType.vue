<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<!--
	Cases by Type widget — horizontal bar chart of open cases grouped by
	caseType, sorted by count descending. Mirrors the StatusChart pattern
	for consistency. Clicking a bar navigates to /cases pre-filtered by
	caseType so the user lands on the matching index view.

	Spec: openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-003
-->
<template>
	<div class="type-chart">
		<h3 class="type-chart__title">
			{{ t('procest', 'Cases by Type') }}
		</h3>

		<template v-if="loading">
			<div v-for="i in 4" :key="i" class="type-chart__skeleton">
				<div class="skeleton-bar" />
			</div>
		</template>

		<template v-else-if="error">
			<div class="type-chart__error">
				<p>{{ error }}</p>
				<NcButton type="tertiary" @click="$emit('retry')">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="typeData.length === 0">
			<p class="type-chart__empty">
				{{ t('procest', 'No open cases') }}
			</p>
		</template>

		<template v-else>
			<button
				v-for="(item, index) in typeData"
				:key="item.type"
				class="type-chart__row"
				:title="t('procest', 'Filter cases by type: {type}', { type: item.type || t('procest', 'Unknown') })"
				@click="onBarClick(item)">
				<span class="type-chart__label">
					{{ item.type || t('procest', 'Unknown') }}
				</span>
				<div class="type-chart__bar-container">
					<div
						class="type-chart__bar"
						:style="{ width: barWidth(item.count), backgroundColor: barColor(index) }" />
				</div>
				<span class="type-chart__count">{{ item.count }}</span>
			</button>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

const BAR_COLORS = [
	'var(--color-primary)',
	'var(--color-primary-element-light)',
	'var(--color-success)',
	'var(--color-warning)',
	'var(--color-error)',
	'var(--color-text-maxcontrast)',
]

export default {
	name: 'CasesByType',
	components: {
		NcButton,
	},
	props: {
		/**
		 * Array of `{ type: string, count: number }` rows pre-sorted by count
		 * descending. Defaults to an empty array so the widget renders an
		 * empty state during initial load.
		 */
		typeData: { type: Array, default: () => [] },
		/** Loading flag — renders skeleton bars while true. */
		loading: { type: Boolean, default: false },
		/** Error message string — renders the retry CTA when set. */
		error: { type: String, default: null },
	},
	emits: ['retry', 'bar-click'],
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md */
		maxCount() {
			return Math.max(1, ...this.typeData.map(t => t.count))
		},
	},
	methods: {
		/**
		 * @param count
		 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
		 */
		barWidth(count) {
			const pct = (count / this.maxCount) * 100
			return `max(20px, ${pct}%)`
		},
		/**
		 * @param index
		 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
		 */
		barColor(index) {
			return BAR_COLORS[index % BAR_COLORS.length]
		},
		/**
		 * @param item
		 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
		 */
		onBarClick(item) {
			// Default behaviour: navigate to /cases filtered by caseType.
			// Consumers can override by listening for `@bar-click` and
			// calling `event.preventDefault()` on the payload (manual flag
			// pattern — kept simple, no event object).
			this.$emit('bar-click', item)
			if (this.$router && item && item.type) {
				this.$router.push({
					name: 'Cases',
					query: { caseType: item.type },
				}).catch(() => {
					// Ignore NavigationDuplicated / cancelled errors.
				})
			}
		},
	},
}
</script>

<style scoped>
.type-chart {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
}

.type-chart__title {
	font-size: 15px;
	margin: 0 0 12px;
	flex-shrink: 0;
}

.type-chart__row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
	width: 100%;
	background: transparent;
	border: none;
	padding: 0;
	cursor: pointer;
	text-align: left;
	font: inherit;
	color: inherit;
}

.type-chart__row:hover .type-chart__bar,
.type-chart__row:focus-visible .type-chart__bar {
	filter: brightness(1.1);
}

.type-chart__row:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: var(--border-radius);
}

.type-chart__label {
	flex: 0 0 140px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	text-align: right;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.type-chart__bar-container {
	flex: 1;
	height: 24px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.type-chart__bar {
	height: 100%;
	border-radius: var(--border-radius);
	transition: width 0.3s ease, filter 0.15s ease;
}

.type-chart__count {
	flex: 0 0 32px;
	font-size: 13px;
	font-weight: 600;
	text-align: right;
}

.type-chart__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px 0;
}

.type-chart__error {
	text-align: center;
	padding: 12px;
	color: var(--color-error);
}

.type-chart__skeleton {
	margin-bottom: 8px;
}

.skeleton-bar {
	height: 24px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
	0% { opacity: 0.6; }
	50% { opacity: 1; }
	100% { opacity: 0.6; }
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Woo Deadline panel — lists open cases whose case-type title contains "Woo"
	(case-insensitive) with a statutory-deadline countdown and traffic-light
	severity. Severity is communicated by both colour AND a text label so the
	panel is not colour-dependent (WCAG 1.4.1).

	Spec: openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-004
-->
<template>
	<div class="woo-panel">
		<h3 class="woo-panel__title">
			{{ t('procest', 'Woo Deadlines') }}
		</h3>

		<template v-if="loading">
			<div v-for="i in 3" :key="i" class="woo-panel__skeleton">
				<div class="skeleton-bar" />
			</div>
		</template>

		<template v-else-if="error">
			<div class="woo-panel__error">
				<p>{{ error }}</p>
				<NcButton type="tertiary" @click="$emit('retry')">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="cases.length === 0">
			<p class="woo-panel__empty">
				{{ t('procest', 'No open Woo requests') }}
			</p>
		</template>

		<template v-else>
			<button
				v-for="item in cases"
				:key="item.id"
				class="woo-panel__row"
				:class="rowClass(item.severity)"
				@click="$emit('click-case', item.id)">
				<div class="woo-panel__info">
					<span class="woo-panel__identifier">{{ item.identifier }}</span>
					<span class="woo-panel__name">{{ item.title }}</span>
					<span v-if="item.initiator && item.initiator !== '—'" class="woo-panel__initiator">
						{{ item.initiator }}
					</span>
				</div>
				<div class="woo-panel__meta">
					<span class="woo-panel__days" :class="daysClass(item.severity)">
						{{ countdownLabel(item) }}
					</span>
					<span class="woo-panel__severity">{{ severityLabel(item.severity) }}</span>
				</div>
			</button>

			<div class="woo-panel__footer">
				<a href="#" @click.prevent="$emit('view-all')">
					{{ t('procest', 'View all Woo cases') }} &rarr;
				</a>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'WooDeadlinePanel',
	components: {
		NcButton,
	},
	props: {
		/** Pre-computed Woo rows from getWooCases() — already sorted. */
		cases: { type: Array, default: () => [] },
		/** Loading flag — renders skeleton rows while true. */
		loading: { type: Boolean, default: false },
		/** Error message — renders retry CTA when set. */
		error: { type: String, default: null },
	},
	emits: ['click-case', 'view-all', 'retry'],
	methods: {
		/**
		 * Severity → text label (WCAG: never colour alone).
		 *
		 * @param {string} severity One of overdue|critical|warning|ok
		 * @return {string}
		 */
		severityLabel(severity) {
			switch (severity) {
			case 'overdue': return this.t('procest', 'Overdue')
			case 'critical': return this.t('procest', 'Critical')
			case 'warning': return this.t('procest', 'At risk')
			default: return this.t('procest', 'On track')
			}
		},
		/**
		 * Build the countdown text for a row.
		 *
		 * @param {object} item Woo row
		 * @return {string}
		 */
		countdownLabel(item) {
			if (item.isOverdue) {
				const days = Math.abs(item.daysRemaining)
				return this.t('procest', '{days} days overdue', { days })
			}
			if (item.daysRemaining === 0) {
				return this.t('procest', 'Due today')
			}
			return this.t('procest', '{days} days remaining', { days: item.daysRemaining })
		},
		/**
		 * @param {string} severity Severity key
		 * @return {string} Row CSS modifier class
		 */
		rowClass(severity) {
			return `woo-panel__row--${severity}`
		},
		/**
		 * @param {string} severity Severity key
		 * @return {string} Days-text CSS modifier class
		 */
		daysClass(severity) {
			return `woo-panel__days--${severity}`
		},
	},
}
</script>

<style scoped>
.woo-panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.woo-panel__title {
	font-size: 15px;
	margin: 0 0 12px;
}

.woo-panel__row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	width: 100%;
	padding: 10px 12px;
	margin-bottom: 4px;
	border-radius: var(--border-radius);
	cursor: pointer;
	background: transparent;
	border: none;
	border-left: 3px solid var(--color-border);
	text-align: left;
	font: inherit;
	color: inherit;
	transition: background 0.15s ease;
}

.woo-panel__row:hover,
.woo-panel__row:focus-visible {
	background: var(--color-background-hover);
}

.woo-panel__row:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.woo-panel__row--overdue {
	border-left-color: var(--color-error);
}

.woo-panel__row--critical {
	border-left-color: var(--color-warning);
}

.woo-panel__row--warning {
	border-left-color: var(--color-warning);
}

.woo-panel__row--ok {
	border-left-color: var(--color-success);
}

.woo-panel__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.woo-panel__identifier {
	font-weight: bold;
	font-size: 13px;
}

.woo-panel__name {
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.woo-panel__initiator {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.woo-panel__meta {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	flex-shrink: 0;
	margin-left: 12px;
}

.woo-panel__days {
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
}

.woo-panel__days--overdue {
	color: var(--color-error);
}

.woo-panel__days--critical,
.woo-panel__days--warning {
	color: var(--color-warning-text);
}

.woo-panel__days--ok {
	color: var(--color-success);
}

.woo-panel__severity {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.woo-panel__footer {
	margin-top: 12px;
	text-align: center;
}

.woo-panel__footer a {
	font-size: 13px;
	color: var(--color-primary);
	text-decoration: none;
}

.woo-panel__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px 0;
}

.woo-panel__error {
	text-align: center;
	padding: 12px;
	color: var(--color-error);
}

.woo-panel__skeleton {
	margin-bottom: 8px;
}

.skeleton-bar {
	height: 48px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
	0% { opacity: 0.6; }
	50% { opacity: 1; }
	100% { opacity: 0.6; }
}

@media (prefers-reduced-motion: reduce) {
	.woo-panel__row {
		transition: none;
	}

	.skeleton-bar {
		animation: none;
	}
}
</style>

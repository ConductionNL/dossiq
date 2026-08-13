<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	At-risk deadline list — the prioritised panel of open cases that are near
	or past their statutory deadline, each with a RAG badge (Overdue / At risk)
	and a deadline-usage progress bar. Extracted from the monolithic
	DoorlooptijdDashboard.vue. Communicates urgency by both colour AND a text
	label so it is not colour-dependent (WCAG 1.4.1).

	Selecting a row emits `select-case` with the case id; the parent routes to
	the case detail page. Behaviour is identical to the inline markup it
	replaces.

	Spec: openspec/specs/doorlooptijd-dashboard/spec.md
-->
<template>
	<div v-if="cases.length > 0" class="at-risk-panel">
		<h3>{{ t('procest', 'At-Risk Cases') }}</h3>
		<div class="at-risk-list">
			<div
				v-for="c in cases"
				:key="c.id"
				class="at-risk-item"
				role="button"
				tabindex="0"
				@click="$emit('select-case', c.id)"
				@keydown.enter="$emit('select-case', c.id)"
				@keydown.space.prevent="$emit('select-case', c.id)">
				<div class="at-risk-item__header">
					<span class="at-risk-item__title">{{
						c.title || c.identifier
					}}</span>
					<span
						v-if="c.isOverdue"
						class="at-risk-badge at-risk-badge--overdue">
						{{ t('procest', 'Overdue') }}
					</span>
					<span v-else class="at-risk-badge at-risk-badge--warning">
						{{ t('procest', 'At risk') }}
					</span>
				</div>
				<div class="at-risk-item__meta">
					<span>{{ c.caseTypeName }}</span>
					<span>{{ c.identifier ? '#' + c.identifier : '' }}</span>
					<span :class="{ 'text-error': c.isOverdue }">
						{{
							c.remainingDays >= 0
								? t('procest', '{days} days remaining', {
										days: c.remainingDays,
									})
								: t('procest', '{days} days overdue', {
										days: Math.abs(c.remainingDays),
									})
						}}
					</span>
				</div>
				<div class="at-risk-item__progress">
					<div class="progress-track">
						<div
							class="progress-fill"
							:class="{
								'progress-fill--danger': c.isOverdue,
								'progress-fill--warning':
									!c.isOverdue && c.percentUsed > 0.75,
							}"
							:style="{
								width: Math.min(c.percentUsed * 100, 100) + '%',
							}" />
					</div>
					<span class="progress-label">
						{{ Math.round(c.percentUsed * 100) }}%
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'DeadlineCaseTable',
	props: {
		/**
		 * At-risk cases as produced by getAtRiskCases(): each entry has
		 * { id, title, identifier, caseTypeName, isOverdue, remainingDays, percentUsed }.
		 */
		cases: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['select-case'],
}
</script>

<style scoped>
.at-risk-panel {
	padding: 16px;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	margin-bottom: 24px;
}

.at-risk-panel h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.at-risk-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.at-risk-item {
	padding: 12px;
	border-radius: 6px;
	border: 1px solid var(--color-border);
	cursor: pointer;
	transition: background 0.15s;
}

.at-risk-item:hover {
	background: var(--color-background-hover);
}

.at-risk-item__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.at-risk-item__title {
	font-weight: 600;
	font-size: 14px;
	flex: 1;
}

.at-risk-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.at-risk-badge--overdue {
	background: rgba(233, 50, 45, 0.12);
	color: var(--color-error);
}

.at-risk-badge--warning {
	background: rgba(232, 163, 22, 0.12);
	color: var(--color-warning-text);
}

.at-risk-item__meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
}

.at-risk-item__progress {
	display: flex;
	align-items: center;
	gap: 8px;
}

.progress-track {
	flex: 1;
	height: 6px;
	background: var(--color-background-dark);
	border-radius: 3px;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	border-radius: 3px;
	background: var(--color-warning);
	transition: width 0.3s;
}

.progress-fill--danger {
	background: var(--color-error);
}

.progress-fill--warning {
	background: var(--color-warning);
}

.progress-label {
	font-size: 12px;
	font-weight: 600;
	width: 40px;
	text-align: right;
}

.text-error {
	color: var(--color-error);
	font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
	.at-risk-item,
	.progress-fill {
		transition: none;
	}
}
</style>

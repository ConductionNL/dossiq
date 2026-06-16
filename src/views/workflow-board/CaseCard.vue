<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow-board case card — a single draggable Kanban card. Shows the case
	identifier, truncated title, case-type chip, assignee and a deadline
	indicator. Emits `dragstart` (with the case id) and `click` (open detail).

	Spec: openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-006
-->
<template>
	<div
		class="case-card"
		:class="{ 'case-card--overdue': deadlineSeverity === 'overdue', 'case-card--warning': deadlineSeverity === 'warning' }"
		draggable="true"
		role="button"
		tabindex="0"
		@dragstart="onDragStart"
		@click="$emit('click', caseItem.id)"
		@keydown.enter="$emit('click', caseItem.id)"
		@keydown.space.prevent="$emit('click', caseItem.id)">
		<div class="case-card__header">
			<span class="case-card__identifier">{{ caseItem.identifier || '—' }}</span>
			<span v-if="caseTypeName" class="case-card__type">{{ caseTypeName }}</span>
		</div>
		<p class="case-card__title">
			{{ caseItem.title || '—' }}
		</p>
		<div class="case-card__footer">
			<span class="case-card__assignee">
				{{ caseItem.assignee || t('procest', 'Unassigned') }}
			</span>
			<span
				v-if="deadlineLabel"
				class="case-card__deadline"
				:class="deadlineClass">
				{{ deadlineLabel }}
			</span>
		</div>
	</div>
</template>

<script>
import { getDaysRemaining } from '../../utils/caseHelpers.js'

export default {
	name: 'CaseCard',
	props: {
		/** The case object: { id, identifier, title, caseType, assignee, deadline }. */
		caseItem: { type: Object, required: true },
		/** Resolved case-type display name (parent resolves from the type map). */
		caseTypeName: { type: String, default: '' },
	},
	emits: ['click', 'dragstart'],
	computed: {
		/**
		 * Days remaining on the deadline, or null when there is no deadline.
		 *
		 * @return {number|null}
		 */
		daysRemaining() {
			if (!this.caseItem.deadline) return null
			return getDaysRemaining(this.caseItem.deadline)
		},
		/**
		 * Deadline severity: overdue (<0), warning (<=3), or ok.
		 *
		 * @return {string|null}
		 */
		deadlineSeverity() {
			if (this.daysRemaining === null) return null
			if (this.daysRemaining < 0) return 'overdue'
			if (this.daysRemaining <= 3) return 'warning'
			return 'ok'
		},
		/**
		 * Human-readable deadline label (WCAG: text accompanies the colour).
		 *
		 * @return {string|null}
		 */
		deadlineLabel() {
			if (this.daysRemaining === null) return null
			if (this.daysRemaining < 0) {
				return this.t('procest', '{days} days overdue', { days: Math.abs(this.daysRemaining) })
			}
			if (this.daysRemaining === 0) return this.t('procest', 'Due today')
			return this.t('procest', '{days} days', { days: this.daysRemaining })
		},
		/**
		 * @return {string} Deadline CSS modifier class
		 */
		deadlineClass() {
			return this.deadlineSeverity ? `case-card__deadline--${this.deadlineSeverity}` : ''
		},
	},
	methods: {
		/**
		 * Stash the dragged case id on the dataTransfer payload and notify the
		 * parent board so it can track the in-flight card.
		 *
		 * @param {DragEvent} event The native dragstart event
		 * @return {void}
		 */
		onDragStart(event) {
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
				event.dataTransfer.setData('text/plain', String(this.caseItem.id))
			}
			this.$emit('dragstart', this.caseItem.id)
		},
	},
}
</script>

<style scoped>
.case-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-left: 3px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 12px;
	margin-bottom: 8px;
	cursor: grab;
	transition: box-shadow 0.15s ease, background 0.15s ease;
}

.case-card:hover,
.case-card:focus-visible {
	background: var(--color-background-hover);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
}

.case-card:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.case-card--overdue {
	border-left-color: var(--color-error);
}

.case-card--warning {
	border-left-color: var(--color-warning);
}

.case-card__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.case-card__identifier {
	font-weight: bold;
	font-size: 12px;
}

.case-card__type {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
	padding: 1px 8px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 50%;
}

.case-card__title {
	font-size: 13px;
	margin: 0 0 6px;
	overflow: hidden;
	text-overflow: ellipsis;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}

.case-card__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.case-card__assignee {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.case-card__deadline {
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.case-card__deadline--overdue {
	color: var(--color-error);
}

.case-card__deadline--warning {
	color: var(--color-warning-text);
}

.case-card__deadline--ok {
	color: var(--color-text-maxcontrast);
}
</style>

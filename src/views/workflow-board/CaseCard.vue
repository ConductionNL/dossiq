<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow-board case card — a single draggable Kanban card. Shows the case
	identifier, truncated title, case-type chip, assignee and a deadline
	indicator. Emits `dragstart` (with the case id), `click` (open detail),
	`move` (caseId, newStatusId) from the keyboard-operable "Move to…" menu —
	the same status-transition path as the drag gesture (WCAG 2.1.1 Keyboard) —
	and `toggle-select` (caseId) from its selection checkbox, used by the
	column-scoped bulk-selection UI (case-bulk-status-transition).

	Spec: openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
	Spec: openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
-->
<template>
	<div
		class="case-card"
		:class="{
			'case-card--overdue': deadlineSeverity === 'overdue',
			'case-card--warning': deadlineSeverity === 'warning',
			'case-card--selection-mode': selectionMode,
			'case-card--selected': selected,
		}"
		draggable="true"
		role="button"
		tabindex="0"
		@dragstart="onDragStart"
		@click="$emit('click', caseItem.id)"
		@keydown.enter="$emit('click', caseItem.id)"
		@keydown.space.prevent="$emit('click', caseItem.id)">
		<NcCheckboxRadioSwitch
			class="case-card__select"
			:model-value="selected"
			@update:model-value="$emit('toggle-select', caseItem.id)"
			@click.stop
			@keydown.stop>
			<span class="hidden-visually">{{ t('procest', 'Select case {identifier}', { identifier: caseItem.identifier || caseItem.id }) }}</span>
		</NcCheckboxRadioSwitch>
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

		<!-- Keyboard-operable status move control (REQ-KBD-01). Separate
			focusable control from the card body's open-detail action; stop
			propagation so activating it never also fires the card's own
			click/open handler. -->
		<NcActions
			v-if="otherColumns.length > 0"
			class="case-card__move-actions"
			:inline="0"
			@click.stop
			@keydown.stop>
			<template #icon>
				<ArrowRightBoldCircleOutline :size="18" />
			</template>
			<NcActionButton
				v-for="col in otherColumns"
				:key="col.id"
				@click="$emit('move', caseItem.id, col.id)">
				{{ t('procest', 'Move to {status}', { status: col.name }) }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ArrowRightBoldCircleOutline from 'vue-material-design-icons/ArrowRightBoldCircleOutline.vue'
import { getDaysRemaining } from '../../utils/caseHelpers.js'
import { columnsExcludingCurrent } from '../../utils/workflowBoardHelpers.js'

export default {
	name: 'CaseCard',
	components: {
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		ArrowRightBoldCircleOutline,
	},
	props: {
		/** The case object: { id, identifier, title, caseType, assignee, deadline }. */
		caseItem: { type: Object, required: true },
		/** Resolved case-type display name (parent resolves from the type map). */
		caseTypeName: { type: String, default: '' },
		/**
		 * All board columns (status types), used to populate the "Move to…"
		 * menu with every status other than this card's current one.
		 *
		 * @type {Array<{id: string, name: string}>}
		 */
		columns: { type: Array, default: () => [] },
		/** Whether this card is currently in the bulk-selection set. */
		selected: { type: Boolean, default: false },
		/**
		 * Whether this card's column is the active selection scope — while
		 * true the selection checkbox stays visible even without hover/focus
		 * (case-bulk-status-transition column-scoped selection).
		 */
		selectionMode: { type: Boolean, default: false },
	},
	emits: ['click', 'dragstart', 'move', 'toggle-select'],
	computed: {
		/**
		 * Status columns the card can move to — every column except the one
		 * it is currently in.
		 *
		 * @return {Array<{id: string, name: string}>}
		 */
		otherColumns() {
			return columnsExcludingCurrent(this.columns, this.caseItem.status)
		},
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
	position: relative;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-left: 3px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 12px;
	margin-bottom: 8px;
	cursor: grab;
	transition: box-shadow 0.15s ease, background 0.15s ease;
}

.case-card__move-actions {
	position: absolute;
	top: 4px;
	right: 4px;
}

.case-card__select {
	position: absolute;
	top: 2px;
	left: 2px;
	opacity: 0;
	transition: opacity 0.1s ease;
}

.case-card:hover .case-card__select,
.case-card:focus-within .case-card__select,
.case-card--selection-mode .case-card__select,
.case-card--selected .case-card__select {
	opacity: 1;
}

.case-card__header {
	padding-left: 26px;
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

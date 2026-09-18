<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow-board case card — a single Kanban card. Shows the case identifier,
	truncated title, case-type chip, assignee and a deadline indicator. Emits
	`click` (open detail), `contextMenu` (caseId, event) on right-click,
	`requestMove` (caseId) on the M key, and `toggle-select` (caseId) from its
	selection checkbox, used by the column-scoped bulk-selection UI
	(case-bulk-status-transition).

	Dragging is Sortable's, set up by BoardColumn's list: the card only carries
	its id in `data-case-id` for the column to read off the dragged element,
	and the three drag classes styled at the bottom of this file.

	THE CARD CARRIES NO MOVE CONTROL OF ITS OWN ANY MORE, and the M key is why
	it is still keyboard-operable. It used to hold an NcActions listing every
	board column, which is one per status NAME across every case type on the
	instance: two hundred items on a real register, nearly all of them statuses
	this case cannot reach. Moving is now asked for — right-click, or M on the
	focused card — and answered by a dialog the board fills from the engine's
	offer for THIS case.

	The M key is the WCAG 2.1.1 path the menu used to be (dragging is
	mouse-only), so it is named in the card's aria-label: a gesture with no
	visible control has to be announced or it does not exist. A right-click is
	also reachable from the keyboard via Shift+F10 / the Menu key, but that is
	a fallback, not the affordance.

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
		role="button"
		tabindex="0"
		:data-case-id="caseItem.id"
		:aria-label="ariaLabel"
		@click="$emit('click', caseItem.id)"
		@contextmenu.prevent="$emit('contextMenu', caseItem.id, $event)"
		@keydown.enter="$emit('click', caseItem.id)"
		@keydown.space.prevent="$emit('click', caseItem.id)"
		@keydown.m.prevent="$emit('requestMove', caseItem.id)">
		<NcCheckboxRadioSwitch
			class="case-card__select"
			:modelValue="selected"
			@update:modelValue="$emit('toggle-select', caseItem.id)"
			@click.stop
			@keydown.stop>
			<span class="hidden-visually">{{
				t('dossiq', 'Select case {identifier}', {
					identifier: caseItem.identifier || caseItem.id,
				})
			}}</span>
		</NcCheckboxRadioSwitch>
		<div class="case-card__header">
			<span class="case-card__identifier">{{
				caseItem.identifier || '—'
			}}</span>
			<span v-if="caseTypeName" class="case-card__type">{{
				caseTypeName
			}}</span>
		</div>
		<p class="case-card__title">
			{{ caseItem.title || '—' }}
		</p>
		<div class="case-card__footer">
			<span class="case-card__assignee">
				{{ caseItem.assignee || t('dossiq', 'Unassigned') }}
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
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { getDaysRemaining } from '../../utils/caseHelpers.js'

export default {
	name: 'CaseCard',
	components: {
		NcCheckboxRadioSwitch,
	},

	props: {
		/** The case object: { id, identifier, title, caseType, assignee, deadline }. */
		caseItem: { type: Object, required: true },
		/** Resolved case-type display name (parent resolves from the type map). */
		caseTypeName: { type: String, default: '' },
		/** Whether this card is currently in the bulk-selection set. */
		selected: { type: Boolean, default: false },
		/**
		 * Whether this card's column is the active selection scope — while
		 * true the selection checkbox stays visible even without hover/focus
		 * (case-bulk-status-transition column-scoped selection).
		 */
		selectionMode: { type: Boolean, default: false },
	},

	emits: ['click', 'contextMenu', 'requestMove', 'toggle-select'],
	computed: {
		/**
		 * What the card announces, including how to move it.
		 *
		 * The move gesture is named here because it has no visible control to
		 * find: dragging is a mouse gesture, and `m` is the keyboard one. An
		 * affordance a screen-reader user cannot discover is not an
		 * affordance, and this is the only place left that can say it.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
		 */
		ariaLabel() {
			return this.t(
				'dossiq',
				'Case {identifier}: {title}. Press Enter to open, or M to move it to another status.',
				{
					identifier: this.caseItem.identifier || this.caseItem.id,
					title: this.caseItem.title || '',
				},
			)
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
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
		 */
		deadlineLabel() {
			if (this.daysRemaining === null) return null
			if (this.daysRemaining < 0) {
				return this.t('dossiq', '{days} days overdue', {
					days: Math.abs(this.daysRemaining),
				})
			}
			if (this.daysRemaining === 0) return this.t('dossiq', 'Due today')
			return this.t('dossiq', '{days} days', { days: this.daysRemaining })
		},

		/**
		 * @return {string} Deadline CSS modifier class
		 */
		deadlineClass() {
			return this.deadlineSeverity
				? `case-card__deadline--${this.deadlineSeverity}`
				: ''
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
	/* A button, and Sortable's pointer drag would otherwise select its text. */
	user-select: none;
	transition:
		box-shadow 0.15s ease,
		background 0.15s ease;
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
	/* Space for the absolutely-positioned .case-card__select checkbox. */
	padding-left: 26px;
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

/* Sortable's three drag classes, named in BoardColumn's list options. */
.case-card--chosen {
	cursor: grabbing;
}

/* The placeholder left where the card would land. */
.case-card--ghost {
	opacity: 0.35;
	border-style: dashed;
	background: var(--color-background-hover);
	box-shadow: none;
}

/* The clone under the pointer. */
.case-card--dragging {
	opacity: 0.95;
	transform: rotate(1.5deg) scale(1.02);
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
	cursor: grabbing;
}

@media (prefers-reduced-motion: reduce) {
	.case-card,
	.case-card__select {
		transition: none;
	}

	.case-card--dragging {
		transform: none;
	}
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow-board column — one Kanban column per non-final status type. Renders
	a header (status name + case count) and a Sortable list of CaseCard
	children. A card dragged out of another column lands here through
	`cardDropped({ caseId, fromColumn, toColumn })`, which the parent board
	answers with the actual status transition. Also re-emits each CaseCard's
	`contextMenu(caseId, event)` and `requestMove(caseId)` — the two ways a
	move is asked for now that the card carries no menu of its own — and
	`toggle-select(caseId, columnId)` from its selection checkbox, used by the
	column-scoped bulk-selection UI (case-bulk-status-transition).

	THE DRAG IS SORTABLE'S (vue-draggable-plus), NOT THE BROWSER'S. The native
	HTML5 drag showed a grab cursor and then nothing moved: no ghost under the
	pointer, no gap opening where the card would land, and the card appeared
	in its new column only after the drop. Sortable in fallback mode draws its
	own clone under the pointer (`dragClass`), leaves a placeholder in the list
	it is over (`ghostClass`) and animates the others aside, so the move reads
	as a move before it is one.

	A COLUMN CAN REFUSE THE CARD WHILE IT IS IN THE AIR. The board fetches the
	engine's offer for the dragged case on `dragstart` and hands every column a
	`dropState`; `canDrop` is asked on each hover (Sortable's `onMove` runs on
	the list the card came FROM, so the check is a function from the board,
	not a per-column emit). A refused column fades and, when a guard is what
	holds the case, says so under its header — the same sentence the case page
	shows — rather than taking the card and giving it back with a toast.

	The list is rendered even when the column is empty: a `v-else` paragraph in
	its place would leave an empty column with nothing to drop on.

	Spec: openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
	Spec: openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
-->
<template>
	<div
		class="board-column"
		:class="{
			'board-column--refused': refused,
			'board-column--reachable': reachable,
		}">
		<div class="board-column__header">
			<span
				class="board-column__swatch"
				:style="colourStyle"
				:data-colour="colour"
				data-testid="board-column-colour"
				aria-hidden="true" />
			<span class="board-column__name">{{ statusType.name }}</span>
			<span class="board-column__count">{{ cases.length }}</span>
		</div>

		<p v-if="hint" class="board-column__hint" data-testid="board-column-hint">
			{{ hint }}
		</p>

		<div class="board-column__body">
			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else>
				<VueDraggable
					:modelValue="cases"
					class="board-column__list"
					:data-column-id="statusType.id"
					group="dossiq-board"
					:sort="false"
					:animation="150"
					draggable=".case-card"
					filter=".case-card__select"
					:preventOnFilter="false"
					:forceFallback="true"
					:fallbackOnBody="true"
					:fallbackTolerance="4"
					:delay="120"
					:delayOnTouchOnly="true"
					ghostClass="case-card--ghost"
					chosenClass="case-card--chosen"
					dragClass="case-card--dragging"
					@update:modelValue="$emit('update:cases', $event)"
					@start="onStart"
					@add="onAdd"
					@end="$emit('dragend')"
					@move="onMove">
					<CaseCard
						v-for="c in cases"
						:key="c.id"
						:caseItem="c"
						:caseTypeName="caseTypeName(c.caseType)"
						:selected="selectedCaseIds.includes(String(c.id))"
						:selectionMode="selectionColumnId === statusType.id"
						@click="$emit('click-case', $event)"
						@contextMenu="
							(caseId, event) => $emit('contextMenu', caseId, event)
						"
						@requestMove="(caseId) => $emit('requestMove', caseId)"
						@toggleSelect="
							(caseId) => $emit('toggle-select', caseId, statusType.id)
						" />
				</VueDraggable>

				<p v-if="cases.length === 0" class="board-column__empty">
					{{ t('dossiq', 'No cases') }}
				</p>
			</template>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'
import CaseCard from './CaseCard.vue'
import {
	normaliseStatusColour,
	statusColourToken,
} from '../../utils/statusColour.js'

export default {
	name: 'BoardColumn',
	components: {
		CaseCard,
		NcLoadingIcon,
		VueDraggable,
	},

	props: {
		/** The status type for this column: { id, name, order, isFinal }. */
		statusType: { type: Object, required: true },
		/** Cases currently in this status. */
		cases: { type: Array, default: () => [] },
		/** Loading flag — renders a spinner while true. */
		loading: { type: Boolean, default: false },
		/** Map of caseType id → display name, supplied by the parent board. */
		caseTypeMap: { type: Object, default: () => ({}) },
		/** Case ids currently selected (bulk selection), as strings. */
		selectedCaseIds: { type: Array, default: () => [] },
		/** The column id that owns the active selection scope, or null. */
		selectionColumnId: { type: String, default: null },
		/**
		 * The board's verdict on the card in the air landing here, as
		 * `dropVerdict` shapes it: `{ allowed, reason, blocked }`. Null when
		 * nothing is being dragged, or this is the column it came from.
		 */
		dropState: { type: Object, default: null },
		/**
		 * Whether the dragged card may enter the column with this id. Asked
		 * from `onMove`, which Sortable fires on the SOURCE list.
		 *
		 * @type {(toColumnId: string) => boolean}
		 */
		canDrop: { type: Function, default: () => true },
	},

	emits: [
		'cardDropped',
		'click-case',
		'contextMenu',
		'dragend',
		'dragstart',
		'requestMove',
		'toggle-select',
		'update:cases',
	],

	computed: {
		/**
		 * The colour name this column is drawn in.
		 *
		 * The board merges every non-final status sharing a NAME into one
		 * column, so the colour arrives on the merged column rather than on a
		 * single status type; `mergeColumnColour` decided which one won.
		 *
		 * @return {string} A name from the palette; grey when none is set.
		 * @spec openspec/specs/case-types/spec.md
		 */
		colour() {
			return normaliseStatusColour(this.statusType.colour)
		},

		/**
		 * The header swatch's colour.
		 *
		 * A swatch beside the name rather than a tinted header bar: the name
		 * has to stay readable at every hue, and a full-bleed background in
		 * the darker hues would leave it at a contrast ratio the palette
		 * cannot guarantee (WCAG 2.2 SC 1.4.3). The swatch is decorative and
		 * marked aria-hidden — the column already says its status in words.
		 *
		 * @return {object} A style object.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		colourStyle() {
			return { backgroundColor: statusColourToken(this.colour) }
		},

		/**
		 * @return {boolean} The dragged card cannot land here.
		 */
		refused() {
			return this.dropState?.allowed === false
		},

		/**
		 * @return {boolean} The dragged card can land here.
		 */
		reachable() {
			return this.dropState?.allowed === true
		},

		/**
		 * Why the dragged card cannot land here, when a guard is the reason.
		 *
		 * @return {string} The guard's sentence, or the empty string.
		 */
		hint() {
			if (!this.dropState?.blocked) {
				return ''
			}
			return (
				this.dropState.reason
				|| this.t('dossiq', 'Something is holding this case here.')
			)
		},
	},

	methods: {
		/**
		 * Resolve a caseType id to its display name.
		 *
		 * @param {string} caseTypeId The caseType uuid
		 * @return {string}
		 */
		caseTypeName(caseTypeId) {
			return this.caseTypeMap[caseTypeId] || ''
		},

		/**
		 * A card in this column was picked up.
		 *
		 * @param {object} evt Sortable's start event; `item` is the card
		 * @return {void}
		 */
		onStart(evt) {
			const caseId = evt?.item?.dataset?.caseId
			if (caseId) {
				this.$emit('dragstart', caseId)
			}
		},

		/**
		 * A card from another column was dropped here. The wrapper has already
		 * moved it between the two lists; the board now makes the move real.
		 *
		 * @param {object} evt Sortable's add event; `item` is the card, `from` the source list
		 * @return {void}
		 */
		onAdd(evt) {
			const caseId = evt?.item?.dataset?.caseId
			const fromColumn = evt?.from?.dataset?.columnId
			if (caseId && fromColumn) {
				this.$emit('cardDropped', {
					caseId,
					fromColumn,
					toColumn: this.statusType.id,
				})
			}
		},

		/**
		 * Whether the card may enter the list it is being held over.
		 *
		 * Fired on the column the card came FROM. Returning to that column is
		 * always allowed, or Sortable could not put the card back.
		 *
		 * @param {object} evt Sortable's move event; `to` is the hovered list
		 * @return {boolean} False refuses the hover
		 */
		onMove(evt) {
			const to = evt?.to?.dataset?.columnId ?? null
			if (to === null || to === this.statusType.id) {
				return true
			}
			return this.canDrop(to) !== false
		},
	},
}
</script>

<style scoped>
.board-column {
	display: flex;
	flex-direction: column;
	min-width: 240px;
	max-width: 320px;
	flex: 1;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 8px;
	transition: opacity 0.15s ease;
}

/* The column the card is held over: Sortable's placeholder is inside it. */
.board-column:has(.case-card--ghost) {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: -2px;
}

.board-column--reachable:not(:has(.case-card--ghost)) {
	outline: 2px dashed var(--color-border-dark);
	outline-offset: -2px;
}

.board-column--refused {
	opacity: 0.5;
}

.board-column__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 4px 8px 8px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 8px;
}

.board-column__swatch {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	margin-right: 8px;
	border-radius: 50%;
}

.board-column__name {
	/* The header is a space-between row; without this the name would float
	   to the centre once the swatch joined it and the count stayed right. */
	flex: 1;
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.board-column__count {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-main-background);
	border-radius: var(--border-radius-pill);
	padding: 1px 10px;
}

.board-column__hint {
	margin: 0 0 8px;
	padding: 6px 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	background: var(--color-main-background);
	border-radius: var(--border-radius);
}

/* The column is stretched to the board's height; the body takes what the
   header leaves and scrolls its own cards. */
.board-column__body {
	flex: 1;
	min-height: 80px;
	overflow-y: auto;
}

/* Tall enough to drop on when empty. */
.board-column__list {
	min-height: 64px;
}

.board-column__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	font-size: 13px;
	padding: 24px 8px;
}

/* The placeholder has taken the empty column's place; the text would sit under it. */
.board-column__list:has(.case-card--ghost) + .board-column__empty {
	display: none;
}

@media (prefers-reduced-motion: reduce) {
	.board-column {
		transition: none;
	}
}
</style>

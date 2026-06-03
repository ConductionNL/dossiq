<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow-board column — one Kanban column per non-final status type. Renders
	a header (status name + case count), a scrollable list of CaseCard children,
	and accepts drag-and-drop of cards. On drop it emits `drop(caseId, statusId)`
	to the parent board, which performs the actual status transition.

	Spec: openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-006
-->
<template>
	<div
		class="board-column"
		:class="{ 'board-column--dragover': dragOver }"
		@dragover.prevent="dragOver = true"
		@dragleave="dragOver = false"
		@drop="onDrop">
		<div class="board-column__header">
			<span class="board-column__name">{{ statusType.name }}</span>
			<span class="board-column__count">{{ cases.length }}</span>
		</div>

		<div class="board-column__body">
			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else-if="cases.length === 0">
				<p class="board-column__empty">
					{{ t('procest', 'No cases') }}
				</p>
			</template>

			<template v-else>
				<CaseCard
					v-for="c in cases"
					:key="c.id"
					:case-item="c"
					:case-type-name="caseTypeName(c.caseType)"
					@click="$emit('click-case', $event)"
					@dragstart="$emit('dragstart', $event)" />
			</template>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import CaseCard from './CaseCard.vue'

export default {
	name: 'BoardColumn',
	components: {
		NcLoadingIcon,
		CaseCard,
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
	},
	emits: ['drop', 'click-case', 'dragstart'],
	data() {
		return {
			dragOver: false,
		}
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
		 * Handle a card drop: read the dragged case id and notify the parent to
		 * transition it into this column's status.
		 *
		 * @param {DragEvent} event The native drop event
		 * @return {void}
		 */
		onDrop(event) {
			this.dragOver = false
			const caseId = event.dataTransfer ? event.dataTransfer.getData('text/plain') : null
			if (caseId) {
				this.$emit('drop', caseId, this.statusType.id)
			}
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
}

.board-column--dragover {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: -2px;
}

.board-column__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 4px 8px 8px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 8px;
}

.board-column__name {
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

.board-column__body {
	overflow-y: auto;
	max-height: calc(100vh - 200px);
	min-height: 80px;
}

.board-column__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	font-size: 13px;
	padding: 24px 8px;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The List view of a case type's flow (board DqZaaktypeFlow): the trigger
  first, then every status and its steps in order, numbered, each with its
  kind pill. It reads the same statuses and steps the canvas draws and only
  emits; WorkflowEditor writes every change through the workflow store, so a
  change here is the same change on the canvas.
-->
<template>
	<div class="workflow-list" data-testid="workflow-list">
		<p class="workflow-list__trigger" data-testid="workflow-list-trigger">
			{{ t('dossiq', 'When: {trigger}', { trigger: triggerLabel }) }}
		</p>
		<ol class="workflow-list__rows">
			<li
				v-for="row in list.rows"
				:key="row.id"
				class="workflow-list__row"
				:class="['workflow-list__row--' + row.kind]"
				:data-testid="'workflow-list-row-' + row.id">
				<span class="workflow-list__number">{{ row.number }}</span>
				<span class="workflow-list__label">{{ row.label }}</span>
				<CnStatusBadge
					class="workflow-list__pill"
					:label="kindLabel(row.kind)"
					:variant="row.kind === 'status' ? 'primary' : 'default'"
					size="small" />
				<span class="workflow-list__actions">
					<button
						v-for="action in actionsFor(row)"
						:key="action.id"
						type="button"
						class="workflow-list__action"
						:disabled="action.disabled"
						:aria-label="action.ariaLabel"
						:data-testid="'workflow-list-' + action.id"
						@click="$emit(action.event, ...action.args)">
						{{ action.text }}
					</button>
				</span>
			</li>
		</ol>
		<p v-if="list.rows.length === 0" class="workflow-list__empty">
			{{ t('dossiq', 'This flow has no statuses yet.') }}
		</p>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { DEFAULT_TRIGGER, flowListRows } from '../../../utils/flowList.js'

export default {
	name: 'WorkflowList',
	components: { CnStatusBadge },

	props: {
		statuses: {
			type: Array,
			default: () => [],
		},

		steps: {
			type: Array,
			default: () => [],
		},

		trigger: {
			type: String,
			default: null,
		},

		readOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['moveStep', 'editStep', 'deleteStep', 'addStep', 'editStatus', 'deleteStatus'],

	computed: {
		/**
		 * @return {{trigger: string, rows: Array<object>}} The flow as an ordered list.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#scenario-the-list-shows-the-flow-in-order
		 */
		list() {
			return flowListRows(this.statuses, this.steps, this.trigger)
		},

		/**
		 * @return {string} The trigger in the reader's language when it is the default one.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#scenario-the-list-shows-the-flow-in-order
		 */
		triggerLabel() {
			return this.list.trigger === DEFAULT_TRIGGER
				? t('dossiq', 'Case created')
				: this.list.trigger
		},
	},

	methods: {
		/**
		 * The buttons one row offers: a step moves up and down, is edited and
		 * deleted; a status is edited, gets a step and is deleted. A published
		 * flow offers no change, only Edit to read the panel.
		 *
		 * @param {object} row One row of the list.
		 * @return {Array<{id: string, text: string, ariaLabel: string, disabled: boolean, event: string, args: Array}>} The buttons.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
		 */
		actionsFor(row) {
			const name = { name: row.label }
			const ro = this.readOnly
			if (row.kind !== 'status') {
				return [
					{ id: 'up', text: '↑', ariaLabel: t('dossiq', 'Move {name} up', name), disabled: ro || row.first, event: 'moveStep', args: [row.id, 'up'] },
					{ id: 'down', text: '↓', ariaLabel: t('dossiq', 'Move {name} down', name), disabled: ro || row.last, event: 'moveStep', args: [row.id, 'down'] },
					{ id: 'edit', text: t('dossiq', 'Edit'), ariaLabel: t('dossiq', 'Edit {name}', name), disabled: false, event: 'editStep', args: [row.id] },
					{ id: 'delete', text: t('dossiq', 'Delete'), ariaLabel: t('dossiq', 'Delete {name}', name), disabled: ro, event: 'deleteStep', args: [row.id] },
				]
			}
			return [
				{ id: 'edit-status', text: t('dossiq', 'Edit'), ariaLabel: t('dossiq', 'Edit {name}', name), disabled: false, event: 'editStatus', args: [row.statusId] },
				{ id: 'add-step', text: t('dossiq', 'Add step'), ariaLabel: t('dossiq', 'Add a step to {name}', name), disabled: ro, event: 'addStep', args: [row.statusId] },
				{ id: 'delete-status', text: t('dossiq', 'Delete'), ariaLabel: t('dossiq', 'Delete {name}', name), disabled: ro, event: 'deleteStatus', args: [row.statusId] },
			]
		},

		/**
		 * @param {string} kind status, task, message or action.
		 * @return {string} The pill's word.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#scenario-the-list-shows-the-flow-in-order
		 */
		kindLabel(kind) {
			return {
				status: t('dossiq', 'Status'),
				task: t('dossiq', 'Task'),
				message: t('dossiq', 'Message'),
				action: t('dossiq', 'Action'),
			}[kind]
		},
	},
}
</script>

<style scoped>
.workflow-list {
	max-width: 880px;
}

.workflow-list__trigger {
	font-weight: bold;
	margin: 0 0 12px;
}

.workflow-list__rows {
	list-style: none;
	margin: 0;
	padding: 0;
}

.workflow-list__row {
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	gap: 12px;
	padding: 8px 4px;
}

.workflow-list__row--task,
.workflow-list__row--message,
.workflow-list__row--action {
	padding-inline-start: 28px;
}

.workflow-list__number {
	color: var(--color-text-maxcontrast);
	min-width: 24px;
}

.workflow-list__label {
	flex: 1;
}

.workflow-list__row--status .workflow-list__label {
	font-weight: bold;
}


.workflow-list__actions {
	display: flex;
	gap: 4px;
}

.workflow-list__action {
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	padding: 2px 8px;
}

.workflow-list__action:disabled {
	cursor: default;
	opacity: 0.5;
}

.workflow-list__empty {
	color: var(--color-text-maxcontrast);
}
</style>

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
				class="workflow-list__row" :class="['workflow-list__row--' + row.kind]"
				:data-testid="'workflow-list-row-' + row.id">
				<span class="workflow-list__number">{{ row.number }}</span>
				<span class="workflow-list__label">{{ row.label }}</span>
				<span class="workflow-list__pill" :class="['workflow-list__pill--' + row.kind]">
					{{ kindLabel(row.kind) }}
				</span>
				<span class="workflow-list__actions">
					<template v-if="row.kind !== 'status'">
						<button
							type="button"
							class="workflow-list__action"
							:disabled="readOnly || row.first"
							:aria-label="t('dossiq', 'Move {name} up', { name: row.label })"
							data-testid="workflow-list-up"
							@click="$emit('moveStep', row.id, 'up')">
							↑
						</button>
						<button
							type="button"
							class="workflow-list__action"
							:disabled="readOnly || row.last"
							:aria-label="t('dossiq', 'Move {name} down', { name: row.label })"
							data-testid="workflow-list-down"
							@click="$emit('moveStep', row.id, 'down')">
							↓
						</button>
						<button
							type="button"
							class="workflow-list__action"
							:aria-label="t('dossiq', 'Edit {name}', { name: row.label })"
							data-testid="workflow-list-edit"
							@click="$emit('editStep', row.id)">
							{{ t('dossiq', 'Edit') }}
						</button>
						<button
							type="button"
							class="workflow-list__action"
							:disabled="readOnly"
							:aria-label="t('dossiq', 'Delete {name}', { name: row.label })"
							data-testid="workflow-list-delete"
							@click="$emit('deleteStep', row.id)">
							{{ t('dossiq', 'Delete') }}
						</button>
					</template>
					<template v-else>
						<button
							type="button"
							class="workflow-list__action"
							:disabled="readOnly"
							:aria-label="t('dossiq', 'Add a step to {name}', { name: row.label })"
							data-testid="workflow-list-add-step"
							@click="$emit('addStep', row.statusId)">
							{{ t('dossiq', 'Add step') }}
						</button>
						<button
							type="button"
							class="workflow-list__action"
							:disabled="readOnly"
							:aria-label="t('dossiq', 'Delete {name}', { name: row.label })"
							data-testid="workflow-list-delete-status"
							@click="$emit('deleteStatus', row.statusId)">
							{{ t('dossiq', 'Delete') }}
						</button>
					</template>
				</span>
			</li>
		</ol>
		<p v-if="list.rows.length === 0" class="workflow-list__empty">
			{{ t('dossiq', 'This flow has no statuses yet.') }}
		</p>
	</div>
</template>

<script>
import { DEFAULT_TRIGGER, flowListRows } from '../../../utils/flowList.js'

export default {
	name: 'WorkflowList',

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

	emits: ['moveStep', 'editStep', 'deleteStep', 'addStep', 'deleteStatus'],

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

.workflow-list__pill {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	padding: 2px 10px;
}

.workflow-list__pill--status {
	background: var(--color-primary-element-light);
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

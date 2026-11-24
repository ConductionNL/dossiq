<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The side panel of a status step in a case type's flow (board
  DqZaaktypeFlow), opened from the List and from the canvas alike: Step name,
  Name of the status, Steps in this status, Next status and Deadline in this
  status, with Done and Delete step. It only emits; WorkflowEditor saves the
  status and writes the next status as the status's outgoing transition.
-->
<template>
	<div class="status-step-panel" data-testid="status-step-panel">
		<div class="status-step-panel__header">
			<h4>{{ t('dossiq', 'Status step') }}</h4>
		</div>

		<div class="status-step-panel__body">
			<div class="status-step-panel__field">
				<label for="status-step-name">{{ t('dossiq', 'Step name') }}</label>
				<input
					id="status-step-name"
					v-model="local.name"
					class="status-step-panel__input"
					type="text"
					:disabled="readOnly"
					data-testid="status-step-name">
			</div>

			<div class="status-step-panel__field">
				<label for="status-step-public-label">{{ t('dossiq', 'Name of the status') }}</label>
				<input
					id="status-step-public-label"
					v-model="local.publicLabel"
					class="status-step-panel__input"
					type="text"
					:disabled="readOnly"
					data-testid="status-step-public-label">
			</div>

			<div class="status-step-panel__field">
				<label>{{ t('dossiq', 'Steps in this status') }}</label>
				<ul class="status-step-panel__steps" data-testid="status-step-steps">
					<li v-for="step in steps" :key="step.id">
						{{ step.title }}
					</li>
				</ul>
				<p v-if="steps.length === 0" class="status-step-panel__empty">
					{{ t('dossiq', 'No steps in this status yet.') }}
				</p>
			</div>

			<div class="status-step-panel__field">
				<label for="status-step-next">{{ t('dossiq', 'Next status') }}</label>
				<select
					id="status-step-next"
					v-model="local.nextStatus"
					class="status-step-panel__select"
					:disabled="readOnly"
					data-testid="status-step-next">
					<option value="">
						{{ t('dossiq', 'None') }}
					</option>
					<option v-for="other in otherStatuses" :key="other.id" :value="other.id">
						{{ other.name }}
					</option>
				</select>
			</div>

			<div class="status-step-panel__field">
				<label for="status-step-deadline">{{ t('dossiq', 'Deadline in this status (days)') }}</label>
				<input
					id="status-step-deadline"
					v-model.number="local.phaseTermDays"
					class="status-step-panel__input"
					type="number"
					min="0"
					:disabled="readOnly"
					data-testid="status-step-deadline">
			</div>
		</div>

		<div class="status-step-panel__footer">
			<NcButton
				v-if="!readOnly"
				variant="tertiary"
				data-testid="status-step-delete"
				@click="$emit('delete', status.id)">
				{{ t('dossiq', 'Delete step') }}
			</NcButton>
			<NcButton variant="primary" data-testid="status-step-done" @click="done">
				{{ t('dossiq', 'Done') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'StatusStepPanel',
	components: { NcButton },

	props: {
		status: {
			type: Object,
			required: true,
		},

		steps: {
			type: Array,
			default: () => [],
		},

		statuses: {
			type: Array,
			default: () => [],
		},

		nextStatus: {
			type: String,
			default: '',
		},

		readOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update', 'delete', 'close'],

	data() {
		return { local: this.fromProps() }
	},

	computed: {
		/**
		 * @return {Array<object>} The statuses this one can move on to.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#scenario-a-status-step-opens-its-panel
		 */
		otherStatuses() {
			return this.statuses.filter((s) => s.id !== this.status.id)
		},
	},

	watch: {
		status() {
			this.local = this.fromProps()
		},

		nextStatus() {
			this.local = this.fromProps()
		},
	},

	methods: {
		/**
		 * The panel's working copy of the status.
		 *
		 * @return {{name: string, publicLabel: string, nextStatus: string, phaseTermDays: (number|null)}} The fields.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#scenario-a-status-step-opens-its-panel
		 */
		fromProps() {
			const days = this.status.phaseTermDays
			return {
				name: this.status.name || '',
				publicLabel: this.status.publicLabel || '',
				nextStatus: this.nextStatus || '',
				phaseTermDays: typeof days === 'number' ? days : null,
			}
		},

		/**
		 * Hand the changed fields to the editor and close; a published flow
		 * only closes.
		 *
		 * @return {void}
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-the-step-panel-follows-the-board-in-both-views
		 */
		done() {
			if (!this.readOnly) {
				const days = this.local.phaseTermDays
				this.$emit('update', {
					status: {
						...this.status,
						name: this.local.name.trim() || this.status.name,
						publicLabel: this.local.publicLabel.trim(),
						phaseTermDays: typeof days === 'number' && days >= 0 ? days : null,
					},
					nextStatus: this.local.nextStatus || null,
				})
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
@use './_configPanel.scss' as panel;

@include panel.config-panel(status-step-panel, 360px);

.status-step-panel__steps {
	margin: 0;
	padding-inline-start: 18px;
}

.status-step-panel__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.status-step-panel__footer {
	border-top: 1px solid var(--color-border);
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	padding: 12px 16px;
}
</style>

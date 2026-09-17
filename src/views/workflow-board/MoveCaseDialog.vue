<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Move one case to another status, from the workflow board.

	A SEARCHABLE DROPDOWN, not a list. The control this replaces was an actions
	menu holding every board column, and a board column exists per status NAME
	across every case type on the instance — two hundred entries on a real
	register. A list of the offered statuses would be far shorter but is still
	a list that can grow, so the choice is a select the handler can type into.

	Presentational, like the other dialogs in this app: it renders the targets
	it is handed and emits the one that was picked. The board owns the engine
	call, because the board owns the move.

	Spec: openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Move case')"
		data-testid="move-case-dialog"
		size="small"
		@closing="$emit('update:open', false)">
		<div class="move-case-dialog">
			<p v-if="caseTitle" class="move-case-dialog__case">
				{{ caseTitle }}
			</p>

			<NcLoadingIcon v-if="loading" :size="32" />

			<NcNoteCard v-else-if="error" type="error" data-testid="move-case-error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard
				v-else-if="targets.length === 0"
				type="info"
				data-testid="move-case-empty">
				{{ t('dossiq', 'This case has nowhere to go from here.') }}
			</NcNoteCard>

			<NcSelect
				v-else
				v-model="picked"
				data-testid="move-case-select"
				:options="targets"
				:selectable="(target) => !target.disabled"
				:inputLabel="t('dossiq', 'Move to')"
				:placeholder="t('dossiq', 'Pick a status')"
				label="label"
				trackBy="id">
				<template #option="target">
					<span>{{ target.label }}</span>
					<!-- The reason travels with the option it belongs to. A
						blocked status is offered rather than hidden, so it has
						to say why it cannot be picked. -->
					<small v-if="target.reason" class="move-case-dialog__reason">
						{{ target.reason }}
					</small>
				</template>
			</NcSelect>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('update:open', false)">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="move-case-confirm"
				:disabled="picked === null"
				@click="confirm">
				{{ t('dossiq', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

export default {
	name: 'MoveCaseDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The case being moved, for the heading line. */
		caseTitle: { type: String, default: '' },
		/**
		 * The statuses on offer, as `moveTargetsFromTransitions` shapes them.
		 *
		 * @type {Array<{id: string, label: string, disabled: boolean, reason: string}>}
		 */
		targets: { type: Array, default: () => [] },
		/** Whether the engine's answer is still on its way. */
		loading: { type: Boolean, default: false },
		/** Why the offer could not be read, empty when it could. */
		error: { type: String, default: '' },
	},

	emits: ['update:open', 'confirm'],

	data() {
		return {
			picked: null,
		}
	},

	methods: {
		/**
		 * Hand the picked column name to the board.
		 *
		 * @return {void}
		 */
		confirm() {
			if (this.picked === null) {
				return
			}
			this.$emit('confirm', this.picked.id)
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.move-case-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
	min-height: 120px;
}

.move-case-dialog__case {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.move-case-dialog__reason {
	display: block;
	color: var(--color-text-maxcontrast);
}
</style>

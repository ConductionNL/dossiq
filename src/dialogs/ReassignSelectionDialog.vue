<!--
  ReassignSelectionDialog. Give the selected cases to another handler.

  Opened by the Cases page's `reassign` bulk action. The selection travels in
  as a prop rather than being re-read here: an action that goes and finds out
  what was selected is one re-render away from acting on a different set than
  the user saw highlighted.

  THE REASON IS REQUIRED, and the button stays disabled without one.
  Reassigning four hundred statutory cases with nothing recorded about why is
  an audit finding waiting to happen, and a bulk act is exactly when the
  reason goes unwritten. OpenRegister's job does not know that, because it is
  a case policy: the action declares it, the hand-off enforces it, and this
  disabled button says so before the click rather than after it (D-3).

  The act itself runs as a bulk job. dossiq writes no loop over cases.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="large"
		data-testid="reassign-selection-dialog"
		@update:open="$emit('update:open', $event)">
		<div class="reassign">
			<template v-if="job === null">
				<BulkSelectionScope
					:selectedIds="selectedIds"
					:total="matchingTotal"
					:scope="scope"
					@update:scope="scope = $event" />

				<NcTextField
					v-model="handler"
					:label="t('dossiq', 'Give the cases to')"
					:placeholder="t('dossiq', 'User id of the receiving handler')"
					data-testid="reassign-selection-handler" />

				<NcTextArea
					v-model="justification"
					:label="t('dossiq', 'Why these cases are moving')"
					:placeholder="
						t('dossiq', 'Recorded with the act and readable afterwards')
					"
					data-testid="reassign-selection-justification" />

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>

			<BulkJobProgress
				v-else
				:job="job"
				:busy="busy"
				@update:job="job = $event"
				@finished="$emit('reassigned', $event)" />
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ job === null ? t('dossiq', 'Cancel') : t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="job === null"
				variant="primary"
				:disabled="!canRehearse"
				data-testid="reassign-selection-submit"
				@click="rehearse">
				{{ t('dossiq', 'See what would happen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import BulkJobProgress from '../components/bulk/BulkJobProgress.vue'
import BulkSelectionScope from '../components/bulk/BulkSelectionScope.vue'
import {
	ACTION_REASSIGN,
	previewBulkJob,
	readRefusal,
} from '../services/bulkJobApi.js'
import { buildSelection, SCOPE_PAGE } from '../utils/selectionScope.js'

export default {
	name: 'ReassignSelectionDialog',

	components: {
		BulkJobProgress,
		BulkSelectionScope,
		NcButton,
		NcDialog,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the dialog is open. */
		open: { type: Boolean, default: false },
		/** The case ids the user selected. */
		selectedIds: { type: Array, default: () => [] },
		/** How many cases match the list's current search, when that is known. */
		matchingTotal: { type: Number, default: 0 },
		/** The list's current filters, for a whole-result selection. */
		filters: { type: Object, default: () => ({}) },
	},

	emits: ['update:open', 'reassigned'],

	data() {
		return {
			handler: '',
			justification: '',
			scope: SCOPE_PAGE,
			busy: false,
			error: '',
			job: null,
		}
	},

	computed: {
		/**
		 * @return {string} The dialog title.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		title() {
			return t('dossiq', 'Give the cases to another handler')
		},

		/**
		 * Both fields are required, and the reason is the one that matters.
		 *
		 * @return {boolean} Whether the act can be rehearsed.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		canRehearse() {
			return (
				this.busy === false
				&& this.handler.trim() !== ''
				&& this.justification.trim() !== ''
			)
		},
	},

	methods: {
		t,

		/**
		 * Hand the redistribution to the job and read back what it would do.
		 *
		 * Nothing moves yet. The job comes back rehearsed, saying which cases
		 * it would move and which it would skip, and the commit is a second
		 * act inside the progress panel.
		 *
		 * @return {Promise<void>} Resolves when the job has been rehearsed.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		async rehearse() {
			this.busy = true
			this.error = ''

			try {
				this.job = await previewBulkJob({
					action: ACTION_REASSIGN,
					parameters: {
						toUser: this.handler.trim(),
						reason: this.justification.trim(),
					},
					selection: buildSelection({
						scope: this.scope,
						selectedIds: this.selectedIds,
						filters: this.filters,
					}),
					justification: this.justification.trim(),
				})
			} catch (e) {
				const { reason, message } = readRefusal(e)

				this.error =
					reason === 'justification-required'
						? t(
								'dossiq',
								'Say why these cases are moving. The reason is kept with the act.',
							)
						: message || t('dossiq', 'The cases could not be moved.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.reassign {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>

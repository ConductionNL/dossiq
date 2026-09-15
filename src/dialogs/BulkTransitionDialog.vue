<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	One bulk dialog, four gestures: transition, suspend, resume and extend term.

	WHAT CHANGED, because the shape of this file is the change. It used to
	preview and then execute against a dossiq endpoint that looped over the
	selection server-side. Closing the tab mid-run left four hundred statutory
	cases in a state nobody could read afterwards, and the only record of what
	was skipped was a summary that vanished with the dialog.

	Now the dialog composes the act, hands it to OpenRegister's bulk job, and
	renders what the job says. The job owns the walk, the progress, the per-row
	outcome, the cancel and the retry (D-1). dossiq declares what happens to one
	case, in lib/BulkAction/.

	TWO PHASES, and the seam between them is the rehearsal. Compose the act,
	read what it would do to every case, then commit. Nothing is written until
	the commit, so the skip list is read BEFORE the act rather than after it.

	THE REASON IS REQUIRED IN EVERY MODE. Suspending, resuming and extending are
	statutory acts (Awb 4:5 and 4:14) somebody accounts for later, and doing
	twenty at once is exactly when the justification goes unwritten. The action
	declares the requirement and the server enforces it; the disabled button is
	the half that says so before the click.

	@spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
-->
<template>
	<NcDialog :name="title" data-testid="bulk-dialog" size="large" @closing="onClose">
		<div class="bulk-transition-dialog">
			<NcLoadingIcon v-if="loadingTransitions" :size="32" />

			<template v-else>
				<p v-if="transitionsError" class="bulk-transition-dialog__error">
					{{ transitionsError }}
				</p>

				<template v-else-if="job === null">
					<BulkSelectionScope
						:selectedIds="caseIds"
						:total="matchingTotal"
						:scope="scope"
						@update:scope="scope = $event" />

					<NcSelect
						v-if="isTransition"
						v-model="selectedTransition"
						:options="transitionOptions"
						:placeholder="t('dossiq', 'Pick a status transition')"
						:inputLabel="t('dossiq', 'New status')"
						label="label"
						trackBy="id" />

					<NcTextArea
						v-model="reason"
						data-testid="bulk-reason"
						:label="t('dossiq', 'Reason, written onto every case')" />

					<NcTextField
						v-if="mode === 'suspend'"
						v-model="days"
						data-testid="bulk-days"
						type="number"
						:label="t('dossiq', 'Days the applicant is given')" />

					<div
						v-if="mode === 'extend'"
						class="bulk-transition-dialog__field">
						<label for="bulk-new-deadline">
							{{ t('dossiq', 'New deadline') }}
						</label>
						<input
							id="bulk-new-deadline"
							data-testid="bulk-new-deadline"
							type="date"
							:value="newEndDate"
							@input="newEndDate = $event.target.value">
					</div>

					<NcNoteCard v-if="refusal" type="error" data-testid="bulk-refusal">
						{{ refusal }}
					</NcNoteCard>

					<div class="bulk-transition-dialog__actions">
						<NcButton
							variant="primary"
							data-testid="bulk-rehearse"
							:disabled="!canRehearse"
							@click="rehearse">
							{{ t('dossiq', 'See what would happen') }}
						</NcButton>
						<NcButton :disabled="starting" @click="onClose">
							{{ t('dossiq', 'Cancel') }}
						</NcButton>
					</div>
				</template>

				<template v-else>
					<BulkJobProgress
						:job="job"
						:busy="starting"
						@update:job="job = $event"
						@finished="onFinished" />

					<div class="bulk-transition-dialog__actions">
						<NcButton data-testid="bulk-close" @click="onClose">
							{{ t('dossiq', 'Close') }}
						</NcButton>
					</div>
				</template>
			</template>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BulkJobProgress from '../components/bulk/BulkJobProgress.vue'
import BulkSelectionScope from '../components/bulk/BulkSelectionScope.vue'
import {
	ACTION_LIFECYCLE,
	ACTION_TRANSITION,
	previewBulkJob,
	readRefusal,
} from '../services/bulkJobApi.js'
import {
	isLifecycleGesture,
	lifecycleParameters,
	transitionParameters,
} from '../utils/bulkTransitionHelpers.js'
import { buildSelection, SCOPE_PAGE } from '../utils/selectionScope.js'

export default {
	name: 'BulkTransitionDialog',

	components: {
		BulkJobProgress,
		BulkSelectionScope,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The selected case ids the gesture applies to. */
		caseIds: {
			type: Array,
			required: true,
		},

		/**
		 * Which gesture this dialog is running: `transition` (the default, and
		 * what the workflow board opens), `suspend`, `resume` or `extend`. The
		 * mode picks the fields, the title and the job's parameters. The
		 * rehearsal, the per-case outcome and the skip list are deliberately
		 * identical across all four.
		 */
		mode: {
			type: String,
			default: 'transition',
			validator: (value) =>
				['transition', 'suspend', 'resume', 'extend'].includes(value),
		},

		/** How many cases match the list's current search, when that is known. */
		matchingTotal: {
			type: Number,
			default: 0,
		},

		/** The list's current filters, for a whole-result selection. */
		filters: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close', 'completed'],

	data() {
		return {
			loadingTransitions: true,
			transitionsError: null,
			transitions: [],
			selectedTransition: null,
			reason: '',
			days: '14',
			newEndDate: '',
			scope: SCOPE_PAGE,
			starting: false,
			refusal: '',
			job: null,
		}
	},

	computed: {
		/**
		 * NcSelect options built from the available transitions of the first
		 * selected case. Columns are homogeneous by design: the same status
		 * name means the same available transitions, so one case represents
		 * the column.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		transitionOptions() {
			return this.transitions.map((tr) => ({
				id: tr.id,
				label: (tr.label || tr.id),
			}))
		},

		/**
		 * @return {boolean} Whether this dialog is running a status transition.
		 */
		isTransition() {
			return this.mode === 'transition'
		},

		/**
		 * The title names the gesture rather than saying "change status" for
		 * all four. A suspend dialog headed "change status" is the kind of
		 * thing a reader clicks through and then cannot explain afterwards.
		 *
		 * @return {string} The title.
		 */
		title() {
			const count = this.caseIds.length

			if (this.mode === 'suspend') {
				return t('dossiq', 'Suspend {count} cases', { count })
			}

			if (this.mode === 'resume') {
				return t('dossiq', 'Resume {count} cases', { count })
			}

			if (this.mode === 'extend') {
				return t('dossiq', 'Extend the term of {count} cases', { count })
			}

			return t('dossiq', 'Change the status of {count} cases', { count })
		},

		/**
		 * The rehearsal is offered once the mode's own fields are filled.
		 *
		 * The reason gates every mode, including a transition, where it used to
		 * be an optional comment. Reading back twenty cases that moved for no
		 * recorded reason is the failure this prevents.
		 *
		 * @return {boolean} Whether the act can be rehearsed.
		 */
		canRehearse() {
			if (this.starting === true || this.reason.trim().length === 0) {
				return false
			}

			if (this.isTransition === true && this.selectedTransition === null) {
				return false
			}

			return (this.mode !== 'extend' || this.newEndDate !== '')
		},
	},

	/**
	 * Load what the mode needs before the reader can act.
	 *
	 * A lifecycle gesture has nothing to pick, so it is ready at once.
	 *
	 * @return {Promise<void>} Resolves when the dialog is usable.
	 */
	async mounted() {
		if (this.isTransition === false) {
			this.loadingTransitions = false
			return
		}

		await this.loadTransitions()
	},

	methods: {
		t,

		/**
		 * Load the available transitions of the first selected case, which is
		 * what fills the picker.
		 *
		 * @return {Promise<void>} Resolves when the picker is filled.
		 */
		async loadTransitions() {
			this.loadingTransitions = true
			this.transitionsError = null

			const caseId = this.caseIds[0]
			if (!caseId) {
				this.transitionsError = t('dossiq', 'No cases are selected.')
				this.loadingTransitions = false
				return
			}

			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/dossiq/api/case/'
							+ encodeURIComponent(caseId)
							+ '/available-transitions',
					),
				)
				this.transitions = (data?.transitions || [])
			} catch (err) {
				this.transitionsError = (err?.response?.data?.error || err.message)
			} finally {
				this.loadingTransitions = false
			}
		},

		/**
		 * Hand the act to the job and read back what it would do.
		 *
		 * Nothing is written by this. The job comes back rehearsed, with a row
		 * per case, and the commit lives in the progress panel underneath.
		 *
		 * @return {Promise<void>} Resolves when the job has been rehearsed.
		 */
		async rehearse() {
			this.starting = true
			this.refusal = ''

			try {
				this.job = await previewBulkJob({
					action: (isLifecycleGesture(this.mode) ? ACTION_LIFECYCLE : ACTION_TRANSITION),
					parameters: this.parameters(),
					selection: buildSelection({
						scope: this.scope,
						selectedIds: this.caseIds,
						filters: this.filters,
					}),
					justification: this.reason.trim(),
				})
			} catch (err) {
				this.refusal = this.refusalSentence(err)
			} finally {
				this.starting = false
			}
		},

		/**
		 * The parameters the chosen act needs.
		 *
		 * @return {object} The job parameters.
		 */
		parameters() {
			if (isLifecycleGesture(this.mode) === true) {
				return lifecycleParameters(this.mode, {
					reason: this.reason,
					days: this.days,
					newEndDate: this.newEndDate,
				})
			}

			return transitionParameters(this.selectedTransition.id, this.reason.trim())
		},

		/**
		 * A refusal, in the words a handler can act on.
		 *
		 * The ceiling and the case-type-version refusals get their own
		 * sentence because both are recoverable by changing the selection, and
		 * "the request was refused" tells nobody which way to change it.
		 *
		 * @param {object} err The axios error.
		 *
		 * @return {string} What to show.
		 */
		refusalSentence(err) {
			const { reason, message, details } = readRefusal(err)

			if (reason === 'case-type-versions') {
				return t(
					'dossiq',
					'These cases run on {versions} versions of {caseType}. A field means something different on each, so pick one version and try again.',
					{
						versions: (details.versions || []).join(' and '),
						caseType: (details.caseType || ''),
					},
				)
			}

			if (reason === 'ceiling') {
				return t('dossiq', 'This act takes at most {ceiling} cases at a time, and you selected {count}.', {
					ceiling: (details.ceiling || 0),
					count: (details.count || 0),
				})
			}

			return (message || err?.message || t('dossiq', 'The act could not be started.'))
		},

		/**
		 * Tell the list the cases have moved, once the job has stopped.
		 *
		 * @return {void}
		 */
		onFinished() {
			this.$emit('completed', this.job)
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.bulk-transition-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.bulk-transition-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bulk-transition-dialog__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.bulk-transition-dialog__error {
	color: var(--color-error);
}
</style>

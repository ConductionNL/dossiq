<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	One bulk dialog, four gestures — Transition, Suspend, Resume and Extend
	term. Pick what applies, give a reason, auto-preview the per-case
	ready/blocked outcome (read-only), then execute it across every selected
	case. The single-case write paths remain the only write paths:
	`StatusTransitionService::execute()` for a transition, and
	`CaseLifecycleService`'s suspend / resume / extend for the other three.
	This dialog only calls the bulk endpoints, which loop those once per
	case, so every guard and every automatic action a single case gets, a
	bulk case gets too. Partial failures are always shown, never silently
	dropped.

	ONE DIALOG RATHER THAN FOUR, because the preview, the per-case summary
	and the failure reporting are the part worth keeping equal. Four dialogs
	would be four places for "8 of 10 succeeded" to be phrased differently,
	and the whole point of the preview is that a reader can trust it says the
	same thing every time.

	THE REASON IS REQUIRED IN EVERY MODE, and Execute stays disabled until
	there is one. Suspending, resuming and extending are statutory acts
	(Awb 4:5 and 4:14) that someone has to justify later, and doing twenty at
	once is precisely when the justification goes unwritten. The server
	refuses a reasonless lifecycle batch as well; the disabled button is the
	half that says so before the click rather than after it.

	Spec: openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
	Spec: openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
-->
<template>
	<NcDialog :name="title" data-testid="bulk-dialog" @closing="onClose">
		<div class="bulk-transition-dialog">
			<NcLoadingIcon v-if="loadingTransitions" :size="32" />

			<template v-else>
				<p v-if="transitionsError" class="bulk-transition-dialog__error">
					{{ transitionsError }}
				</p>

				<template v-else>
					<NcSelect
						v-if="isTransition"
						v-model="selectedTransition"
						:options="transitionOptions"
						:placeholder="t('dossiq', 'Select a status transition')"
						:inputLabel="t('dossiq', 'New status')"
						:disabled="executed"
						label="label"
						trackBy="id" />

					<NcTextArea
						v-model="reason"
						data-testid="bulk-reason"
						:label="t('dossiq', 'Reason (applied to every case)')"
						:disabled="executed" />

					<NcTextField
						v-if="mode === 'suspend'"
						v-model="days"
						data-testid="bulk-days"
						type="number"
						:label="t('dossiq', 'Days the applicant is given')"
						:disabled="executed" />

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
							:disabled="executed"
							@input="newEndDate = $event.target.value" />
					</div>

					<NcLoadingIcon
						v-if="previewLoading"
						:size="24"
						class="bulk-transition-dialog__preview-loading" />

					<div
						v-else-if="previewSummary"
						data-testid="bulk-preview-summary"
						class="bulk-transition-dialog__summary">
						<p>
							{{
								t(
									'dossiq',
									'{ready} of {total} cases are ready to transition.',
									{
										ready: previewSummary.counts.ready || 0,
										total: previewSummary.total,
									},
								)
							}}
						</p>
						<ul
							v-if="previewSummary.failed.length > 0"
							class="bulk-transition-dialog__reasons">
							<li
								v-for="item in previewSummary.failed"
								:key="item.caseId">
								{{ item.caseId }}: {{ reasonText(item) }}
							</li>
						</ul>
					</div>

					<p v-if="error" class="bulk-transition-dialog__error">
						{{ error }}
					</p>

					<div
						v-if="executeSummary"
						data-testid="bulk-execute-summary"
						class="bulk-transition-dialog__summary">
						<p>
							{{
								t(
									'dossiq',
									'{succeeded} of {total} cases were transitioned.',
									{
										succeeded:
											executeSummary.counts.succeeded || 0,
										total: executeSummary.total,
									},
								)
							}}
						</p>
						<ul
							v-if="executeSummary.failed.length > 0"
							class="bulk-transition-dialog__reasons">
							<li
								v-for="item in executeSummary.failed"
								:key="item.caseId">
								{{ item.caseId }}: {{ reasonText(item) }}
							</li>
						</ul>
					</div>

					<div class="bulk-transition-dialog__actions">
						<NcButton
							v-if="!executed"
							data-testid="bulk-execute"
							:disabled="!canExecute"
							@click="onExecute">
							{{ t('dossiq', 'Execute') }}
						</NcButton>
						<NcButton
							type="secondary"
							:disabled="executing"
							@click="onClose">
							{{
								executed
									? t('dossiq', 'Close')
									: t('dossiq', 'Cancel')
							}}
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	buildExecutePayload,
	buildLifecycleExecutePayload,
	buildLifecyclePreviewPayload,
	buildPreviewPayload,
	isLifecycleGesture,
	summarizeResults,
} from '../utils/bulkTransitionHelpers.js'

export default {
	name: 'BulkTransitionDialog',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcTextArea,
		NcTextField,
		NcLoadingIcon,
	},

	props: {
		/** The selected case ids the gesture applies to. */
		caseIds: {
			type: Array,
			required: true,
		},

		/**
		 * Which gesture this dialog is running: `transition` (the default,
		 * and what the workflow board opens), `suspend`, `resume` or
		 * `extend`. The mode picks the fields, the title, the request
		 * payload and the phrasing of the summary; everything else — the
		 * preview, the per-case result list, the partial-failure reporting —
		 * is deliberately identical across all four.
		 */
		mode: {
			type: String,
			default: 'transition',
			validator: (value) =>
				['transition', 'suspend', 'resume', 'extend'].includes(value),
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
			previewLoading: false,
			previewSummary: null,
			executing: false,
			executed: false,
			executeSummary: null,
			error: null,
		}
	},

	computed: {
		/**
		 * NcSelect options built from the available transitions of the first
		 * selected case — columns are homogeneous by design (same status name
		 * ⇒ same available transitions), so one case represents the column.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		transitionOptions() {
			return this.transitions.map((tr) => ({
				id: tr.id,
				label: tr.label || tr.id,
			}))
		},

		/**
		 * Whether this dialog is running a status transition.
		 *
		 * @return {boolean}
		 */
		isTransition() {
			return this.mode === 'transition'
		},

		/**
		 * The dialog's title, which names the gesture rather than saying
		 * "change status" for all four — a Suspend dialog headed "Change
		 * status" is the kind of thing a reader clicks through and then
		 * cannot explain afterwards.
		 *
		 * @return {string}
		 */
		title() {
			const count = this.caseIds.length
			if (this.mode === 'suspend') {
				return this.t('dossiq', 'Suspend {count} cases', { count })
			}

			if (this.mode === 'resume') {
				return this.t('dossiq', 'Resume {count} cases', { count })
			}

			if (this.mode === 'extend') {
				return this.t('dossiq', 'Extend the term of {count} cases', {
					count,
				})
			}

			return this.t('dossiq', 'Change status for {count} cases', { count })
		},

		/**
		 * Execute is enabled once the mode's own fields are filled, the
		 * preview has come back, and at least one case is ready.
		 *
		 * The reason gates every mode, including Transition, where it used to
		 * be an optional comment. Reading back a batch of twenty cases that
		 * moved for no recorded reason is the failure this prevents.
		 *
		 * @return {boolean}
		 */
		canExecute() {
			if (this.executing || !this.previewSummary) return false
			if (this.reason.trim().length === 0) return false
			if (this.isTransition && !this.selectedTransition) return false
			if (this.mode === 'extend' && !this.newEndDate) return false
			return (this.previewSummary.counts.ready || 0) > 0
		},
	},

	watch: {
		selectedTransition(newVal) {
			this.previewSummary = null
			if (newVal) {
				this.runPreview()
			}
		},
	},

	async mounted() {
		if (this.isTransition) {
			await this.loadTransitions()
			return
		}

		// A lifecycle gesture has nothing to pick, so the preview runs on
		// open: the reader sees which of the selection the gesture applies
		// to before deciding whether to type a reason at all.
		this.loadingTransitions = false
		await this.runPreview()
	},

	methods: {
		t,
		/**
		 * Load the available transitions for the first selected case — used to
		 * populate the transition picker (the column's available transitions).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-column-scoped-selection-on-the-workflow-board
		 */
		async loadTransitions() {
			this.loadingTransitions = true
			this.transitionsError = null
			const caseId = this.caseIds[0]
			if (!caseId) {
				this.transitionsError = this.t('dossiq', 'No cases selected.')
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
				this.transitions = data?.transitions || []
			} catch (err) {
				this.transitionsError = err?.response?.data?.error || err.message
			} finally {
				this.loadingTransitions = false
			}
		},

		/**
		 * Run a read-only bulk preview for the currently selected transition.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-preview-before-execute
		 */
		async runPreview() {
			this.previewLoading = true
			this.error = null
			try {
				const payload = isLifecycleGesture(this.mode)
					? buildLifecyclePreviewPayload(
							{ caseIds: this.caseIds },
							this.mode,
						)
					: buildPreviewPayload(
							{ caseIds: this.caseIds },
							this.selectedTransition.id,
						)
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/cases/bulk-transition/preview'),
					payload,
				)
				this.previewSummary = summarizeResults(data?.results || {})
			} catch (err) {
				this.error = err?.response?.data?.error || err.message
			} finally {
				this.previewLoading = false
			}
		},

		/**
		 * Execute the bulk transition and render per-case results.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-bulk-transitions-go-through-the-engine
		 */
		async onExecute() {
			if (!this.canExecute) return
			this.executing = true
			this.error = null
			try {
				const payload = isLifecycleGesture(this.mode)
					? buildLifecycleExecutePayload(
							{ caseIds: this.caseIds },
							this.mode,
							{
								reason: this.reason,
								days: this.days,
								newEndDate: this.newEndDate,
							},
						)
					: buildExecutePayload(
							{ caseIds: this.caseIds },
							this.selectedTransition.id,
							this.reason,
						)
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/cases/bulk-transition/execute'),
					payload,
				)
				this.executeSummary = summarizeResults(data?.results || {})
				this.executed = true
			} catch (err) {
				this.error = err?.response?.data?.error || err.message
			} finally {
				this.executing = false
			}
		},

		/**
		 * Close the dialog — emits `completed` when an execute has run (so the
		 * board refreshes and clears the selection), otherwise `close`.
		 *
		 * @return {void}
		 */
		onClose() {
			this.$emit(this.executed ? 'completed' : 'close')
		},

		/**
		 * Render a human-readable reason string for a blocked/failed/error entry.
		 *
		 * @param {{status: string, reasons: Array}} item The summarized result entry.
		 * @return {string}
		 */
		reasonText(item) {
			if (!item.reasons || item.reasons.length === 0) return item.status
			return item.reasons
				.map(
					(r) => r?.failureMessage || r?.message || r?.type || item.status,
				)
				.join(', ')
		},
	},
}
</script>

<style scoped>
.bulk-transition-dialog {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bulk-transition-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bulk-transition-dialog__actions {
	display: flex;
	gap: 8px;
}

.bulk-transition-dialog__summary {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.bulk-transition-dialog__reasons {
	margin: 8px 0 0;
	padding-left: 20px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.bulk-transition-dialog__error {
	color: var(--color-error);
}

.bulk-transition-dialog__preview-loading {
	margin: 8px auto;
}
</style>

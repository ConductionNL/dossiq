<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Bulk status-transition dialog — pick one of the column's available
	transitions, add an optional comment, auto-preview the per-case
	ready/blocked outcome (read-only), then execute it across every selected
	case. The engine (`StatusTransitionService::execute()`) remains the only
	write path; this dialog only calls the bulk-transition endpoints, which
	loop that engine once per case. Partial failures are always shown, never
	silently dropped.

	Spec: openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
-->
<template>
	<NcDialog
		:name="
			t('dossiq', 'Change status for {count} cases', {
				count: caseIds.length,
			})
		"
		@closing="onClose">
		<div class="bulk-transition-dialog">
			<NcLoadingIcon v-if="loadingTransitions" :size="32" />

			<template v-else>
				<p v-if="transitionsError" class="bulk-transition-dialog__error">
					{{ transitionsError }}
				</p>

				<template v-else>
					<NcSelect
						v-model="selectedTransition"
						:options="transitionOptions"
						:placeholder="t('dossiq', 'Select a status transition')"
						:inputLabel="t('dossiq', 'New status')"
						:disabled="executed"
						label="label"
						trackBy="id" />

					<NcTextArea
						v-model="comment"
						:label="
							t('dossiq', 'Comment (optional, applied to every case)')
						"
						:disabled="executed" />

					<NcLoadingIcon
						v-if="previewLoading"
						:size="24"
						class="bulk-transition-dialog__preview-loading" />

					<div
						v-else-if="previewSummary"
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
import {
	buildExecutePayload,
	buildPreviewPayload,
	summarizeResults,
} from '../utils/bulkTransitionHelpers.js'

export default {
	name: 'BulkTransitionDialog',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcTextArea,
		NcLoadingIcon,
	},

	props: {
		/** The selected case ids the transition applies to. */
		caseIds: {
			type: Array,
			required: true,
		},
	},

	emits: ['close', 'completed'],
	data() {
		return {
			loadingTransitions: true,
			transitionsError: null,
			transitions: [],
			selectedTransition: null,
			comment: '',
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
		 * Execute is enabled once a transition is selected, preview has
		 * completed, and at least one case is ready.
		 *
		 * @return {boolean}
		 */
		canExecute() {
			if (this.executing || !this.selectedTransition || !this.previewSummary)
				return false
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
		await this.loadTransitions()
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
				const payload = buildPreviewPayload(
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
			if (!this.selectedTransition) return
			this.executing = true
			this.error = null
			try {
				const payload = buildExecutePayload(
					{ caseIds: this.caseIds },
					this.selectedTransition.id,
					this.comment,
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

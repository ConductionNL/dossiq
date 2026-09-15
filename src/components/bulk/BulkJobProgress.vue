<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	What a bulk act did, case by case.

	The progress bar is the easy half. The list underneath it is the feature:
	twelve of four hundred did not move, here they are, here is why each one
	refused. A count in a toast that disappears is not that, and a handler who
	reads "388 of 400 succeeded" and closes the tab has learned nothing they
	can act on (D-2).

	SKIPPED AND REFUSED ARE SEPARATE, and stay separate on screen. Skipped is
	"the act does not apply to this case". Refused is "you may not write this
	case". Collapsing them would hide a permission problem inside a business
	outcome, and a coordinator would read "12 skipped" and move on.

	NOTHING HERE POLLS A LOOP OF ITS OWN. The job walks the cases in the
	background, so this reads its position, and closing the tab stops the
	reading and not the act.

	@spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
-->
<template>
	<div class="bulk-job" data-testid="bulk-job-progress">
		<p class="bulk-job__state" data-testid="bulk-job-state">
			{{ stateSentence }}
		</p>

		<NcProgressBar
			v-if="showsProgress"
			:value="percentage"
			size="medium"
			data-testid="bulk-job-bar" />

		<div v-if="hasOutcomes" class="bulk-job__counts">
			<NcButton
				v-for="entry in outcomeTabs"
				:key="entry.outcome"
				:variant="entry.outcome === openOutcome ? 'primary' : 'tertiary'"
				:data-testid="`bulk-job-count-${entry.outcome}`"
				@click="open(entry.outcome)">
				{{ entry.label }}
			</NcButton>
		</div>

		<div v-if="openOutcome" class="bulk-job__rows" data-testid="bulk-job-rows">
			<NcLoadingIcon v-if="loadingRows" :size="24" />

			<p v-else-if="rows.length === 0" class="bulk-job__empty">
				{{ t('dossiq', 'No cases with this outcome.') }}
			</p>

			<ul v-else class="bulk-job__list">
				<li v-for="row in rows" :key="row.id" class="bulk-job__row">
					<span class="bulk-job__case">{{ row.objectUuid }}</span>
					<span class="bulk-job__reason">{{ row.reason || t('dossiq', 'No reason recorded.') }}</span>
				</li>
			</ul>

			<NcButton
				v-if="moreRows"
				variant="tertiary"
				data-testid="bulk-job-more"
				@click="loadMore">
				{{ t('dossiq', 'Show more') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="bulk-job__actions">
			<NcButton
				v-if="isPreviewed"
				variant="primary"
				:disabled="busy"
				data-testid="bulk-job-commit"
				@click="commit">
				{{ commitLabel }}
			</NcButton>

			<NcButton
				v-if="isRunning"
				:disabled="busy"
				data-testid="bulk-job-cancel"
				@click="cancel">
				{{ t('dossiq', 'Stop this act') }}
			</NcButton>

			<NcButton
				v-if="canRetry"
				:disabled="busy"
				data-testid="bulk-job-retry"
				@click="retry">
				{{ t('dossiq', 'Run the cases it did not reach') }}
			</NcButton>

			<NcButton
				v-if="canDownload"
				variant="tertiary"
				:href="reportUrl"
				data-testid="bulk-job-download">
				{{ t('dossiq', 'Download the report') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import {
	bulkJobReportUrl,
	cancelBulkJob,
	commitBulkJob,
	fetchBulkJob,
	fetchBulkJobMembers,
	isFinished,
	retryBulkJob,
} from '../../services/bulkJobApi.js'

/** How often the job's position is re-read while it runs, in milliseconds. */
const POLL_INTERVAL = 2000

/** How many rows one page of the outcome list holds. */
const PAGE_SIZE = 25

export default {
	name: 'BulkJobProgress',

	components: { NcButton, NcLoadingIcon, NcNoteCard, NcProgressBar },

	props: {
		/** The job as it was last read. The parent owns the first read. */
		job: { type: Object, required: true },

		/** Whether the parent is mid-request and the buttons should wait. */
		busy: { type: Boolean, default: false },
	},

	emits: ['update:job', 'finished'],

	data() {
		return {
			openOutcome: '',
			rows: [],
			rowTotal: 0,
			loadingRows: false,
			error: '',
			timer: null,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the job is rehearsed but not yet committed.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		isPreviewed() {
			return this.job.state === 'previewed'
		},

		/**
		 * @return {boolean} Whether the job is still walking cases.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		isRunning() {
			return ['running', 'cancelling'].includes(String(this.job.state))
		},

		/**
		 * A stopped job with cases it never reached can be run again. A
		 * finished one cannot, and neither can one still moving.
		 *
		 * @return {boolean} Whether running the rest is on offer.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		canRetry() {
			return (
				['cancelled', 'failed'].includes(String(this.job.state))
				&& Number(this.job.processed || 0) < Number(this.job.total || 0)
			)
		},

		/**
		 * @return {boolean} Whether the report is worth offering yet.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		canDownload() {
			return Number(this.job.total || 0) > 0
		},

		/**
		 * @return {string} Where the report downloads from.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		reportUrl() {
			return bulkJobReportUrl(this.job.id)
		},

		/**
		 * @return {boolean} Whether a bar says anything useful.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		showsProgress() {
			return (this.isRunning || isFinished(this.job)) && Number(this.job.total || 0) > 0
		},

		/**
		 * @return {number} How far along the job is, as a percentage.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		percentage() {
			const total = Number(this.job.total || 0)
			if (total === 0) {
				return 0
			}

			return Math.round((Number(this.job.processed || 0) / total) * 100)
		},

		/**
		 * The job's position, in a sentence rather than a status code.
		 *
		 * `previewed` reads differently from every other state on purpose:
		 * it is the only one where nothing has been written, and a coordinator
		 * about to commit needs to know that before they read the counts.
		 *
		 * @return {string} What is happening.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		stateSentence() {
			const total = Number(this.job.total || 0)
			const processed = Number(this.job.processed || 0)

			switch (String(this.job.state)) {
			case 'previewed':
				return t('dossiq', 'Nothing has been written yet. This is what would happen to {total} cases.', { total })
			case 'running':
				return t('dossiq', 'Running. {processed} of {total} cases done.', { processed, total })
			case 'cancelling':
				return t('dossiq', 'Stopping after the case it is on.')
			case 'cancelled':
				return t('dossiq', 'Stopped. {processed} of {total} cases were done first.', { processed, total })
			case 'failed':
				return t('dossiq', 'The act stopped on an error after {processed} of {total} cases.', { processed, total })
			default:
				return t('dossiq', 'Finished. {processed} of {total} cases done.', { processed, total })
			}
		},

		/**
		 * @return {boolean} Whether any case has an outcome yet.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		hasOutcomes() {
			return Object.values(this.counts).some((count) => count > 0)
		},

		/**
		 * @return {object} The four counts, defaulted so a missing key is zero.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		counts() {
			const counts = (this.job.counts || {})

			return {
				applied: Number(counts.applied || 0),
				skipped: Number(counts.skipped || 0),
				refused: Number(counts.refused || 0),
				failed: Number(counts.failed || 0),
			}
		},

		/**
		 * The four outcomes as openable counts.
		 *
		 * Every outcome is offered, including zero ones: a handler looking for
		 * the skip list should find it saying "none" rather than not find it.
		 *
		 * @return {Array<{outcome: string, label: string}>} The tabs.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		outcomeTabs() {
			return [
				{ outcome: 'applied', label: t('dossiq', '{count} changed', { count: this.counts.applied }) },
				{ outcome: 'skipped', label: t('dossiq', '{count} skipped', { count: this.counts.skipped }) },
				{ outcome: 'refused', label: t('dossiq', '{count} you may not write', { count: this.counts.refused }) },
				{ outcome: 'failed', label: t('dossiq', '{count} failed', { count: this.counts.failed }) },
			]
		},

		/**
		 * What the commit button says, given exactly what it will do.
		 *
		 * The number is on the button rather than only in the sentence above
		 * it, because the button is the last thing read before four hundred
		 * cases change.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		commitLabel() {
			return t('dossiq', 'Apply to {total} cases', { total: Number(this.job.total || 0) })
		},

		/**
		 * @return {boolean} Whether the open outcome has rows past this page.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		moreRows() {
			return this.rows.length < this.rowTotal
		},
	},

	watch: {
		/**
		 * Follow the job while it moves, and stop following when it stops.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		job: {
			immediate: true,

			/**
			 * Start or stop following, according to where the job now is.
			 *
			 * @return {void}
			 *
			 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
			 */
			handler() {
				if (isFinished(this.job) === true) {
					this.stopPolling()
					this.$emit('finished', this.job)
					return
				}

				if (this.isRunning === true) {
					this.startPolling()
				}
			},
		},
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		t,

		/**
		 * Re-read the job's position until it stops moving.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		startPolling() {
			if (this.timer !== null) {
				return
			}

			this.timer = setInterval(async () => {
				try {
					const job = await fetchBulkJob(this.job.id)
					this.$emit('update:job', job)
					if (this.openOutcome) {
						await this.open(this.openOutcome, { reset: true })
					}
				} catch {
					this.stopPolling()
					this.error = t('dossiq', 'The act is still running, but its progress could not be read.')
				}
			}, POLL_INTERVAL)
		},

		/**
		 * Stop re-reading.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		stopPolling() {
			if (this.timer !== null) {
				clearInterval(this.timer)
				this.timer = null
			}
		},

		/**
		 * Open one outcome's list of cases.
		 *
		 * @param {string}  outcome         applied, skipped, refused or failed.
		 * @param {object}  [options]       How to open it.
		 * @param {boolean} [options.reset] Whether to discard the rows already shown.
		 *
		 * @return {Promise<void>} Resolves when the page has been read.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		async open(outcome, { reset = false } = {}) {
			if (this.openOutcome === outcome && reset === false) {
				this.openOutcome = ''
				this.rows = []
				return
			}

			this.openOutcome = outcome
			this.loadingRows = (reset === false)
			this.error = ''

			try {
				const page = await fetchBulkJobMembers(this.job.id, { outcome, limit: PAGE_SIZE, offset: 0 })
				this.rows = page.results
				this.rowTotal = page.total
			} catch {
				this.error = t('dossiq', 'The cases with this outcome could not be read.')
			} finally {
				this.loadingRows = false
			}
		},

		/**
		 * Read the next page of the open outcome.
		 *
		 * @return {Promise<void>} Resolves when the page has been added.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		async loadMore() {
			this.loadingRows = true
			try {
				const page = await fetchBulkJobMembers(this.job.id, {
					outcome: this.openOutcome,
					limit: PAGE_SIZE,
					offset: this.rows.length,
				})
				this.rows = this.rows.concat(page.results)
				this.rowTotal = page.total
			} catch {
				this.error = t('dossiq', 'The rest of the list could not be read.')
			} finally {
				this.loadingRows = false
			}
		},

		/**
		 * Write the act. This is the only button here that changes a case.
		 *
		 * @return {Promise<void>} Resolves when the job is running.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		async commit() {
			this.error = ''
			try {
				this.$emit('update:job', await commitBulkJob(this.job.id))
			} catch (e) {
				this.error = this.messageOf(e, t('dossiq', 'The act could not be started.'))
			}
		},

		/**
		 * Stop the job before the next case.
		 *
		 * @return {Promise<void>} Resolves when the job is stopping.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		async cancel() {
			this.error = ''
			try {
				this.$emit('update:job', await cancelBulkJob(this.job.id))
			} catch (e) {
				this.error = this.messageOf(e, t('dossiq', 'The act could not be stopped.'))
			}
		},

		/**
		 * Run the cases a stopped job never reached.
		 *
		 * @return {Promise<void>} Resolves when the job is running again.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		async retry() {
			this.error = ''
			try {
				this.$emit('update:job', await retryBulkJob(this.job.id))
			} catch (e) {
				this.error = this.messageOf(e, t('dossiq', 'The rest of the cases could not be run.'))
			}
		},

		/**
		 * The server's own sentence when it wrote one, and a fallback when it
		 * did not.
		 *
		 * @param {object} error    The axios error.
		 * @param {string} fallback What to say when the server said nothing.
		 *
		 * @return {string} The message.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		messageOf(error, fallback) {
			return String(error?.response?.data?.error || fallback)
		},
	},
}
</script>

<style scoped>
.bulk-job {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bulk-job__state {
	color: var(--color-text-maxcontrast);
}

.bulk-job__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.bulk-job__rows {
	max-height: 260px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px;
}

.bulk-job__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.bulk-job__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.bulk-job__case {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.bulk-job__reason,
.bulk-job__empty {
	color: var(--color-text-maxcontrast);
}

.bulk-job__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>

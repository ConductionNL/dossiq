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

	@spec openspec/specs/case-management/spec.md
-->
<template>
	<CnDialog
		:name="title"
		:eyebrow="eyebrow"
		data-testid="bulk-dialog"
		size="large"
		@closing="onClose">
		<div class="bulk-transition-dialog">
			<NcLoadingIcon v-if="loadingTransitions" :size="32" />

			<template v-else>
				<p v-if="transitionsError" class="bulk-transition-dialog__error">
					{{ transitionsError }}
				</p>

				<template v-else-if="job === null">
					<CnSelectionScope
						:labels="scopeLabels"
						:selectedIds="caseIds"
						:total="matchingTotal"
						:scope="scope"
						@update:scope="scope = $event" />

					<div class="bulk-transition-dialog__fields">
						<div v-if="isTransition" class="bulk-transition-dialog__field">
							<NcSelect
								v-model="selectedTransition"
								:options="transitionOptions"
								:placeholder="t('dossiq', 'Pick a status transition')"
								:inputLabel="t('dossiq', 'New status')"
								label="label"
								trackBy="id" />
							<span class="bulk-transition-dialog__help">
								{{ t('dossiq', 'Only statuses every chosen case may get from its current status.') }}
							</span>
						</div>
						<div class="bulk-transition-dialog__field">
							<NcTextField
								v-model="reason"
								data-testid="bulk-reason"
								:label="t('dossiq', 'Reason')" />
							<span class="bulk-transition-dialog__help">
								{{ t('dossiq', 'Goes into the history of every case.') }}
							</span>
						</div>
					</div>

					<div
v-if="showsChanges"
						class="bulk-transition-dialog__changes"
						data-testid="bulk-changes">
						<strong>{{ changesTitle }}</strong>
						<table class="bulk-transition-dialog__table">
							<thead>
								<tr>
									<th scope="col">
										{{ t('dossiq', 'Case') }}
									</th>
									<th scope="col">
										{{ t('dossiq', 'Now') }}
									</th>
									<th scope="col">
										{{ t('dossiq', 'Becomes') }}
									</th>
									<th scope="col">
										{{ t('dossiq', 'Term') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in changeRows" :key="row.id" data-testid="bulk-change-row">
									<td>
										<strong>{{ row.title }}</strong>
										<span class="bulk-transition-dialog__help">{{ row.identifier }}</span>
									</td>
									<td>{{ row.status }}</td>
									<td>{{ becomes }}</td>
									<td>{{ row.deadline }}</td>
								</tr>
							</tbody>
						</table>
						<span class="bulk-transition-dialog__help">
							{{ t('dossiq', 'The term does not move along: the final date is on the case and follows the statutory term, not the status.') }}
						</span>
					</div>

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
							@input="newEndDate = $event.target.value" />
					</div>

					<NcNoteCard
						v-if="refusal"
						type="error"
						data-testid="bulk-refusal">
						{{ refusal }}
					</NcNoteCard>

					<div class="bulk-transition-dialog__actions">
						<NcButton :disabled="starting" @click="onClose">
							{{ t('dossiq', 'Cancel') }}
						</NcButton>
						<NcButton
							variant="primary"
							data-testid="bulk-rehearse"
							:disabled="!canRehearse"
							@click="rehearse">
							{{ t('dossiq', 'See what would happen') }}
						</NcButton>
					</div>
				</template>

				<template v-else-if="allChanged">
					<NcNoteCard type="success" data-testid="bulk-result">
						<strong>{{ resultTitle }}</strong>
						<p v-if="selectedTransition">
							{{ t('dossiq', 'The cases now stand at {status}. Each case page shows the new status and the reason in the history.', { status: selectedTransition.label }) }}
						</p>
					</NcNoteCard>
					<ul class="bulk-transition-dialog__result">
						<li v-for="row in changeRows" :key="row.id" data-testid="bulk-result-row">
							<span>{{ row.title }}</span>
							<span class="bulk-transition-dialog__done">{{ t('dossiq', 'Succeeded') }}</span>
						</li>
					</ul>
					<div class="bulk-transition-dialog__actions">
						<NcButton data-testid="bulk-close" @click="onClose">
							{{ t('dossiq', 'Close') }}
						</NcButton>
					</div>
				</template>

				<template v-else>
					<CnBulkJobProgress
						:job="job"
						:labels="jobLabels"
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
	</CnDialog>
</template>

<script>
import { CnBulkJobProgress, CnDialog, CnSelectionScope } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	ACTION_LIFECYCLE,
	ACTION_TRANSITION,
	previewBulkJob,
	readRefusal,
} from '../services/bulkJobApi.js'
import {
	changeRow,
	commonTransitions,
	isLifecycleGesture,
	lifecycleParameters,
	transitionParameters,
} from '../utils/bulkTransitionHelpers.js'
import { buildSelection, caseJobLabels, caseScopeLabels, SCOPE_PAGE } from '../utils/selectionScope.js'

/**
 * The refusals that get a sentence of their own, by reason: both are
 * recoverable by changing the selection.
 *
 * @type {Map<string, function(object): string>}
 */
const REFUSAL_SENTENCES = new Map([
	['case-type-versions', versionsRefusalSentence],
	['ceiling', ceilingRefusalSentence],
])

/**
 * @param {object} details The refusal details: `versions` and `caseType`.
 * @return {string} The sentence for a selection over several case type versions.
 */
function versionsRefusalSentence(details) {
	return t(
		'dossiq',
		'These cases run on versions {versions} of {caseType}. A field means something different on each, so pick one version and try again.',
		{
			versions: (details.versions || []).join(' and '),
			caseType: details.caseType || '',
		},
	)
}

/**
 * @param {object} details The refusal details: `ceiling` and `count`.
 * @return {string} The sentence for a selection over the ceiling.
 */
function ceilingRefusalSentence(details) {
	return t(
		'dossiq',
		'This act takes at most {ceiling} cases at a time, and you selected {count}.',
		{
			ceiling: details.ceiling || 0,
			count: details.count || 0,
		},
	)
}

export default {
	name: 'BulkTransitionDialog',

	components: {
		CnBulkJobProgress,
		CnDialog,
		CnSelectionScope,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
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
		 *
		 * @spec openspec/specs/case-management/spec.md
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
			changeRows: [],
			selectedTransition: null,
			reason: '',
			days: '14',
			newEndDate: '',
			scope: SCOPE_PAGE,
			jobLabels: caseJobLabels(),
			scopeLabels: caseScopeLabels(),
			starting: false,
			refusal: '',
			job: null,
		}
	},

	computed: {
		/**
		 * NcSelect options: the transitions every selected case offers.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		transitionOptions() {
			return this.transitions.map((tr) => ({
				id: tr.id,
				label: tr.label || tr.id,
			}))
		},

		/**
		 * @return {boolean} Whether this dialog is running a status transition.
		 *
		 * @spec openspec/specs/case-management/spec.md
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
		 *
		 * @spec openspec/specs/case-management/spec.md
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

			return t('dossiq', 'Other status')
		},

		/**
		 * The line above a transition's title.
		 *
		 * @return {string} The eyebrow, empty for the other gestures.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
		 */
		eyebrow() {
			return this.isTransition
				? t('dossiq', '{count} cases selected · see first what changes', { count: this.caseIds.length })
				: ''
		},

		/**
		 * Whether the table of changes speaks for the whole act: a transition
		 * on the selected rows, not on a result wider than the page.
		 *
		 * @return {boolean} Whether to show it.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
		 */
		showsChanges() {
			return this.isTransition && this.scope === SCOPE_PAGE && this.changeRows.length > 0
		},

		/**
		 * @return {string} The heading over the table of changes.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
		 */
		changesTitle() {
			return t('dossiq', 'What changes: {count} cases', { count: this.changeRows.length })
		},

		/**
		 * @return {string} The status the cases get, or a dash until one is picked.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
		 */
		becomes() {
			return this.selectedTransition ? this.selectedTransition.label : '—'
		},

		/**
		 * Whether the job finished with every case changed.
		 *
		 * @return {boolean} True for the result panel.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-result-names-every-changed-case
		 */
		allChanged() {
			const job = this.job
			if (!this.showsChanges || !job || job.state !== 'completed') {
				return false
			}
			return (job.counts?.applied ?? 0) === job.total
		},

		/**
		 * @return {string} The heading of the result panel.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-result-names-every-changed-case
		 */
		resultTitle() {
			return t('dossiq', '{applied} of {total} cases changed', { applied: this.job.counts.applied, total: this.job.total })
		},

		/**
		 * The rehearsal is offered once the mode's own fields are filled.
		 *
		 * The reason gates every mode, including a transition, where it used to
		 * be an optional comment. Reading back twenty cases that moved for no
		 * recorded reason is the failure this prevents.
		 *
		 * @return {boolean} Whether the act can be rehearsed.
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		canRehearse() {
			if (this.starting === true || this.reason.trim().length === 0) {
				return false
			}

			return this.modeFieldsFilled
		},

		/**
		 * Whether the mode's own fields are filled: a transition for a
		 * transition, a new deadline for an extension.
		 *
		 * @return {boolean} True when the mode asks for nothing more.
		 */
		modeFieldsFilled() {
			if (this.isTransition === true && this.selectedTransition === null) {
				return false
			}

			return this.mode !== 'extend' || this.newEndDate !== ''
		},
	},

	/**
	 * Load what the mode needs before the reader can act.
	 *
	 * A lifecycle gesture has nothing to pick, so it is ready at once.
	 *
	 * @return {Promise<void>} Resolves when the dialog is usable.
	 *
	 * @spec openspec/specs/case-management/spec.md
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
		 * Load what every selected case offers, which fills the picker, and
		 * the case itself, which fills the table of changes. Both are reads.
		 *
		 * @return {Promise<void>} Resolves when the picker is filled.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
		 */
		async loadTransitions() {
			this.loadingTransitions = true
			this.transitionsError = null

			if (this.caseIds.length === 0) {
				this.transitionsError = t('dossiq', 'No cases are selected.')
				this.loadingTransitions = false
				return
			}

			try {
				const offers = await Promise.all(this.caseIds.map((id) => this.readCase(id)))
				this.transitions = commonTransitions(offers.map((offer) => offer.transitions))
				this.changeRows = offers.map((offer) => offer.row)
			} catch (err) {
				this.transitionsError = err?.response?.data?.error || err.message
			} finally {
				this.loadingTransitions = false
			}
		},

		/**
		 * Read one case and what it offers.
		 *
		 * @param {string} id The case id.
		 *
		 * @return {Promise<{transitions: Array<object>, row: object}>} The offer and its table row.
		 *
		 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
		 */
		async readCase(id) {
			const safeId = encodeURIComponent(id)
			const [offer, object] = await Promise.all([
				axios.get(generateUrl('/apps/dossiq/api/case/' + safeId + '/available-transitions')),
				axios.get(generateUrl('/apps/openregister/api/objects/dossiq/case/' + safeId)),
			])

			return {
				transitions: offer.data?.transitions || [],
				row: changeRow(id, object.data, offer.data?.current),
			}
		},

		/**
		 * Hand the act to the job and read back what it would do.
		 *
		 * Nothing is written by this. The job comes back rehearsed, with a row
		 * per case, and the commit lives in the progress panel underneath.
		 *
		 * @return {Promise<void>} Resolves when the job has been rehearsed.
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		async rehearse() {
			this.starting = true
			this.refusal = ''

			try {
				this.job = await previewBulkJob({
					action: isLifecycleGesture(this.mode)
						? ACTION_LIFECYCLE
						: ACTION_TRANSITION,
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
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		parameters() {
			if (isLifecycleGesture(this.mode) === true) {
				return lifecycleParameters(this.mode, {
					reason: this.reason,
					days: this.days,
					newEndDate: this.newEndDate,
				})
			}

			return transitionParameters(
				this.selectedTransition.id,
				this.reason.trim(),
			)
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
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		refusalSentence(err) {
			const { reason, message, details } = readRefusal(err)
			const sentence = REFUSAL_SENTENCES.get(reason)

			if (sentence) {
				return sentence(details)
			}

			return (
				message
				|| err?.message
				|| t('dossiq', 'The act could not be started.')
			)
		},

		/**
		 * Tell the list the cases have moved, once the job has stopped.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		onFinished() {
			this.$emit('completed', this.job)
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/case-management/spec.md
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

.bulk-transition-dialog__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 14px 16px;
}

.bulk-transition-dialog__help {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.bulk-transition-dialog__changes {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.bulk-transition-dialog__table {
	width: 100%;
	border: 1px solid var(--color-border);
	border-collapse: separate;
	border-spacing: 0;
	border-radius: 8px;
}

.bulk-transition-dialog__table th,
.bulk-transition-dialog__table td {
	padding: 9px 10px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.bulk-transition-dialog__table tbody tr:last-child td {
	border-bottom: 0;
}

.bulk-transition-dialog__result {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.bulk-transition-dialog__result li {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.bulk-transition-dialog__done {
	color: var(--color-success-text);
	font-size: 12px;
	font-weight: 600;
}

.bulk-transition-dialog__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 8px;
}

.bulk-transition-dialog__error {
	color: var(--color-error);
}
</style>

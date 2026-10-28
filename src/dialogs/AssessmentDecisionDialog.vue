<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Decide a case from the assessments of its documents, a case page header
	action.

	GENERIC (decision 182). Nothing here names a procedure. The manifest action
	carries the configuration in `props`: where the outstanding documents are
	read, where the assessments are read and which field holds their
	classification, what the classifications are called, and where the
	decision is posted.

	OUTSTANDING DOCUMENTS BLOCK THE DECISION, AND ARE NAMED. A decision over a
	case with a document nobody assessed is a decision about part of the file.
	The dialog names the documents that wait and offers nothing to save. The
	route refuses the same thing; the dialog only saves the handler the trip.

	IF THE OUTSTANDING LIST CANNOT BE READ, NOTHING IS OFFERED EITHER. An
	unreadable list is not an empty one.

	@spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Record the decision')"
		:eyebrow="eyebrow"
		:subtitle="subtitle"
		width="form"
		:noClose="busy"
		data-testid="assessment-decision-dialog"
		@closing="$emit('close')">
		<div v-if="decided === null" class="assessment-decision">
			<p
				v-if="loading"
				role="status"
				data-testid="assessment-decision-loading">
				{{ t('dossiq', 'Loading…') }}
			</p>
			<template v-else>
				<p
					v-if="loadError"
					class="assessment-decision__error"
					role="alert"
					data-testid="assessment-decision-load-error">
					{{ loadError }}
				</p>

				<CnChoiceCards
					v-if="counts.length"
					layout="rows"
					:modelValue="proposedOutcome"
					:label="t('dossiq', 'Decision')"
					:options="outcomeOptions"
					:readonly="true"
					:descriptionLines="1" />

				<p
					v-if="countsError !== ''"
					role="status"
					data-testid="assessment-decision-counts-error">
					{{ countsError }}
				</p>

				<div v-if="grounds.length" class="assessment-decision__field">
					<span class="assessment-decision__label">{{ t('dossiq', 'Refusal grounds') }}</span>
					<span class="assessment-decision__chips">
						<span v-for="ground in grounds" :key="ground.code" class="assessment-decision__chip">
							{{ n('dossiq', '{ground} · %n document', '{ground} · %n documents', ground.count, { ground: ground.code }) }}
						</span>
					</span>
					<span class="assessment-decision__hint">
						{{ t('dossiq', 'Taken over from the assessment. You change them per document.') }}
					</span>
				</div>

				<NcNoteCard
					v-if="outstanding.length > 0"
					type="warning"
					:showAlert="true"
					:heading="t('dossiq', 'Assess these documents first. The decision covers every document on the case.')"
					class="assessment-decision__blocked"
					data-testid="assessment-decision-blocked">
					<ul>
						<li v-for="document in outstanding" :key="document.id">
							{{
								document.title
								|| t('dossiq', 'Document without a title')
							}}
						</li>
					</ul>
				</NcNoteCard>

				<template v-else-if="loadError === ''">
					<div class="assessment-decision__row">
						<div class="assessment-decision__field">
							<label class="assessment-decision__label" for="assessment-decision-date">
								{{ t('dossiq', 'Decision date') }}
							</label>
							<span class="assessment-decision__date-wrap">
								<input
									id="assessment-decision-date"
									v-model="decisionDate"
									class="assessment-decision__date"
									type="date"
									data-testid="assessment-decision-date">
								<CalendarBlankOutline :size="18" class="assessment-decision__date-icon" aria-hidden="true" />
							</span>
							<span v-if="kase && kase.deadline" class="assessment-decision__hint">
								{{ t('dossiq', 'At the latest {date}', { date: formatDate(kase.deadline) }) }}
							</span>
						</div>
						<div v-if="daysLeft !== null" class="assessment-decision__field">
							<span class="assessment-decision__label">{{ t('dossiq', 'Term') }}</span>
							<span class="assessment-decision__term">
								{{ daysLeft >= 0 ? n('dossiq', '%n day left', '%n days left', daysLeft) : n('dossiq', '%n day late', '%n days late', -daysLeft) }}
							</span>
						</div>
					</div>

					<div class="assessment-decision__field">
						<label class="assessment-decision__label" for="assessment-decision-reasons">{{ t('dossiq', 'Reasons for the decision') }}</label>
						<NcTextArea
							id="assessment-decision-reasons"
							v-model="description"
							data-testid="assessment-decision-description"
							:label="t('dossiq', 'Reasons for the decision')"
							:labelOutside="true"
							:rows="4"
							resize="vertical" />
						<span class="assessment-decision__hint">
							{{ t('dossiq', 'Goes into the decision and into the reason of this step.') }}
						</span>
					</div>

					<div
						v-if="letterPurpose && (letterDeclaration || letterReadError)"
						class="assessment-decision__field"
						data-testid="assessment-decision-letter">
						<span class="assessment-decision__label">{{ t('dossiq', 'Letter of the decision') }}</span>
						<div class="assessment-decision__letter">
							<span class="assessment-decision__letter-icon"><FileDocumentCheckOutline :size="18" /></span>
							<div class="assessment-decision__letter-text">
								<template v-if="letter">
									<strong data-testid="assessment-decision-letter-title">{{ letter.title }}</strong>
									<span class="assessment-decision__hint">{{ letterLine }}</span>
								</template>
								<span v-else-if="letterReadError" class="assessment-decision__hint" data-testid="assessment-decision-letter-unread">{{ t('dossiq', 'The letter of this case could not be read.') }}</span>
								<span v-else class="assessment-decision__hint">{{ t('dossiq', 'No letter on this case yet.') }}</span>
							</div>
							<NcButton
								v-if="letter"
								data-testid="assessment-decision-letter-other"
								@click="composing = true">
								{{ t('dossiq', 'Other letter') }}
							</NcButton>
							<NcButton
								v-else
								data-testid="assessment-decision-letter-draft"
								:disabled="drafting"
								@click="draftLetter">
								{{ t('dossiq', 'Draft the letter') }}
							</NcButton>
						</div>
						<span v-if="letterError" class="assessment-decision__error" role="alert">{{ letterError }}</span>
						<span class="assessment-decision__hint">
							{{ t('dossiq', 'What now? Deciding asks two things: draft the decision and record it. The letter comes first.') }}
						</span>
					</div>
				</template>

				<div
					v-if="asideShown"
					class="assessment-decision__aside"
					data-testid="assessment-decision-aside">
					<AccountGroupOutline :size="18" class="assessment-decision__aside-icon" />
					<span>{{ t('dossiq', aside.text) }}</span>
				</div>

				<NcNoteCard v-if="note" type="info" :heading="noteHeading ? t('dossiq', noteHeading) : undefined">
					{{ t('dossiq', note) }}
				</NcNoteCard>

				<p
					v-if="error"
					class="assessment-decision__error"
					role="alert"
					data-testid="assessment-decision-error">
					{{ error }}
				</p>
			</template>
			<BeschikkingComposerDialog
				v-if="composing"
				:open="true"
				:caseId="targetCaseId"
				@close="composing = false"
				@generated="loadLetter" />
		</div>

		<p v-else role="status" data-testid="assessment-decision-done">
			{{ t('dossiq', 'The decision is recorded on this case.') }}
		</p>

		<template #actions>
			<NcButton
				data-testid="assessment-decision-cancel"
				@click="$emit('close')">
				{{ decided === null ? t('dossiq', 'Cancel') : t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="decided === null"
				variant="primary"
				data-testid="assessment-decision-save"
				:disabled="!canSave"
				@click="save">
				<template #icon>
					<Gavel :size="20" />
				</template>
				{{ t('dossiq', 'Record the decision') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { getCanonicalLocale, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarBlankOutline from 'vue-material-design-icons/CalendarBlankOutline.vue'
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import BeschikkingComposerDialog from './BeschikkingComposerDialog.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { nextStepOf } from '../utils/caseNextStep.js'

const PAGE_REFRESH = 'cn:page:refresh'

/**
 * The sentence a failed request carries, or the fallback.
 *
 * @param {object} failure The axios error.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string} The sentence.
 */
function sentenceOf(failure, fallback) {
	const data = failure?.response?.data
	return String(data?.message || data?.error || fallback)
}

export default {
	name: 'AssessmentDecisionDialog',

	components: {
		AccountGroupOutline,
		CalendarBlankOutline,
		BeschikkingComposerDialog,
		CnChoiceCards,
		FileDocumentCheckOutline,
		CnDialog,
		Gavel,
		NcButton,
		NcNoteCard,
		NcTextArea,
	},

	props: {
		/**
		 * The case. Arrives as the unresolved `@objectId` token from an
		 * `open-modal` action, so the route answers instead.
		 */
		caseId: {
			type: String,
			default: '',
		},

		/** What the dialog is called, in the words of the case type that configures it. */
		title: {
			type: String,
			default: 'Decide',
		},

		/** Where the outstanding documents are read; `{caseId}` is filled in. */
		outstandingUrl: {
			type: String,
			default:
				'/apps/dossiq/api/cases/{caseId}/document-assessments/outstanding',
		},

		/** Where the decision is posted; `{caseId}` is filled in. Empty disables saving. */
		decideUrl: {
			type: String,
			default: '',
		},

		/** Where the assessments are read: `{ register, schema, caseField, classificationField }`. */
		assessments: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The classifications: `{ value, label, decisionLabel?, description? }`.
		 * `label` names an assessment; `decisionLabel` names the decision
		 * option and `description` says what it means (both i18n keys).
		 */
		classifications: {
			type: Array,
			default: () => [],
		},

		/** The classification the decision takes when the documents were classified differently. */
		mixedOutcome: {
			type: String,
			default: '',
		},

		/** An i18n key shown as a note under the form: what follows the decision. */
		note: {
			type: String,
			default: '',
		},

		/** An i18n key shown as the note's heading. */
		noteHeading: {
			type: String,
			default: '',
		},

		/**
		 * The case type's `documentDrafts` purpose the decision letter is drafted for.
		 * Set, the dialog shows the case's letter and records the decision with it.
		 */
		letterPurpose: {
			type: String,
			default: '',
		},

		/**
		 * A pointer beside the form, `{ text, whenApp? }`: an i18n key, shown
		 * only while an app `whenApp` names (an id or a list of ids) is installed.
		 */
		aside: {
			type: Object,
			default: null,
		},

		/**
		 * Where the case type's statuses are read, with `{caseType}` and
		 * `{caseId}`. Set, the subtitle names the step the case moves to.
		 */
		stepsUrl: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			loadError: '',
			outstanding: [],
			tally: {},
			groundTally: {},
			kase: null,
			decisionDate: new Date().toISOString().slice(0, 10),
			countsError: '',
			description: '',
			busy: false,
			error: '',
			decided: null,
			letter: null,
			letterDeclaration: null,
			letterError: '',
			letterReadError: false,
			drafting: false,
			composing: false,
			nextStep: null,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		eyebrow() {
			return caseEyebrow(t('dossiq', this.title), this.kase)
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		subtitle() {
			const total = Object.values(this.tally).reduce((sum, count) => sum + count, 0)
			const built = n('dossiq', 'The decision is built from the %n assessed document.', 'The decision is built from the %n assessed documents.', total)
			if (this.nextStep === null) {
				return built
			}
			const step = t('dossiq', 'The case moves to step {number}, {status}.', { number: this.nextStep.number, status: this.nextStep.name.toLowerCase() })
			return step + ' ' + built
		},

		/**
		 * Whether the aside is shown: configured, and its app installed when it names one.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		asideShown() {
			if (!this.aside || !this.aside.text) {
				return false
			}
			const apps = [].concat(this.aside.whenApp ?? []).map(String).filter(Boolean)
			const roots = globalThis.OC?.appswebroots ?? {}
			return apps.length === 0 || apps.some((app) => Boolean(roots[app]))
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		outcomeOptions() {
			return this.counts.map((row) => ({
				value: row.value,
				label: row.decisionLabel,
				description: row.value === this.proposedOutcome && this.countsError === ''
					? t('dossiq', 'Proposal from the assessment: {counts}', { counts: this.countsSummary })
					: row.description,
			}))
		},

		/**
		 * The assessed documents per classification, e.g. "4 public, 2 disclosed in part".
		 *
		 * @return {string} The summary; classifications nobody chose are left out.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		countsSummary() {
			return this.counts
				.filter((row) => row.count > 0)
				.map((row) => `${row.count} ${row.label.toLowerCase()}`)
				.join(', ')
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		proposedOutcome() {
			const used = this.counts.filter((row) => row.count > 0)
			if (used.length === 1) {
				return used[0].value
			}
			return used.length > 1 ? this.mixedOutcome : ''
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		grounds() {
			return Object.entries(this.groundTally).map(([code, count]) => ({ code, count }))
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		daysLeft() {
			const deadline = String(this.kase?.deadline ?? '').slice(0, 10)
			if (deadline === '') {
				return null
			}
			const end = new Date(deadline + 'T00:00:00')
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			return Number.isNaN(end.getTime()) ? null : Math.round((end - today) / 86400000)
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		heading() {
			return t('dossiq', this.title)
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		counts() {
			return this.classifications.map((option) => {
				const value = String(option?.value ?? '')
				const label = t('dossiq', String(option?.label ?? value))
				return {
					value,
					label,
					decisionLabel: option?.decisionLabel ? t('dossiq', String(option.decisionLabel)) : label,
					description: option?.description
						? t('dossiq', String(option.description))
						: n('dossiq', '%n document assessed this way', '%n documents assessed this way', this.tally[value] ?? 0),
					count: this.tally[value] ?? 0,
				}
			})
		},

		/**
		 * The line under the letter's title: its type and when it was made.
		 *
		 * @return {string} The line.
		 * @spec openspec/specs/decision-document/spec.md
		 */
		letterLine() {
			const date = this.letter?.creatiedatum ? this.formatDate(this.letter.creatiedatum) : ''
			return [this.letterDeclaration?.documentType, date].filter(Boolean).join(' · ')
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		canSave() {
			return (
				this.busy === false
				&& this.loading === false
				&& this.loadError === ''
				&& this.decideUrl !== ''
				&& this.targetCaseId !== ''
				&& this.outstanding.length === 0
			)
		},
	},

	async mounted() {
		this.load()
		this.loadLetter()
		this.kase = await fetchCaseSummary(this.targetCaseId)
		this.loadNextStep()
	},

	methods: {
		t,
		n,

		/**
		 * @param {string} value A date.
		 * @return {string} The date written out in the reader's language.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		formatDate(value) {
			const date = new Date(String(value).slice(0, 10) + 'T00:00:00')
			return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString(getCanonicalLocale() || undefined, { day: 'numeric', month: 'long', year: 'numeric' })
		},

		/**
		 * Read the case type's statuses and keep the step after the case's own.
		 * Unreadable, the subtitle stays as it was.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		async loadNextStep() {
			const caseType = String(this.kase?.caseType ?? '')
			if (this.stepsUrl === '' || caseType === '') {
				return
			}
			try {
				const { data } = await axios.get(this.urlOf(this.stepsUrl.replace('{caseType}', encodeURIComponent(caseType))))
				this.nextStep = nextStepOf(data?.statusTypes, String(this.kase?.status ?? ''))
			} catch {
				this.nextStep = null
			}
		},

		/**
		 * A configured url with the case filled in.
		 *
		 * @param {string} template The url with `{caseId}`.
		 * @return {string} The url to call.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		urlOf(template) {
			return generateUrl(
				String(template).replace(
					'{caseId}',
					encodeURIComponent(this.targetCaseId),
				),
			)
		},

		/**
		 * Read what still waits, and how the assessed documents were classified.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		async load() {
			this.loading = true
			await Promise.all([this.loadOutstanding(), this.loadCounts()])
			this.loading = false
		},

		/**
		 * Read the outstanding documents. A failure blocks the decision.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		async loadOutstanding() {
			this.loadError = ''
			try {
				const { data } = await axios.get(this.urlOf(this.outstandingUrl))
				this.outstanding = (data?.documents || []).map((row) => ({
					id: String(row?.id ?? ''),
					title: String(row?.title ?? ''),
				}))
			} catch (failure) {
				this.outstanding = []
				this.loadError = sentenceOf(
					failure,
					t('dossiq', 'The documents of this case could not be read.'),
				)
			}
		},

		/**
		 * Count the assessments per classification. A failure hides the counts and blocks nothing:
		 * the counts inform, the outstanding list decides.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		async loadCounts() {
			const source = this.assessments || {}
			this.countsError = ''
			if (!source.register || !source.schema) {
				this.countsError = t(
					'dossiq',
					'The number of documents per assessment is not shown here.',
				)
				return
			}

			const field = source.classificationField || 'classification'
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${encodeURIComponent(source.register)}/${encodeURIComponent(source.schema)}`,
					),
					{
						params: {
							[source.caseField || 'case']: this.targetCaseId,
							_limit: 500,
						},
					},
				)
				const tally = {}
				const grounds = {}
				for (const row of data?.results || []) {
					const value = String(row?.[field] ?? '')
					tally[value] = (tally[value] ?? 0) + 1
					for (const ground of Array.isArray(row?.weigeringsgronden) ? row.weigeringsgronden : []) {
						const code = String(ground?.code ?? ground ?? '')
						if (code !== '') {
							grounds[code] = (grounds[code] ?? 0) + 1
						}
					}
				}
				this.tally = tally
				this.groundTally = grounds
			} catch {
				this.countsError = t(
					'dossiq',
					'The number of documents per assessment could not be read.',
				)
			}
		},

		/**
		 * Read the case's letter for the configured purpose. A failure says so in the row and blocks nothing.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/decision-document/spec.md
		 */
		async loadLetter() {
			if (this.letterPurpose === '') {
				return
			}
			this.letterReadError = false
			try {
				const { data } = await axios.get(this.letterUrl())
				this.letterDeclaration = data?.declaration || null
				this.letter = data?.document || null
			} catch {
				this.letterDeclaration = null
				this.letter = null
				this.letterReadError = true
			}
		},

		/**
		 * Draft the case type's letter for the purpose and show it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/decision-document/spec.md
		 */
		async draftLetter() {
			this.drafting = true
			this.letterError = ''
			try {
				const { data } = await axios.post(this.letterUrl())
				this.letterDeclaration = data?.declaration || this.letterDeclaration
				this.letter = data?.document || null
			} catch {
				this.letterError = t('dossiq', 'The letter could not be drafted.')
			} finally {
				this.drafting = false
			}
		},

		/**
		 * @return {string} The letter route of this case and purpose.
		 * @spec openspec/specs/decision-document/spec.md
		 */
		letterUrl() {
			return this.urlOf(`/apps/dossiq/api/cases/{caseId}/letters/${encodeURIComponent(this.letterPurpose)}`)
		},

		/**
		 * Post the decision once and show what the server answered.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
		 */
		async save() {
			const decision = {}
			if (this.description.trim() !== '') {
				decision.description = this.description.trim()
			}
			if (this.decisionDate !== '' && this.decisionDate !== new Date().toISOString().slice(0, 10)) {
				decision.decisionDate = this.decisionDate
			}

			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(this.urlOf(this.decideUrl), {
					decision,
					...(this.letter?.id ? { letter: this.letter.id } : {}),
				})
				this.decided = data || {}
				// The case changed: what the page offers next (publishing, for
				// one) follows from fields the server just wrote.
				emit(PAGE_REFRESH, {})
			} catch (failure) {
				this.error = sentenceOf(
					failure,
					t('dossiq', 'The decision could not be recorded.'),
				)
				// The usual refusal is a document that was added meanwhile.
				await this.loadOutstanding()
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.assessment-decision {
	display: flex;
	flex-direction: column;
	gap: 18px;

	p {
		margin: 0;
	}

	&__row {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 16px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 6px;
		min-width: 0;
	}

	// Labels and help lines sit on the font's normal line, as DqBesluit draws
	// them; the body's 1.5 put each 2px lower and 4px taller.
	&__label {
		font-size: 14px;
		font-weight: 600;
		line-height: normal;
	}

	&__hint {
		font-size: 13px;
		line-height: normal;
		color: var(--color-text-maxcontrast);
	}

	// The text area stands right under its label and over its help line:
	// NcTextArea's 6px top margin and its wrapper's 2px ring room on either
	// side come out, and the inline text area leaves no descender gap.
	& :deep(.textarea) {
		margin-block: -2px;
	}

	& :deep(.textarea__input) {
		vertical-align: top;
	}

	// The date field carries the board's calendar icon at its end; the
	// browser's own picker button stays where it is, only invisible, so a
	// click there still opens the picker.
	&__date-wrap {
		position: relative;
		display: block;
	}

	&__date {
		box-sizing: border-box;
		width: 100%;
		height: 40px;
		padding: 0 40px 0 12px;
		border: 1px solid var(--color-border-dark);
		border-radius: 8px;
		font: inherit;
		vertical-align: top;

		&::-webkit-calendar-picker-indicator {
			opacity: 0;
		}
	}

	&__date-icon {
		position: absolute;
		top: 11px;
		right: 12px;
		color: var(--color-text-maxcontrast);
		pointer-events: none;
	}

	&__term {
		display: flex;
		align-items: center;
		min-height: 40px;
		font-size: 15px;
	}

	&__letter {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 10px 14px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
	}

	// The letter's mark is a 32px tile on the success tint, as DqBesluit draws it.
	&__letter-icon {
		display: inline-flex;
		flex: none;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		border-radius: 8px;
		background: var(--nl-color-green-light, #e7f3ea);
		color: var(--nl-color-green-text, var(--color-success-text));
	}

	&__letter-text {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
		line-height: normal;

		strong {
			font-size: 15px;
		}
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__chip {
		padding: 4px 10px;
		border-radius: 12px;
		background: var(--color-background-dark);
		font-size: 13px;
		font-weight: 600;
		line-height: normal;
	}

	&__blocked {
		ul {
			margin: 0;
			padding-inline-start: 20px;
			list-style: disc;
		}
	}

	&__error {
		color: var(--color-error-text);
	}

	// A muted side note as DqBesluit draws it: 10px 14px in, 13px on a 1.5
	// line in the boards' soft ink, an 18px mark.
	&__aside {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		padding: 10px 14px;
		border-radius: 10px;
		background: var(--color-background-dark);
		color: var(--cn-board-text-soft, var(--color-main-text));
		font-size: 13px;
		line-height: 1.5;
	}

	&__aside-icon {
		flex: none;
		color: var(--color-text-maxcontrast);
	}
}
</style>

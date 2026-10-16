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
					:modelValue="proposedOutcome"
					:label="t('dossiq', 'Decision')"
					:options="outcomeOptions"
					:disabled="true"
					:descriptionLines="1" />

				<p
					v-if="countsError === ''"
					class="assessment-decision__hint"
					data-testid="assessment-decision-counts">
					{{ t('dossiq', 'From the assessment:') }}
					<template v-for="(row, index) in counts" :key="row.value">
						<span :data-testid="'assessment-decision-count-' + row.value">{{ row.count }}</span>
						{{ row.label.toLowerCase() }}{{ index < counts.length - 1 ? ',' : '' }}
					</template>
					· {{ t('dossiq', 'not assessed yet') }}
					<span data-testid="assessment-decision-count-outstanding">{{ outstanding.length }}</span>.
					{{ t('dossiq', 'The decision follows the assessment; you change it per document.') }}
				</p>
				<p
					v-else
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

				<div
					v-if="outstanding.length > 0"
					class="assessment-decision__blocked"
					role="alert"
					data-testid="assessment-decision-blocked">
					<strong>{{
						t(
							'dossiq',
							'Assess these documents first. The decision covers every document on the case.',
						)
					}}</strong>
					<ul>
						<li v-for="document in outstanding" :key="document.id">
							{{
								document.title
								|| t('dossiq', 'Document without a title')
							}}
						</li>
					</ul>
				</div>

				<template v-else-if="loadError === ''">
					<div class="assessment-decision__row">
						<div class="assessment-decision__field">
							<label class="assessment-decision__label" for="assessment-decision-date">
								{{ t('dossiq', 'Decision date') }}
							</label>
							<input
								id="assessment-decision-date"
								v-model="decisionDate"
								class="assessment-decision__date"
								type="date"
								data-testid="assessment-decision-date">
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
						<NcTextArea
							v-model="description"
							data-testid="assessment-decision-description"
							:label="t('dossiq', 'Reasons for the decision')"
							:labelOutside="true"
							resize="vertical" />
						<span class="assessment-decision__hint">
							{{ t('dossiq', 'Goes into the decision and into the reason of this step.') }}
						</span>
					</div>
				</template>

				<NcNoteCard v-if="note" type="info">
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
import { CnChoiceCards } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { getCanonicalLocale, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import CnDialog from '../components/CnDialogShell.js'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'

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
		CnChoiceCards,
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

		/** The classifications: `{ value, label }`. */
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
			return n('dossiq', 'The decision is built from the %n assessed document.', 'The decision is built from the %n assessed documents.', total)
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003 */
		outcomeOptions() {
			return this.counts.map((row) => ({
				value: row.value,
				label: row.label,
				description: n('dossiq', '%n document assessed this way', '%n documents assessed this way', row.count),
			}))
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
				return {
					value,
					label: t('dossiq', String(option?.label ?? value)),
					count: this.tally[value] ?? 0,
				}
			})
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
		this.kase = await fetchCaseSummary(this.targetCaseId)
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

	&__label {
		font-size: 14px;
		font-weight: 600;
	}

	&__hint {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__date {
		box-sizing: border-box;
		height: 40px;
		padding: 0 12px;
		border: 1px solid var(--color-border-dark);
		border-radius: 8px;
		font: inherit;
	}

	&__term {
		display: flex;
		align-items: center;
		min-height: 40px;
		font-size: 15px;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__chip {
		padding: 3px 10px;
		border-radius: 12px;
		background: var(--color-background-dark);
		font-size: 13px;
		font-weight: 600;
	}

	&__blocked {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-warning);
		color: var(--color-warning-text, var(--color-main-text));

		ul {
			margin: 0;
			padding-inline-start: 20px;
			list-style: disc;
		}
	}

	&__error {
		color: var(--color-error-text);
	}
}
</style>

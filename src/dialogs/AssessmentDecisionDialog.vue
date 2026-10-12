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
	<NcDialog
		:name="heading"
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

				<dl
					v-if="countsError === ''"
					class="assessment-decision__counts"
					data-testid="assessment-decision-counts">
					<template v-for="row in counts" :key="row.value">
						<dt>{{ row.label }}</dt>
						<dd :data-testid="'assessment-decision-count-' + row.value">
							{{ row.count }}
						</dd>
					</template>
					<dt>{{ t('dossiq', 'No assessment yet') }}</dt>
					<dd data-testid="assessment-decision-count-outstanding">
						{{ outstanding.length }}
					</dd>
				</dl>
				<p
					v-else
					role="status"
					data-testid="assessment-decision-counts-error">
					{{ countsError }}
				</p>

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

				<NcTextArea
					v-else-if="loadError === ''"
					v-model="description"
					data-testid="assessment-decision-description"
					:label="t('dossiq', 'Summary of the decision (optional)')" />

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
				{{ t('dossiq', 'Record the decision') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

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
		NcButton,
		NcDialog,
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
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			loadError: '',
			outstanding: [],
			tally: {},
			countsError: '',
			description: '',
			busy: false,
			error: '',
			decided: null,
		}
	},

	computed: {
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

	mounted() {
		this.load()
	},

	methods: {
		t,

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
				for (const row of data?.results || []) {
					const value = String(row?.[field] ?? '')
					tally[value] = (tally[value] ?? 0) + 1
				}
				this.tally = tally
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
	gap: 12px;

	p {
		margin: 0;
	}

	&__counts {
		margin: 0;
		display: grid;
		grid-template-columns: 1fr max-content;
		gap: 6px 16px;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			font-weight: 600;
			text-align: end;
		}
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

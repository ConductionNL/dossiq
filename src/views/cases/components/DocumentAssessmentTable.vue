<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/woo-case-type/tasks.md#task-6
-->
<template>
	<div class="document-assessment-table">
		<h4 class="document-assessment-table__title">
			{{ t('procest', 'Document Assessment') }}
		</h4>
		<p class="document-assessment-table__description">
			{{
				t(
					'procest',
					'Assess each document for disclosure under the WOO (Art. 5.1/5.2).',
				)
			}}
		</p>

		<!-- Progress indicator -->
		<div v-if="documents.length > 0" class="document-assessment-table__progress">
			<NcProgressBar
				:value="assessedCount"
				:max="documents.length"
				class="document-assessment-table__progress-bar" />
			<span class="document-assessment-table__progress-label">
				{{
					t('procest', '{assessed}/{total} documents assessed', {
						assessed: assessedCount,
						total: documents.length,
					})
				}}
			</span>
		</div>

		<div v-if="documents.length === 0" class="document-assessment-table__empty">
			{{ t('procest', 'No documents to assess.') }}
		</div>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th scope="col">{{ t('procest', '#') }}</th>
						<th scope="col">{{ t('procest', 'Document') }}</th>
						<th scope="col">{{ t('procest', 'Assessment') }}</th>
						<th scope="col">
							{{ t('procest', 'Grounds (WOO Art. 5.1/5.2)') }}
						</th>
						<th scope="col">{{ t('procest', 'Motivation') }}</th>
						<th scope="col">{{ t('procest', 'Redaction') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(doc, index) in documents"
						:key="doc.id"
						:class="rowClass(doc.id)">
						<td>{{ index + 1 }}</td>
						<td class="document-assessment-table__doc-name">
							{{ doc.title || doc.name || '---' }}
						</td>
						<td>
							<NcSelect
								:modelValue="
									localAssessments[doc.id]
									&& localAssessments[doc.id].classification
								"
								:options="classificationOptions"
								:inputLabel="t('procest', 'Assessment')"
								:disabled="isReadOnly"
								:placeholder="t('procest', 'Select...')"
								@update:modelValue="
									(val) => setClassification(doc.id, val)
								" />
						</td>
						<td>
							<NcSelect
								v-if="requiresGrounds(doc.id)"
								:modelValue="
									localAssessments[doc.id]
									&& localAssessments[doc.id].weigeringsgronden
								"
								:options="weigeringsgronden"
								:inputLabel="t('procest', 'Grounds')"
								:multiple="true"
								label="label"
								trackBy="code"
								:disabled="isReadOnly"
								:placeholder="t('procest', 'Select grounds...')"
								@update:modelValue="
									(val) => setGrounds(doc.id, val)
								" />
							<span v-else class="document-assessment-table__na"
								>---</span
							>
						</td>
						<td>
							<NcTextField
								v-if="
									localAssessments[doc.id]
									&& localAssessments[doc.id].classification
								"
								:modelValue="
									localAssessments[doc.id]
									&& localAssessments[doc.id].motivering
								"
								:disabled="isReadOnly"
								:aria-label="
									t('procest', 'Motivation for {doc}', {
										doc: doc.title || doc.name || doc.id,
									})
								"
								:placeholder="t('procest', 'Optional motivation...')"
								@update:modelValue="
									(val) => setMotivering(doc.id, val)
								" />
						</td>
						<td>
							<NcButton
								v-if="
									redactionAssistEnabled
									&& localAssessments[doc.id]
									&& localAssessments[doc.id].classification
										=== 'deels_openbaar'
								"
								type="tertiary"
								:aria-label="
									t(
										'procest',
										'AI-assisted redaction suggestions for {doc}',
										{ doc: doc.title || doc.name || doc.id },
									)
								"
								@click="openRedactionAssist(doc.id)">
								{{ t('procest', 'Redaction assist') }}
							</NcButton>
							<span v-else class="document-assessment-table__na"
								>---</span
							>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<RedactionAssistDialog
			v-if="activeRedactionDoc"
			:caseId="caseId"
			:documentRef="activeRedactionDoc"
			@close="activeRedactionDoc = null"
			@reviewed="onRedactionReviewed" />

		<!-- Validation errors -->
		<div
			v-if="validationErrors.length > 0"
			class="document-assessment-table__errors">
			<p
				v-for="err in validationErrors"
				:key="err"
				class="document-assessment-table__error">
				{{ err }}
			</p>
		</div>

		<!-- Summary row -->
		<div v-if="documents.length > 0" class="document-assessment-table__summary">
			<span
				class="document-assessment-table__count document-assessment-table__count--openbaar">
				{{ t('procest', 'Public') }}: {{ counts.openbaar }}
			</span>
			<span
				class="document-assessment-table__count document-assessment-table__count--deels">
				{{ t('procest', 'Partial') }}: {{ counts.deels_openbaar }}
			</span>
			<span
				class="document-assessment-table__count document-assessment-table__count--niet">
				{{ t('procest', 'Withheld') }}: {{ counts.niet_openbaar }}
			</span>
			<span
				class="document-assessment-table__count document-assessment-table__count--pending">
				{{ t('procest', 'Pending') }}: {{ counts.pending }}
			</span>
		</div>

		<!-- Save button -->
		<div
			v-if="!isReadOnly && documents.length > 0"
			class="document-assessment-table__actions">
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving">
					{{ t('procest', 'Saving...') }}
				</template>
				<template v-else>
					{{ t('procest', 'Save assessments') }}
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcProgressBar, NcSelect, NcTextField } from '@nextcloud/vue'
import RedactionAssistDialog from '../../../dialogs/RedactionAssistDialog.vue'

export default {
	name: 'DocumentAssessmentTable',
	components: {
		NcButton,
		NcProgressBar,
		NcSelect,
		NcTextField,
		RedactionAssistDialog,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		documents: {
			type: Array,
			default: () => [],
		},

		savedAssessments: {
			type: Object,
			default: () => ({}),
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether the "Redaction assist" (woo-llm-anonymisation) action is
		 * shown for `deels_openbaar` documents. Defaults to true — the
		 * action itself degrades gracefully to a rules-only proposal when
		 * Hermiq is unavailable, so there is no separate availability probe
		 * gating visibility here (see design.md).
		 */
		redactionAssistEnabled: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['saved', 'error', 'redaction-reviewed'],
	data() {
		return {
			/** Local copy of assessments, keyed by document ID. */
			localAssessments: {},
			saving: false,
			validationErrors: [],
			/** documentRef of the document whose RedactionAssistDialog is open, or null. */
			activeRedactionDoc: null,
			classificationOptions: ['openbaar', 'deels_openbaar', 'niet_openbaar'],
			weigeringsgronden: [
				{ code: '5.1.1', label: '5.1.1 Eenheid van de Kroon' },
				{ code: '5.1.2', label: '5.1.2 Veiligheid van de Staat' },
				{ code: '5.1.3', label: '5.1.3 Bedrijfs- en fabricagegegevens' },
				{ code: '5.1.4', label: '5.1.4 Persoonlijke beleidsopvattingen' },
				{ code: '5.1.5', label: '5.1.5 Persoonlijke levenssfeer' },
				{ code: '5.2.1', label: '5.2.1 Economische belangen Staat' },
				{ code: '5.2.2', label: '5.2.2 Opsporing strafbare feiten' },
				{ code: '5.2.3', label: '5.2.3 Inspectie en toezicht' },
				{ code: '5.2.4', label: '5.2.4 Vertrouwelijkheid beraadslaging' },
				{ code: '5.2.5', label: '5.2.5 Functioneren van de Staat' },
			],
		}
	},

	computed: {
		/** @spec openspec/changes/woo-case-type/tasks.md#task-6 */
		counts() {
			const result = {
				openbaar: 0,
				deels_openbaar: 0,
				niet_openbaar: 0,
				pending: 0,
			}
			for (const doc of this.documents) {
				const assessment = this.localAssessments[doc.id]
				if (assessment && assessment.classification) {
					const key = assessment.classification
					if (result[key] !== undefined) {
						result[key]++
					}
				} else {
					result.pending++
				}
			}
			return result
		},

		/** @spec openspec/changes/woo-case-type/tasks.md#task-6 */
		assessedCount() {
			return this.documents.filter(
				(doc) =>
					this.localAssessments[doc.id]
					&& this.localAssessments[doc.id].classification,
			).length
		},
	},

	watch: {
		savedAssessments: {
			immediate: true,
			handler(val) {
				this.localAssessments = { ...val }
			},
		},
	},

	methods: {
		/**
		 * @param docId
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		requiresGrounds(docId) {
			const a = this.localAssessments[docId]
			return (
				a
				&& (a.classification === 'niet_openbaar'
					|| a.classification === 'deels_openbaar')
			)
		},

		/**
		 * @param docId
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		rowClass(docId) {
			const a = this.localAssessments[docId]
			if (!a || !a.classification) {
				return 'document-assessment-table__row--pending'
			}
			return 'document-assessment-table__row--' + a.classification
		},

		/**
		 * @param docId
		 * @param value
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		setClassification(docId, value) {
			this.localAssessments = {
				...this.localAssessments,
				[docId]: {
					...(this.localAssessments[docId] || {}),
					documentRef: docId,
					classification: value,
					weigeringsgronden:
						value === 'openbaar'
							? []
							: this.localAssessments[docId]?.weigeringsgronden || [],
				},
			}
		},

		/**
		 * @param docId
		 * @param value
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		setGrounds(docId, value) {
			this.localAssessments = {
				...this.localAssessments,
				[docId]: {
					...(this.localAssessments[docId] || {}),
					documentRef: docId,
					weigeringsgronden: value,
				},
			}
		},

		/**
		 * @param docId
		 * @param value
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		setMotivering(docId, value) {
			this.localAssessments = {
				...this.localAssessments,
				[docId]: {
					...(this.localAssessments[docId] || {}),
					documentRef: docId,
					motivering: value,
				},
			}
		},

		/**
		 * Open the RedactionAssistDialog for a document
		 * (woo-llm-anonymisation) — an ASSIST to the existing rule-based/
		 * Docudesk/manual redaction flow, never a replacement.
		 *
		 * @param {string} docId
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		openRedactionAssist(docId) {
			this.activeRedactionDoc = docId
		},

		/**
		 * Relay the reviewer's decision to the parent — closes the dialog
		 * (handled by its own `@close`) and lets the parent refresh whatever
		 * redaction-status indicator it renders.
		 *
		 * @param {object} result The updated proposal record.
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		onRedactionReviewed(result) {
			this.$emit('redaction-reviewed', result)
		},

		/**
		 * Validate all assessments client-side before submitting.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		validateAll() {
			this.validationErrors = []

			for (const doc of this.documents) {
				const a = this.localAssessments[doc.id]
				if (!a || !a.classification) {
					continue
				}
				if (
					(a.classification === 'niet_openbaar'
						|| a.classification === 'deels_openbaar')
					&& (!a.weigeringsgronden || a.weigeringsgronden.length === 0)
				) {
					const name = doc.title || doc.name || doc.id
					this.validationErrors.push(
						this.t(
							'procest',
							'"{doc}" is {class} but has no weigeringsgrond selected.',
							{
								doc: name,
								class: a.classification,
							},
						),
					)
				}
			}

			return this.validationErrors.length === 0
		},

		/**
		 * Submit all assessments to the bulk-upsert endpoint.
		 *
		 * @spec openspec/changes/woo-case-type/tasks.md#task-6
		 */
		async save() {
			if (!this.validateAll()) {
				return
			}

			const assessments = Object.values(this.localAssessments).filter(
				(a) => a && a.classification,
			)

			if (assessments.length === 0) {
				return
			}

			this.saving = true
			try {
				const url = generateUrl(
					'/apps/procest/api/cases/'
						+ encodeURIComponent(this.caseId)
						+ '/woo/assessment',
				)
				const { data } = await axios.post(url, { assessments })
				this.$emit('saved', data)
			} catch (err) {
				const message = err.response?.data?.error || err.message
				this.validationErrors = [
					this.t('procest', 'Failed to save assessments: {error}', {
						error: message,
					}),
				]
				this.$emit('error', message)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.document-assessment-table__title {
	margin-bottom: 4px;
}

.document-assessment-table__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.document-assessment-table__progress {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.document-assessment-table__progress-bar {
	flex: 1;
}

.document-assessment-table__progress-label {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.document-assessment-table__empty {
	color: var(--color-text-maxcontrast);
	padding: 16px 0;
}

.document-assessment-table__doc-name {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.document-assessment-table__na {
	color: var(--color-text-maxcontrast);
}

.document-assessment-table__errors {
	margin-top: 12px;
}

.document-assessment-table__error {
	color: var(--color-error);
	font-size: 0.875rem;
	margin: 2px 0;
}

.document-assessment-table__summary {
	display: flex;
	gap: 16px;
	padding: 12px 0;
	flex-wrap: wrap;
}

.document-assessment-table__count {
	padding: 4px 8px;
	border-radius: var(--border-radius);
	font-size: 0.875rem;
}

.document-assessment-table__count--openbaar {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.document-assessment-table__count--deels {
	background: var(--color-warning-light, #fff3e0);
	color: var(--color-warning, #e65100);
}

.document-assessment-table__count--niet {
	background: var(--color-error-light, #ffebee);
	color: var(--color-error, #c62828);
}

.document-assessment-table__count--pending {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.document-assessment-table__actions {
	margin-top: 16px;
}

tr.document-assessment-table__row--pending {
	opacity: 0.75;
}

tr.document-assessment-table__row--openbaar td:first-child {
	border-left: 3px solid var(--color-success, #2e7d32);
}

tr.document-assessment-table__row--deels_openbaar td:first-child {
	border-left: 3px solid var(--color-warning, #e65100);
}

tr.document-assessment-table__row--niet_openbaar td:first-child {
	border-left: 3px solid var(--color-error, #c62828);
}
</style>

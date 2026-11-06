<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Assess documents, a case page header action. The list is the "Wat nog
	openstaat" table of board DqWooReview: the documents of the case that have
	no assessment yet, with a select-all box in the head.

	GENERIC (decision 182). Nothing here names a procedure. The manifest action
	that opens it carries the configuration in `props`: where the outstanding
	list is read, where the assessment is posted, which classifications exist
	and which of them need a ground, where the grounds are read, and what the
	fields of one assessment are called. A case type that assesses documents
	configures it; a case type that does not has no action.

	ONE POST FOR THE WHOLE SELECTION. The route takes a list, so two documents
	are one request and one answer, not two requests that can half succeed
	without the handler seeing which half.

	A REFUSAL IS SHOWN, NOT SWALLOWED. The server's sentence is shown as the
	server said it, the dialog stays open, and the list is read again, so a
	document that was refused is still in it. Nothing reads as saved unless
	the server stored it.

	@spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Assess documents')"
		size="large"
		data-testid="document-assess-dialog"
		@closing="$emit('close')">
		<div class="document-assess">
			<div class="document-assess__head">
				<h3>{{ t('dossiq', 'What is still open') }}</h3>
				<span>{{
					t('dossiq', 'Documents on this case without an assessment.')
				}}</span>
			</div>

			<p
				v-if="loadError"
				class="document-assess__error"
				role="alert"
				data-testid="document-assess-load-error">
				{{ loadError }}
			</p>
			<p
				v-else-if="loading"
				role="status"
				data-testid="document-assess-loading">
				{{ t('dossiq', 'Loading…') }}
			</p>
			<p
				v-else-if="documents.length === 0"
				role="status"
				data-testid="document-assess-empty">
				{{ t('dossiq', 'Every document on this case has an assessment.') }}
			</p>
			<div v-else class="document-assess__table-wrap">
				<table
					class="document-assess__table"
					data-testid="document-assess-table">
					<thead>
						<tr>
							<th scope="col" class="document-assess__check">
								<input
									type="checkbox"
									data-testid="document-assess-select-all"
									:checked="allSelected"
									:aria-label="t('dossiq', 'Select all documents')"
									@change="toggleAll($event.target.checked)" />
							</th>
							<th scope="col">
								{{ t('dossiq', 'Document') }}
							</th>
							<th scope="col">
								{{ t('dossiq', 'Assessment') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="document in documents"
							:key="document.id"
							:data-testid="'document-assess-row-' + document.id">
							<td class="document-assess__check">
								<input
									type="checkbox"
									:data-testid="
										'document-assess-select-' + document.id
									"
									:checked="selected.includes(document.id)"
									:aria-label="
										t('dossiq', 'Select {title}', {
											title: titleOf(document),
										})
									"
									@change="
										toggle(document.id, $event.target.checked)
									" />
							</td>
							<td>
								<strong>{{ titleOf(document) }}</strong>
								<span
									v-if="refusals[document.id]"
									class="document-assess__refusal"
									role="alert"
									:data-testid="
										'document-assess-refusal-' + document.id
									">
									{{ refusals[document.id] }}
								</span>
							</td>
							<td>
								<span class="document-assess__pill cn-status-badge cn-status-badge--default">{{
									t('dossiq', 'No assessment yet')
								}}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<template v-if="documents.length > 0">
				<NcSelect
					v-model="classification"
					data-testid="document-assess-classification"
					:options="classificationOptions"
					:inputLabel="
						t('dossiq', 'Assessment for the selected documents')
					"
					:clearable="false" />

				<template v-if="needsGrounds">
					<NcSelect
						v-model="pickedGrounds"
						data-testid="document-assess-grounds"
						:options="groundOptions"
						:multiple="true"
						:loading="groundsLoading"
						:inputLabel="t('dossiq', 'Grounds')" />
					<p
						v-if="groundsError"
						class="document-assess__error"
						role="alert"
						data-testid="document-assess-grounds-error">
						{{ groundsError }}
					</p>
				</template>
			</template>

			<p
				v-if="notice"
				role="status"
				class="document-assess__notice"
				data-testid="document-assess-notice">
				{{ notice }}
			</p>
			<p
				v-if="error"
				class="document-assess__error"
				role="alert"
				data-testid="document-assess-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="document-assess-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="document-assess-save"
				:disabled="!canSave"
				@click="save">
				{{ busy ? t('dossiq', 'Saving…') : t('dossiq', 'Save assessment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const PAGE_REFRESH = 'cn:page:refresh'
const SPEC_NOTE = 'document-assessment REQ-DAS-002'

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
	name: 'DocumentAssessDialog',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
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

		/** Where the outstanding documents are read; `{caseId}` is filled in. */
		outstandingUrl: {
			type: String,
			default:
				'/apps/dossiq/api/cases/{caseId}/document-assessments/outstanding',
		},

		/** Where the assessments are posted; `{caseId}` is filled in. Empty disables saving. */
		assessUrl: {
			type: String,
			default: '',
		},

		/** The classifications: `{ value, label, needsGrounds }`. */
		classifications: {
			type: Array,
			default: () => [],
		},

		/** Where the grounds are read: `{ register, schema, valueField, labelField, filter }`. */
		grounds: {
			type: Object,
			default: () => ({}),
		},

		/** What the fields of one posted assessment are called. */
		fields: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The document to start with chosen, when the dialog is opened from a
		 * document's row ("Beoordelen", DqZaakDocumenten). Ignored when it is
		 * not outstanding.
		 *
		 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-a-document-is-assessed-from-its-row
		 */
		documentId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			loadError: '',
			documents: [],
			selected: [],
			classification: null,
			pickedGrounds: [],
			groundOptions: [],
			groundsLoading: false,
			groundsLoaded: false,
			groundsError: '',
			busy: false,
			error: '',
			notice: '',
			refusals: {},
			stored: [],
		}
	},

	computed: {
		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002 */
		classificationOptions() {
			return this.classifications.map((option) => ({
				id: String(option?.value ?? ''),
				label: t('dossiq', String(option?.label ?? option?.value ?? '')),
				needsGrounds: option?.needsGrounds === true,
			}))
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002 */
		needsGrounds() {
			return this.classification?.needsGrounds === true
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002 */
		allSelected() {
			return (
				this.documents.length > 0
				&& this.selected.length === this.documents.length
			)
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002 */
		canSave() {
			return (
				this.busy === false
				&& this.assessUrl !== ''
				&& this.targetCaseId !== ''
				&& this.selected.length > 0
				&& this.classification !== null
				&& (this.needsGrounds === false || this.pickedGrounds.length > 0)
			)
		},
	},

	watch: {
		/**
		 * Read the grounds the first time a classification asks for one.
		 *
		 * @param {boolean} needed Whether the picked classification needs a ground.
		 * @return {void}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		needsGrounds(needed) {
			if (needed === true && this.groundsLoaded === false) {
				this.loadGrounds()
			}
		},
	},

	async mounted() {
		if (this.documentId !== '') {
			this.selected = [this.documentId]
		}
		await this.load()
	},

	methods: {
		t,

		/**
		 * A configured url with the case filled in.
		 *
		 * @param {string} template The url with `{caseId}`.
		 * @return {string} The url to call.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
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
		 * What a document is called in the list.
		 *
		 * @param {{id: string, title: string}} document The document.
		 * @return {string} Its title, or a sentence when it has none.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		titleOf(document) {
			return (
				String(document?.title || '')
				|| t('dossiq', 'Document without a title')
			)
		},

		/**
		 * Read the outstanding documents of the case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { data } = await axios.get(this.urlOf(this.outstandingUrl))
				// A document the server answered as saved is not outstanding,
				// even when the list is read before the search sees the save.
				this.documents = (data?.documents || [])
					.map((row) => ({
						id: String(row?.id ?? ''),
						title: String(row?.title ?? ''),
					}))
					.filter(
						(document) => this.stored.includes(document.id) === false,
					)
				const still = this.documents.map((document) => document.id)
				this.selected = this.selected.filter((id) => still.includes(id))
			} catch (failure) {
				this.documents = []
				this.loadError = sentenceOf(
					failure,
					t('dossiq', 'The documents of this case could not be read.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the grounds a handler can cite, from the configured schema.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		async loadGrounds() {
			const source = this.grounds || {}
			if (!source.register || !source.schema) {
				return
			}

			const valueField = source.valueField || 'id'
			const labelField = source.labelField || valueField
			this.groundsLoading = true
			this.groundsError = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${encodeURIComponent(source.register)}/${encodeURIComponent(source.schema)}`,
					),
					{
						params: {
							...(source.filter || {}),
							_limit: 200,
							_order: { [valueField]: 'asc' },
						},
					},
				)
				this.groundOptions = (data?.results || [])
					.map((row) => {
						const value = String(row?.[valueField] ?? '')
						const label = String(row?.[labelField] ?? '')
						return {
							id: value,
							label:
								label !== '' && label !== value
									? value + ' ' + label
									: value,
						}
					})
					.filter((option) => option.id !== '')
				this.groundsLoaded = true
			} catch (failure) {
				this.groundsError = sentenceOf(
					failure,
					t(
						'dossiq',
						'The grounds could not be read, so an assessment that needs one cannot be saved.',
					),
				)
			} finally {
				this.groundsLoading = false
			}
		},

		/**
		 * Tick or untick one document.
		 *
		 * @param {string} id The document.
		 * @param {boolean} on Whether it is ticked.
		 * @return {void}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		toggle(id, on) {
			const rest = this.selected.filter((picked) => picked !== id)
			this.selected = on === true ? [...rest, id] : rest
		},

		/**
		 * Tick or untick every document.
		 *
		 * @param {boolean} on Whether all are ticked.
		 * @return {void}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		toggleAll(on) {
			this.selected =
				on === true ? this.documents.map((document) => document.id) : []
		},

		/**
		 * The refusals of an answer, one sentence per refused document.
		 *
		 * @param {object} data The server's answer, with `errors`.
		 * @return {Record<string, string>} Document id to sentence.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		refusalsOf(data) {
			const fieldOfDocument = this.fields?.document || 'documentRef'
			const out = {}
			for (const refused of data?.errors || []) {
				const id = String(
					refused?.[fieldOfDocument] ?? refused?.documentRef ?? '',
				)
				const sentences = Object.values(refused?.errors || {}).map(String)
				if (id !== '') {
					out[id] = sentences.join(' ')
				}
			}

			return out
		},

		/**
		 * The documents an answer says were saved.
		 *
		 * @param {object} answer The server's answer, with `saved`.
		 * @param {string} fieldOfDocument What the document field of an assessment is called.
		 * @return {Array<string>} Their ids.
		 * @spec openspec/specs/document-assessment/spec.md
		 */
		savedIdsOf(answer, fieldOfDocument) {
			return (Array.isArray(answer?.saved) ? answer.saved : [])
				.map((row) =>
					String(row?.[fieldOfDocument] ?? row?.documentRef ?? ''),
				)
				.filter((id) => id !== '')
		},

		/**
		 * Post the assessment of the selection once, and show what the server said.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
		 */
		async save() {
			const names = {
				document: this.fields?.document || 'documentRef',
				classification: this.fields?.classification || 'classification',
				grounds: this.fields?.grounds || 'grounds',
			}
			const grounds =
				this.needsGrounds === true
					? this.pickedGrounds.map((option) => option.id)
					: []
			const asked = [...this.selected]
			const assessments = asked.map((id) => ({
				[names.document]: id,
				[names.classification]: this.classification.id,
				[names.grounds]: grounds,
			}))

			this.busy = true
			this.error = ''
			this.notice = ''
			this.refusals = {}
			let answer
			try {
				answer = (
					await axios.post(this.urlOf(this.assessUrl), { assessments })
				).data
			} catch (failure) {
				const data = failure?.response?.data
				this.refusals = this.refusalsOf(data)
				this.error =
					Object.keys(this.refusals).length > 0
						? t(
								'dossiq',
								'The server refused part of this assessment. What it said is shown with each document.',
							)
						: sentenceOf(
								failure,
								t('dossiq', 'The assessment could not be saved.'),
							)
				answer = data
			}

			const saved = this.savedIdsOf(answer, names.document)
			const stored = Array.isArray(answer?.saved) ? answer.saved.length : 0
			if (stored > 0) {
				this.stored = [...new Set([...this.stored, ...saved])]
				this.documents = this.documents.filter(
					(document) => saved.includes(document.id) === false,
				)
				this.selected = this.selected.filter(
					(id) => saved.includes(id) === false,
				)
				this.notice = n(
					'dossiq',
					'%n document assessed.',
					'%n documents assessed.',
					stored,
				)
				emit(PAGE_REFRESH, { source: SPEC_NOTE })
			}

			// Read the list again rather than trusting the selection: what the
			// server did not store is still outstanding, and stays on screen.
			await this.load()
			this.busy = false
		},
	},
}
</script>

<style scoped lang="scss">
.document-assess {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__head {
		display: flex;
		flex-direction: column;
		gap: 2px;

		h3 {
			margin: 0;
			font-size: 17px;
			font-weight: 700;
		}

		span {
			color: var(--color-text-maxcontrast);
		}
	}

	// A label breaks between words, and a long word at a hyphenation point of
	// the page language; "Deels openbaar" and a ground's name never split
	// inside a word.
	:deep(.vs__selected),
	&__table td {
		overflow-wrap: break-word;
		word-break: normal;
		hyphens: auto;
	}

	&__table-wrap {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		overflow: hidden;
	}

	&__table {
		border-collapse: collapse;
		width: 100%;

		th {
			padding: 10px 14px;
			text-align: start;
			font-weight: 600;
			color: var(--color-text-maxcontrast);
			background: var(--color-background-hover);
		}

		td {
			padding: 12px 14px;
			vertical-align: top;
			border-top: 1px solid var(--color-border);
		}
	}

	&__check {
		width: 28px;

		input {
			width: 16px;
			height: 16px;
		}
	}

	&__pill {
		display: inline-block;
		background: var(--color-background-dark);
		--cn-status-badge-ink: var(--color-main-text);
		color: var(--cn-status-badge-ink);
		white-space: nowrap;
	}

	&__refusal {
		display: block;
		margin-top: 4px;
		color: var(--color-error-text);
	}

	&__notice {
		margin: 0;
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>

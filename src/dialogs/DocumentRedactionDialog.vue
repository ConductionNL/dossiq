<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Redact documents, a case page header action. Built to board DqLakken: per
	partly public document, the document text with the passages marked, and
	beside it the proposal with a verdict per passage.

	GENERIC (decision 182). Nothing here names a procedure. The manifest action
	that opens it carries the routes and the passage type labels in `props`.

	THE CHECK IS THE OFFICER'S. "Lakken vastleggen" is enabled only when every
	passage has a verdict. Pressing it first records the check of the passages
	to redact, in the officer's name, and only then asks for the redacted copy:
	filinq refuses a copy of a list nobody checked.

	A REFUSAL IS SHOWN, NOT SWALLOWED. The server's sentence is shown as the
	server said it, and the upload stays offered.

	@spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
-->
<template>
	<component
		:is="asPage ? 'section' : 'NcDialog'"
		v-bind="rootAttrs"
		@closing="$emit('close')">
		<div class="document-redaction" :class="{ 'document-redaction--page': asPage }">
			<header v-if="asPage" class="document-redaction__head">
				<div class="document-redaction__heading">
					<h1 class="document-redaction__title">
						{{ dialogName }}
					</h1>
					<nav
						class="document-redaction__crumbs"
						:aria-label="t('dossiq', 'Breadcrumb')"
						data-testid="document-redaction-crumbs">
						<router-link :to="{ name: 'Cases' }">
							{{ t('dossiq', 'All cases') }}
						</router-link>
						<span aria-hidden="true">/</span>
						<router-link :to="{ name: 'CaseDetail', params: { id: targetCaseId } }">
							{{ (kase && kase.identifier) || t('dossiq', 'Case') }}
						</router-link>
						<span aria-hidden="true">/</span>
						<span>{{ t('dossiq', 'Redact') }}</span>
					</nav>
				</div>
				<div v-if="documents.length > 0" class="document-redaction__buttons">
					<NcButton
						data-testid="document-redaction-previous"
						:disabled="index === 0 || busy"
						@click="open(index - 1)">
						{{ t('dossiq', 'Previous document') }}
					</NcButton>
					<NcButton
						data-testid="document-redaction-next"
						:disabled="index >= documents.length - 1 || busy"
						@click="open(index + 1)">
						{{ t('dossiq', 'Next document') }}
					</NcButton>
					<NcButton
						variant="primary"
						data-testid="document-redaction-redact"
						:disabled="!canRedact"
						@click="redact">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ t('dossiq', 'Record redaction') }}
					</NcButton>
				</div>
			</header>
			<p
				v-if="loadError"
				class="document-redaction__error"
				role="alert"
				data-testid="document-redaction-load-error">
				{{ loadError }}
			</p>
			<p
				v-else-if="loading"
				role="status"
				data-testid="document-redaction-loading">
				{{ t('dossiq', 'Loading…') }}
			</p>
			<p
				v-else-if="documents.length === 0"
				role="status"
				class="document-redaction__empty"
				data-testid="document-redaction-empty">
				{{
					t(
						'dossiq',
						'No document on this case is assessed as disclosed in part.',
					)
				}}
			</p>
			<template v-else>
				<div class="document-redaction__bar">
					<span
						class="document-redaction__meta"
						data-testid="document-redaction-position">
						{{
							t('dossiq', 'document {index} of {count}', {
								index: index + 1,
								count: documents.length,
							})
						}}
						<template v-if="current.redactedDocumentRef">
							·
							<span
								class="document-redaction__pill document-redaction__pill--done"
								data-testid="document-redaction-done"
								>{{ t('dossiq', 'Redacted copy recorded') }}</span
							>
						</template>
					</span>
					<div class="document-redaction__buttons">
						<template v-if="!asPage">
							<NcButton
								data-testid="document-redaction-previous"
								:disabled="index === 0 || busy"
								@click="open(index - 1)">
								{{ t('dossiq', 'Previous document') }}
							</NcButton>
							<NcButton
								data-testid="document-redaction-next"
								:disabled="index >= documents.length - 1 || busy"
								@click="open(index + 1)">
								{{ t('dossiq', 'Next document') }}
							</NcButton>
						</template>
						<NcButton
							data-testid="document-redaction-upload"
							:disabled="busy"
							@click="$refs.upload.click()">
							{{ t('dossiq', 'Upload redacted version') }}
						</NcButton>
						<input
							ref="upload"
							type="file"
							class="hidden-visually"
							data-testid="document-redaction-upload-input"
							:aria-label="t('dossiq', 'Upload redacted version')"
							@change="upload($event.target.files)" />
						<NcButton
							v-if="!asPage"
							variant="primary"
							data-testid="document-redaction-redact"
							:disabled="!canRedact"
							@click="redact">
							{{ t('dossiq', 'Record redaction') }}
						</NcButton>
					</div>
				</div>

				<p
					v-if="error"
					class="document-redaction__error"
					role="alert"
					data-testid="document-redaction-error">
					{{ error }}
				</p>
				<p
					v-if="notice"
					class="document-redaction__notice"
					role="status"
					data-testid="document-redaction-notice">
					{{ notice }}
				</p>

				<div class="document-redaction__columns">
					<div
						class="document-redaction__document"
						data-testid="document-redaction-text">
						<strong>{{
							current.title || t('dossiq', 'Document without a title')
						}}</strong>
						<p v-if="detecting" role="status">
							{{ t('dossiq', 'Finding the passages to redact…') }}
						</p>
						<p v-else-if="text === ''" class="document-redaction__muted">
							{{
								t(
									'dossiq',
									'No text was extracted from this document.',
								)
							}}
						</p>
						<p v-else class="document-redaction__body">
							<template v-for="(segment, at) in segments" :key="at">
								<span
									v-if="segment.verdict"
									:class="
										'document-redaction__mark document-redaction__mark--'
										+ segment.verdict
									"
									>{{ segment.text }}</span
								>
								<template v-else>
									{{ segment.text }}
								</template>
							</template>
						</p>
					</div>

					<aside class="document-redaction__proposal">
						<h2>{{ t('dossiq', 'Proposal') }}</h2>
						<span
							class="document-redaction__muted"
							data-testid="document-redaction-count">
							{{
								n(
									'dossiq',
									'%n passage found. Review each passage; the ground goes into the decision.',
									'%n passages found. Review each passage; the ground goes into the decision.',
									passages.length,
								)
							}}
						</span>
						<ul class="document-redaction__list">
							<li
								v-for="(passage, at) in passages"
								:key="at"
								class="document-redaction__passage"
								:data-testid="'document-redaction-passage-' + at">
								<span class="document-redaction__line">
									<strong
										>{{ labelOf(passage.type) }}: "{{
											passage.value
										}}"</strong
									>
									<span
										v-if="passage.verdict"
										:class="
											'document-redaction__pill document-redaction__pill--'
											+ passage.verdict
										"
										>{{ verdictLabel(passage.verdict) }}</span
									>
								</span>
								<span
									v-if="grounds"
									class="document-redaction__muted"
									>{{ grounds }}</span
								>
								<div
									class="document-redaction__verdicts"
									role="group"
									:aria-label="
										t('dossiq', 'Verdict for {passage}', {
											passage: passage.value,
										})
									">
									<NcButton
										v-for="verdict in VERDICTS"
										:key="verdict"
										size="small"
										:pressed="passage.verdict === verdict"
										:data-testid="
											'document-redaction-verdict-'
											+ at
											+ '-'
											+ verdict
										"
										@update:pressed="setVerdict(at, verdict)">
										{{ verdictLabel(verdict) }}
									</NcButton>
								</div>
								<NcSelect
									v-if="passage.entity.source === 'officer'"
									:modelValue="
										typeOptions.find(
											(option) => option.id === passage.type,
										) || null
									"
									:options="typeOptions"
									:inputLabel="t('dossiq', 'Passage type')"
									:clearable="false"
									:data-testid="'document-redaction-type-' + at"
									@update:modelValue="retype(at, $event)" />
								<NcTextField
									v-if="passage.verdict === 'adjust'"
									:modelValue="passage.value"
									:label="t('dossiq', 'Passage text')"
									:data-testid="'document-redaction-value-' + at"
									@update:modelValue="adjust(at, $event)" />
							</li>
						</ul>
						<NcButton
							data-testid="document-redaction-add"
							:disabled="busy || detecting"
							@click="addPassage">
							{{ t('dossiq', 'Add passage') }}
						</NcButton>
					</aside>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton data-testid="document-redaction-close" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</component>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import { fetchCaseSummary } from '../services/caseSummary.js'

const PAGE_REFRESH = 'cn:page:refresh'
const VERDICTS = ['accept', 'adjust', 'skip']

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

/**
 * The text cut into plain and marked segments, the longest passage first where they overlap.
 *
 * @param {string} text The document text.
 * @param {Array<{value: string, verdict: string|null}>} passages The passages.
 * @return {Array<{text: string, verdict: string|null}>} The segments.
 */
export function segmentsOf(text, passages) {
	const ranges = []
	const ordered = [...passages]
		.filter((passage) => String(passage.value || '') !== '')
		.sort((left, right) => right.value.length - left.value.length)
	for (const passage of ordered) {
		let from = text.indexOf(passage.value)
		while (from !== -1) {
			const to = from + passage.value.length
			if (ranges.every((range) => to <= range.from || from >= range.to)) {
				ranges.push({ from, to, verdict: passage.verdict || 'open' })
			}
			from = text.indexOf(passage.value, to)
		}
	}

	ranges.sort((left, right) => left.from - right.from)
	const segments = []
	let at = 0
	for (const range of ranges) {
		if (range.from > at) {
			segments.push({ text: text.slice(at, range.from), verdict: null })
		}
		segments.push({
			text: text.slice(range.from, range.to),
			verdict: range.verdict,
		})
		at = range.to
	}
	if (at < text.length) {
		segments.push({ text: text.slice(at), verdict: null })
	}

	return segments
}

export default {
	name: 'DocumentRedactionDialog',

	components: {
		Check,
		NcButton,
		NcDialog,
		NcSelect,
		NcTextField,
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

		/** Where the partly public documents are listed; `{caseId}` is filled in. */
		listUrl: {
			type: String,
			default: '/apps/dossiq/api/cases/{caseId}/redactions',
		},

		/** The routes of one document; `{caseId}` and `{documentRef}` are filled in. */
		detectUrl: {
			type: String,
			default:
				'/apps/dossiq/api/cases/{caseId}/redactions/{documentRef}/detect',
		},

		checkUrl: {
			type: String,
			default:
				'/apps/dossiq/api/cases/{caseId}/redactions/{documentRef}/check',
		},

		redactUrl: {
			type: String,
			default:
				'/apps/dossiq/api/cases/{caseId}/redactions/{documentRef}/redact',
		},

		uploadUrl: {
			type: String,
			default:
				'/apps/dossiq/api/cases/{caseId}/redactions/{documentRef}/upload',
		},

		/** What a passage type is called: `{ PERSON: 'Name', ... }`. */
		typeLabels: {
			type: Object,
			default: () => ({}),
		},

		/** Draw as the case's redaction page (route `/cases/:id/redact`) instead of a dialog. */
		asPage: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		return {
			VERDICTS,
			loading: true,
			loadError: '',
			documents: [],
			index: 0,
			text: '',
			passages: [],
			detecting: false,
			busy: false,
			error: '',
			notice: '',
			kase: null,
		}
	},

	computed: {
		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * The root's attributes: a full-size dialog, or a plain page section.
		 *
		 * @return {object} The attributes.
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		rootAttrs() {
			if (this.asPage) {
				return { 'data-testid': 'document-redaction-page' }
			}
			return { name: this.dialogName, size: 'full', 'data-testid': 'document-redaction-dialog' }
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		current() {
			return this.documents[this.index] || {}
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		dialogName() {
			if (!this.current.title) {
				return t('dossiq', 'Redact documents')
			}

			return t('dossiq', 'Redact: {title}', { title: this.current.title })
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		typeOptions() {
			return Object.keys(this.typeLabels || {}).map((type) => ({
				id: type,
				label: this.labelOf(type),
			}))
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		grounds() {
			return (this.current.weigeringsgronden || []).join(', ')
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		toRedact() {
			return this.passages
				.filter(
					(passage) =>
						passage.verdict === 'accept' || passage.verdict === 'adjust',
				)
				.map((passage) => ({
					...passage.entity,
					value: passage.value.trim(),
				}))
				.filter((entity) => entity.value !== '')
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		canRedact() {
			return (
				this.busy === false
				&& this.detecting === false
				&& this.passages.length > 0
				&& this.passages.every((passage) => passage.verdict !== null)
				&& this.toRedact.length > 0
			)
		},

		/** @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005 */
		segments() {
			return segmentsOf(this.text, this.passages)
		},
	},

	async mounted() {
		this.load()
		if (this.asPage) {
			this.kase = await fetchCaseSummary(this.targetCaseId)
		}
	},

	methods: {
		t,
		n,

		/**
		 * A configured url with the case and the current document filled in.
		 *
		 * @param {string} template The url.
		 * @return {string} The url to call.
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		urlOf(template) {
			return generateUrl(
				String(template)
					.replace('{caseId}', encodeURIComponent(this.targetCaseId))
					.replace(
						'{documentRef}',
						encodeURIComponent(String(this.current.documentRef || '')),
					),
			)
		},

		/**
		 * What a passage type is called.
		 *
		 * @param {string} type The type.
		 * @return {string} The label.
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		labelOf(type) {
			const label = this.typeLabels?.[type]
			return label ? t('dossiq', String(label)) : String(type || '')
		},

		/**
		 * What a verdict is called.
		 *
		 * @param {string} verdict The verdict.
		 * @return {string} The label.
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		verdictLabel(verdict) {
			return (
				{
					accept: t('dossiq', 'Agreed'),
					adjust: t('dossiq', 'Adjust'),
					skip: t('dossiq', 'Do not redact'),
				}[verdict] || ''
			)
		},

		/**
		 * Read the partly public documents and open the first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { data } = await axios.get(this.urlOf(this.listUrl))
				this.documents = data?.documents || []
			} catch (failure) {
				this.documents = []
				this.loadError = sentenceOf(
					failure,
					t('dossiq', 'The documents of this case could not be read.'),
				)
			} finally {
				this.loading = false
			}

			if (this.documents.length > 0) {
				await this.open(Math.min(this.index, this.documents.length - 1))
			}
		},

		/**
		 * Open one document: detect its passages, unless its redacted copy is recorded.
		 *
		 * @param {number} at The document's position.
		 * @return {Promise<void>}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
		 */
		async open(at) {
			this.index = at
			this.error = ''
			this.notice = ''
			this.text = ''
			this.passages = []
			if (this.current.redactedDocumentRef) {
				this.passages = this.passagesOf(
					this.current.redaction?.entities || [],
					'accept',
				)
				return
			}

			this.detecting = true
			try {
				const { data } = await axios.post(this.urlOf(this.detectUrl))
				this.text = String(data?.text || '')
				this.passages = this.passagesOf(
					data?.redaction?.entities || [],
					null,
				)
			} catch (failure) {
				this.error = sentenceOf(
					failure,
					t(
						'dossiq',
						'The passages of this document could not be found. Upload a redacted version instead.',
					),
				)
			} finally {
				this.detecting = false
			}
		},

		/**
		 * Passages from entities, each with a verdict.
		 *
		 * @param {Array<object>} entities The entities.
		 * @param {string|null} verdict The verdict each starts with.
		 * @return {Array<object>} The passages.
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
		 */
		passagesOf(entities, verdict) {
			return entities.map((entity) => ({
				entity,
				type: String(entity?.type || ''),
				value: String(entity?.value || ''),
				verdict,
			}))
		},

		/**
		 * Give one passage a verdict.
		 *
		 * @param {number} at The passage.
		 * @param {string} verdict The verdict.
		 * @return {void}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		setVerdict(at, verdict) {
			this.passages[at] = { ...this.passages[at], verdict }
		},

		/**
		 * Change the text of an adjusted passage.
		 *
		 * @param {number} at The passage.
		 * @param {string} value The new text.
		 * @return {void}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		adjust(at, value) {
			this.passages[at] = { ...this.passages[at], value: String(value) }
		},

		/**
		 * Give a passage the officer added its type.
		 *
		 * @param {number} at The passage.
		 * @param {{id: string}|null} option The chosen type.
		 * @return {void}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		retype(at, option) {
			const type = String(option?.id || 'UNKNOWN')
			const passage = this.passages[at]
			this.passages[at] = {
				...passage,
				type,
				entity: { ...passage.entity, type },
			}
		},

		/**
		 * Add a passage the detection missed.
		 *
		 * @return {void}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		addPassage() {
			this.passages.push({
				entity: { type: 'UNKNOWN', source: 'officer' },
				type: 'UNKNOWN',
				value: '',
				verdict: 'adjust',
			})
		},

		/**
		 * Record the check of the passages to redact, then ask for the redacted copy.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
		 */
		async redact() {
			if (this.canRedact === false) {
				return
			}

			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				await axios.post(this.urlOf(this.checkUrl), {
					entities: this.toRedact,
				})
				const { data } = await axios.post(this.urlOf(this.redactUrl))
				this.recorded(data)
			} catch (failure) {
				this.error = sentenceOf(
					failure,
					t(
						'dossiq',
						'The redacted copy could not be made. Upload a redacted version instead.',
					),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Upload a version redacted elsewhere.
		 *
		 * @param {FileList|null} files The chosen file.
		 * @return {Promise<void>}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-an-officer-can-upload-a-redacted-version-instead-req-drd-004
		 */
		async upload(files) {
			const file = files?.[0]
			if (!file) {
				return
			}

			const form = new FormData()
			form.append('file', file)
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				const { data } = await axios.post(this.urlOf(this.uploadUrl), form)
				this.recorded(data)
			} catch (failure) {
				this.error = sentenceOf(
					failure,
					t(
						'dossiq',
						'The redacted version could not be stored. Try again.',
					),
				)
			} finally {
				this.busy = false
				if (this.$refs.upload) {
					this.$refs.upload.value = ''
				}
			}
		},

		/**
		 * Show a recorded copy and ask the page to read the case again.
		 *
		 * @param {object} data The server's answer.
		 * @return {void}
		 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
		 */
		recorded(data) {
			this.documents[this.index] = {
				...this.current,
				redactedDocumentRef: String(data?.redactedDocumentRef || ''),
				redaction: data?.redaction || {},
			}
			this.notice = t(
				'dossiq',
				'The redacted copy is recorded. Only this copy will be published.',
			)
			emit(PAGE_REFRESH, { reason: 'document-redaction' })
		},
	},
}
</script>

<style scoped lang="scss">
.document-redaction {
	display: flex;
	flex-direction: column;
	gap: 16px;

	&--page {
		padding: 20px 28px 28px;
	}

	&__head {
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		align-items: flex-start;
		gap: 12px 24px;
	}

	&__heading {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 0;
	}

	&__title {
		margin: 0;
		font-size: 28px;
		font-weight: 700;
		line-height: 1.25;
	}

	&__crumbs {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		font-size: 14px;

		a {
			color: var(--color-primary-element);
			text-decoration: underline;
		}
	}

	&__empty {
		padding: 20px 22px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__bar {
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
	}

	&__buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__meta,
	&__muted {
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}

	&__columns {
		display: flex;
		flex-wrap: wrap;
		gap: 20px;
		align-items: flex-start;
	}

	&__document {
		flex: 999 1 520px;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 28px 32px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		font-family: Georgia, serif;
		font-size: 15px;
		line-height: 1.7;
	}

	&__body {
		margin: 0;
		white-space: pre-wrap;
	}

	&__mark {
		&--accept {
			background: var(--color-main-text);
			color: var(--color-main-text);
		}

		&--adjust,
		&--open {
			outline: 2px solid var(--color-warning-text);
			background: var(--color-warning-hover, var(--color-background-hover));
		}

		&--skip {
			text-decoration: line-through;
			text-decoration-color: var(--color-text-maxcontrast);
		}
	}

	&__proposal {
		flex: 1 1 300px;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 20px 22px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);

		h2 {
			margin: 0;
			font-size: 15px;
			font-weight: 600;
			color: var(--color-text-maxcontrast);
		}
	}

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__passage {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 10px 0;
		border-top: 1px solid var(--color-border);
	}

	&__line {
		display: flex;
		justify-content: space-between;
		gap: 8px;
	}

	&__verdicts {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__pill {
		display: inline-block;
		padding: 3px 10px;
		border-radius: 12px;
		font-size: 13px;
		font-weight: 600;
		white-space: nowrap;

		&--accept,
		&--done {
			background: var(--color-success-hover, var(--color-background-hover));
			color: var(--color-success-text);
		}

		&--adjust {
			background: var(--color-warning-hover, var(--color-background-hover));
			color: var(--color-warning-text);
		}

		&--skip {
			background: var(--color-background-hover);
			color: var(--color-text-maxcontrast);
		}
	}

	&__error {
		color: var(--color-error-text);
	}

	&__notice {
		color: var(--color-success-text);
	}
}
</style>

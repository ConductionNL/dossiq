<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	One dialog, three gestures on a Documents-tab selection — Mark final,
	Change confidentiality, Download ZIP — mirroring how BulkTransitionDialog
	holds four case-list gestures in one component rather than four. `mode`
	picks the fields, the request and the copy; the confirm/cancel shell and
	the busy/result handling are shared.

	Self-sufficient (documents-on-the-case task 2.2, the CnObjectListWidget
	swap): opened as a manifest `open-modal` `bulkActions` entry, which the
	widget hands `props.selectedIds` — see
	CnObjectListWidget.mappedBulkActions in @conduction/nextcloud-vue. There
	is no parent `DossierTab`/`BulkActionsBar` any more to own the request or
	the result reporting, so this dialog makes the call itself, the same
	shape `runBulk()` used to.

	Spec: openspec/specs/document-zaakdossier/spec.md
-->
<template>
	<NcDialog
		:name="title"
		data-testid="bulk-document-dialog"
		:noClose="busy"
		@closing="onClose">
		<div class="bulk-document-dialog">
			<p class="bulk-document-dialog__count">
				{{
					t('dossiq', '{count} document(s) selected', {
						count: selectedIds.length,
					})
				}}
			</p>

			<NcSelect
				v-if="mode === 'confidentiality'"
				v-model="level"
				data-testid="bulk-document-confidentiality"
				:inputLabel="t('dossiq', 'New confidentiality')"
				:options="classificationOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				:disabled="busy" />

			<p v-if="mode === 'zip'" class="bulk-document-dialog__intro">
				{{
					t(
						'dossiq',
						'A ZIP of the selected documents will download to your browser.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard
				v-if="results.length > 0"
				type="success"
				data-testid="bulk-document-results">
				{{
					t('dossiq', '{ok} of {total} succeeded', {
						ok: succeededCount,
						total: results.length,
					})
				}}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="onClose">
				{{ t('dossiq', busy ? 'Close' : 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="results.length === 0"
				variant="primary"
				data-testid="bulk-document-confirm"
				:disabled="busy || (mode === 'confidentiality' && !level)"
				@click="onConfirm">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { classificationOptions as buildClassificationOptions } from '../utils/dossierHelpers.js'

export default {
	name: 'BulkDocumentActionDialog',
	components: { NcButton, NcDialog, NcNoteCard, NcSelect },

	props: {
		/**
		 * The selected ZAAKINFORMATIEOBJECT ids, handed in by
		 * CnObjectListWidget's bulk-action dispatch.
		 *
		 * 🔴 THESE ARE JOIN IDS, NOT DOCUMENT IDS. The widget lists
		 * `zaakinformatieobject` and selects by `row.id`, so what arrives here
		 * identifies the link between a case and a document, not the document.
		 * Both bulk endpoints take informatieobject ids, so `resolveDocumentIds`
		 * maps them before either request goes out.
		 */
		selectedIds: {
			type: Array,
			default: () => [],
		},

		/** Which gesture this dialog runs. */
		mode: {
			type: String,
			default: 'mark-final',
			validator: (value) =>
				['mark-final', 'confidentiality', 'zip'].includes(value),
		},

		// May arrive as the unresolved `@objectId` token; see resolvedCaseId.
		// Only used by `zip`, which downloads scoped to one case.
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],
	data() {
		return {
			level: '',
			busy: false,
			error: '',
			results: [],
		}
	},

	computed: {
		/**
		 * Confidentiality dropdown options for the `confidentiality` mode.
		 *
		 * @return {Array} The classification options.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		classificationOptions() {
			return buildClassificationOptions(this.t.bind(this))
		},

		/**
		 * The case the `zip` mode downloads, resolved the same defensive way
		 * as BeschikkingComposerDialog / DocumentMetadataDialog: an
		 * `open-modal` action's `props` are forwarded verbatim, so a prop
		 * still holding an `@` token is not a case id — the route is.
		 *
		 * @return {string} The case id, or empty string.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},

		/**
		 * The dialog title for the active mode.
		 *
		 * @return {string} The title.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		title() {
			if (this.mode === 'confidentiality')
				return this.t('dossiq', 'Change confidentiality')
			if (this.mode === 'zip') return this.t('dossiq', 'Download ZIP')
			return this.t('dossiq', 'Mark as final')
		},

		/**
		 * The confirm button's label for the active mode.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		confirmLabel() {
			if (this.mode === 'zip') return this.t('dossiq', 'Download')
			return this.t('dossiq', 'Apply')
		},

		/**
		 * How many of the bulk endpoint's per-item results succeeded.
		 *
		 * @return {number} The success count.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		succeededCount() {
			return this.results.filter((r) => r && r.success !== false).length
		},
	},

	methods: {
		/**
		 * Run the gesture for the active `mode`.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		async onConfirm() {
			if (this.mode === 'mark-final')
				return this.runBulk(
					'/apps/dossiq/api/informatieobjecten/bulk/status',
					{ status: 'final' },
				)
			if (this.mode === 'confidentiality')
				return this.runBulk(
					'/apps/dossiq/api/informatieobjecten/bulk/metadata',
					{ metadata: { vertrouwelijkheidaanduiding: this.level } },
				)
			return this.downloadZip()
		},

		/**
		 * The informatieobject ids behind the selected join rows.
		 *
		 * 🔴 SENDING `selectedIds` STRAIGHT THROUGH IS A SILENT NO-OP, and it
		 * shipped that way. `/api/informatieobjecten/bulk/status` resolves each
		 * id in the `informatieobject` schema, and a `zaakinformatieobject` id
		 * is not in it, so every item came back a miss, the dialog rendered its
		 * per-item results as if something had happened, and both documents
		 * stayed `draft`. The e2e test that reads the stored status after the
		 * confirm is what caught it; the unit test did not, because it mocked
		 * the POST and asserted the ids this component had assumed.
		 *
		 * One GET per selected row is the honest cost of a list over the join.
		 * A selection is a handful of rows, and the widget hands over ids only,
		 * not the extended rows it already holds.
		 *
		 * A row that cannot be resolved is dropped rather than passed on, so a
		 * broken link never reaches an endpoint that would report it as a
		 * document failure.
		 *
		 * @return {Promise<Array<string>>} The document ids to act on.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		async resolveDocumentIds() {
			const resolved = []

			for (const joinId of this.selectedIds) {
				const url = generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}/{id}',
					{
						register: 'dossiq',
						schema: 'zaakinformatieobject',
						id: joinId,
					},
				)
				const { data } = await axios.get(url)
				const document = (data && data.informatieobject) || ''
				const id =
					typeof document === 'object' && document !== null
						? String(document.id || '')
						: String(document)

				if (id !== '') {
					resolved.push(id)
				}
			}

			return resolved
		},

		/**
		 * Run one of the two bulk mutation endpoints and report the per-item
		 * results, mirroring DossierTab's old `runBulk()`.
		 *
		 * @param {string} path The API path.
		 * @param {object} extra The mode-specific request body fields.
		 * @return {Promise<void>}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		async runBulk(path, extra) {
			this.busy = true
			this.error = ''
			try {
				const ids = await this.resolveDocumentIds()
				const { data } = await axios.post(generateUrl(path), {
					ids,
					...extra,
				})
				this.results = data.results || []

				// 🔴 A 200 IS NOT A RESULT. Both endpoints answer 200 with a
				// per-item list, and each entry carries its own `success`, so a
				// request where every single item failed came back 200 and this
				// said "Bulk action applied" over the top of it. That toast is
				// why nothing on screen contradicted the join-id bug for as long
				// as it shipped. The reader is told what actually happened now.
				if (this.results.length > 0 && this.succeededCount === 0) {
					this.error = this.t('dossiq', 'Bulk action changed nothing')
					showError(this.error)
				} else {
					showSuccess(
						this.t('dossiq', '{count} of {total} document(s) updated', {
							count: this.succeededCount,
							total: this.results.length,
						}),
					)
				}

				emit('cn:page:refresh')
			} catch {
				this.error = this.t('dossiq', 'Bulk action failed')
				showError(this.error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Download the selection as a ZIP, scoped to the current case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		async downloadZip() {
			if (this.resolvedCaseId === '') return
			this.busy = true
			this.error = ''
			try {
				const url = generateUrl(
					`/apps/dossiq/api/cases/${encodeURIComponent(this.resolvedCaseId)}/dossier/zip`,
				)
				const { data } = await axios.post(
					url,
					{ ids: this.selectedIds },
					{ responseType: 'blob' },
				)
				const objectUrl = window.URL.createObjectURL(data)
				const link = document.createElement('a')
				link.href = objectUrl
				link.download = `dossier-${this.resolvedCaseId}.zip`
				link.click()
				window.URL.revokeObjectURL(objectUrl)
				this.$emit('close')
			} catch {
				this.error = this.t('dossiq', 'ZIP export failed')
				showError(this.error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.bulk-document-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block-end: 12px;
}

.bulk-document-dialog__count {
	font-weight: 600;
}

.bulk-document-dialog__intro {
	color: var(--color-text-maxcontrast);
}
</style>

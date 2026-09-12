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

	Spec: openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-cnobjectlistwidget-supports-multi-select-and-bulk-actions
-->
<template>
	<NcDialog
		:name="title"
		data-testid="bulk-document-dialog"
		:noClose="busy"
		@closing="onClose">
		<div class="bulk-document-dialog">
			<p class="bulk-document-dialog__count">
				{{ t('dossiq', '{count} document(s) selected', { count: selectedIds.length }) }}
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
				{{ t('dossiq', 'A ZIP of the selected documents will download to your browser.') }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard v-if="results.length > 0" type="success" data-testid="bulk-document-results">
				{{ t('dossiq', '{ok} of {total} succeeded', { ok: succeededCount, total: results.length }) }}
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
		/** The selected informatieobject ids, handed in by CnObjectListWidget's bulk-action dispatch. */
		selectedIds: {
			type: Array,
			default: () => [],
		},

		/** Which gesture this dialog runs. */
		mode: {
			type: String,
			default: 'mark-final',
			validator: (value) => ['mark-final', 'confidentiality', 'zip'].includes(value),
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
		classificationOptions() {
			return buildClassificationOptions(this.t.bind(this))
		},

		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},

		title() {
			if (this.mode === 'confidentiality') return this.t('dossiq', 'Change confidentiality')
			if (this.mode === 'zip') return this.t('dossiq', 'Download ZIP')
			return this.t('dossiq', 'Mark as final')
		},

		confirmLabel() {
			if (this.mode === 'zip') return this.t('dossiq', 'Download')
			return this.t('dossiq', 'Apply')
		},

		succeededCount() {
			return this.results.filter((r) => r && r.success !== false).length
		},
	},

	methods: {
		async onConfirm() {
			if (this.mode === 'mark-final') return this.runBulk('/apps/dossiq/api/informatieobjecten/bulk/status', { status: 'final' })
			if (this.mode === 'confidentiality') return this.runBulk('/apps/dossiq/api/informatieobjecten/bulk/metadata', { metadata: { vertrouwelijkheidaanduiding: this.level } })
			return this.downloadZip()
		},

		/**
		 * Run one of the two bulk mutation endpoints and report the per-item
		 * results, mirroring DossierTab's old `runBulk()`.
		 *
		 * @param {string} path The API path.
		 * @param {object} extra The mode-specific request body fields.
		 * @return {Promise<void>}
		 */
		async runBulk(path, extra) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl(path), {
					ids: this.selectedIds,
					...extra,
				})
				this.results = data.results || []
				showSuccess(this.t('dossiq', 'Bulk action applied'))
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

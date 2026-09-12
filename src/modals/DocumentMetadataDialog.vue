<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal v-if="open" size="normal" @close="$emit('close')">
		<div class="dossier-metadata-dialog">
			<h2 class="dossier-metadata-dialog__title">
				{{ t('dossiq', 'Document metadata') }}
			</h2>

			<ul v-if="files.length > 0" class="dossier-metadata-dialog__files">
				<li
					v-for="(file, idx) in files"
					:key="idx"
					class="dossier-metadata-dialog__file">
					<span class="dossier-metadata-dialog__file-name">{{
						file.name
					}}</span>
					<NcProgressBar
						v-if="progress[idx] !== undefined"
						:value="progress[idx]"
						:error="errors[idx] === true" />
				</li>
			</ul>

			<NcSelect
				v-model="selectedType"
				:inputLabel="t('dossiq', 'Document type')"
				:options="typeOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				required />

			<NcSelect
				v-model="selectedDirection"
				data-testid="document-direction"
				:inputLabel="t('dossiq', 'Direction')"
				:options="directionOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false" />

			<NcSelect
				v-model="selectedClassification"
				:inputLabel="t('dossiq', 'Confidentiality')"
				:options="classificationOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				required />

			<NcTextField
				v-model="title"
				:label="t('dossiq', 'Title')"
				:placeholder="t('dossiq', 'Document title')" />

			<NcSelect
				v-model="keywords"
				data-testid="document-keywords"
				:inputLabel="t('dossiq', 'Keywords')"
				:placeholder="t('dossiq', 'Type a keyword and press enter')"
				:options="[]"
				:taggable="true"
				:pushTags="true"
				multiple />

			<NcTextArea
				v-model="description"
				:label="t('dossiq', 'Description')"
				:placeholder="t('dossiq', 'Optional description')" />

			<div class="dossier-metadata-dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!canSubmit || uploading"
					@click="submit">
					{{ t('dossiq', 'Upload') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcModal,
	NcProgressBar,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	classificationOptions as buildClassificationOptions,
	DEFAULT_DIRECTION,
	DOCUMENT_DIRECTIONS,
} from '../utils/dossierHelpers.js'

/**
 * Upload metadata dialog. Collects the required informatieobjecttype and
 * vertrouwelijkheidaanduiding (with the type's default), an editable titel and
 * an optional description, shared across all dropped/selected files, and
 * surfaces a per-file upload progress bar.
 *
 * Self-sufficient (documents-on-the-case task 2.2, the CnObjectListWidget
 * swap): opened as a manifest `open-modal` `dropZone`/upload action, which
 * hands it the dropped/picked `File[]` as `props.files` and resolves no
 * other tokens — so, like BeschikkingComposerDialog, this dialog owns
 * fetching the type catalog and performing the upload itself, rather than
 * relying on a parent tab component to do it and pass the results down as
 * props. `caseId` is read the same defensive way: the prop when it does not
 * still hold the unresolved `@objectId` token, the route otherwise.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
 * @spec openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-a-click-to-upload-button-rides-the-declared-dropzone-action
 */
export default {
	name: 'DocumentMetadataDialog',
	components: {
		NcButton,
		NcModal,
		NcProgressBar,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		files: {
			type: Array,
			default: () => [],
		},

		// May arrive as the unresolved `@objectId` token; see resolvedCaseId.
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'submit'],
	data() {
		return {
			selectedType: '',
			selectedClassification: '',
			selectedDirection: DEFAULT_DIRECTION,
			keywords: [],
			title: '',
			description: '',
			types: [],
			uploading: false,
			progress: {},
			errors: {},
		}
	},

	computed: {
		/**
		 * Dropdown options for the document type catalog.
		 *
		 * @return {Array} The type options.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		typeOptions() {
			return this.types.map((type) => ({
				id: type.id || type.uuid,
				label: type.description || type.id,
				vertrouwelijkheidaanduiding:
					type.vertrouwelijkheidaanduiding || 'intern',
			}))
		},

		/**
		 * Confidentiality dropdown options (ordered lowest to highest), shared
		 * with the bulk confidentiality-change dialog.
		 *
		 * @return {Array} The classification options.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		classificationOptions() {
			return buildClassificationOptions(this.t.bind(this))
		},

		/**
		 * The case this dialog files documents on.
		 *
		 * An `open-modal` action's `props` are forwarded verbatim, so a prop
		 * still holding an `@` token is not a case id — the route is (mirrors
		 * BeschikkingComposerDialog.resolvedCaseId).
		 *
		 * @return {string} The case id, or empty string.
		 * @spec openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-a-click-to-upload-button-rides-the-declared-dropzone-action
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},

		/**
		 * The typed keywords as the schema wants them: plain strings, trimmed,
		 * deduplicated, each at most 64 characters.
		 *
		 * A taggable NcSelect hands back whatever the person typed, and (when
		 * an option is picked rather than typed) an option OBJECT rather than a
		 * string. Sending either straight to a `array of string` property is a
		 * 400 nobody sees until they press Upload.
		 *
		 * @return {string[]} The keywords to save.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		normalisedKeywords() {
			const seen = []
			this.keywords.forEach((entry) => {
				const raw =
					typeof entry === 'string' ? entry : ((entry || {}).label ?? '')
				const keyword = String(raw).trim().slice(0, 64)
				if (keyword !== '' && !seen.includes(keyword)) {
					seen.push(keyword)
				}
			})
			return seen
		},

		/**
		 * Direction options, in the order the enum declares them.
		 *
		 * @return {Array} The direction options.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		directionOptions() {
			const labels = {
				incoming: this.t('dossiq', 'Incoming'),
				outgoing: this.t('dossiq', 'Outgoing'),
				internal: this.t('dossiq', 'Internal'),
			}
			return DOCUMENT_DIRECTIONS.map((direction) => ({
				id: direction,
				label: labels[direction],
			}))
		},

		/**
		 * Whether the required fields are filled.
		 *
		 * @return {boolean} True when type and classification are selected.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		canSubmit() {
			return this.selectedType !== '' && this.selectedClassification !== ''
		},
	},

	watch: {
		/**
		 * When the type changes, default the classification from the type.
		 *
		 * @param {string} newType The newly selected type id.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		selectedType(newType) {
			const match = this.typeOptions.find((option) => option.id === newType)
			if (match && this.selectedClassification === '') {
				this.selectedClassification = match.vertrouwelijkheidaanduiding
			}
		},

		/**
		 * Pre-fill the title from the first filename when files change.
		 *
		 * @param {Array} files The new file list.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		files(files) {
			if (files.length === 1 && this.title === '') {
				this.title = files[0].name
			}
		},

		/**
		 * Load the type catalog the moment the dialog opens — mirrors
		 * BeschikkingComposerDialog's `open` watcher, since this dialog is now
		 * self-sufficient rather than fed props by a parent tab.
		 *
		 * @param {boolean} isOpen Whether the dialog is showing.
		 * @spec openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-a-click-to-upload-button-rides-the-declared-dropzone-action
		 */
		open: {
			immediate: true,
			handler(isOpen) {
				if (isOpen) {
					this.fetchTypes()
				}
			},
		},
	},

	methods: {
		/**
		 * Fetch the informatieobjecttype catalog for the type picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		async fetchTypes() {
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/dossiq/informatieobjecttype?_limit=200',
				)
				const { data } = await axios.get(url)
				this.types = data.results || data.objects || data || []
			} catch {
				this.types = []
			}
		},

		/**
		 * Upload every pending file with the shared metadata, per-file
		 * progress, then close and signal the page to refetch.
		 *
		 * Self-sufficient (see the class doc comment): this used to be an
		 * emitted `submit` event a parent `DossierTab` turned into the POSTs
		 * below; there is no such parent once this dialog is opened as a
		 * manifest `open-modal` action, so it makes the request itself.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-a-click-to-upload-button-rides-the-declared-dropzone-action
		 */
		async submit() {
			if (!this.canSubmit || this.resolvedCaseId === '') {
				return
			}
			const metadata = {
				informatieobjecttype: this.selectedType,
				vertrouwelijkheidaanduiding: this.selectedClassification,
				// The schema default, spelled out rather than left to the
				// server: an upload that names no direction is Internal, and a
				// blank column would read as "nobody knows" instead.
				direction: this.selectedDirection || DEFAULT_DIRECTION,
				keywords: this.normalisedKeywords,
				title: this.title,
				description: this.description,
			}
			this.uploading = true
			this.progress = {}
			this.errors = {}
			let anySuccess = false
			for (let index = 0; index < this.files.length; index++) {
				const file = this.files[index]
				const form = new FormData()
				form.append('files', file)
				form.append('metadata', JSON.stringify(metadata))
				try {
					this.progress = { ...this.progress, [index]: 0 }
					const url = generateUrl(
						`/apps/dossiq/api/cases/${encodeURIComponent(this.resolvedCaseId)}/dossier`,
					)
					await axios.post(url, form, {
						headers: { 'Content-Type': 'multipart/form-data' },
						onUploadProgress: (event) => {
							if (event.total) {
								this.progress = {
									...this.progress,
									[index]: Math.round((event.loaded / event.total) * 100),
								}
							}
						},
					})
					this.progress = { ...this.progress, [index]: 100 }
					anySuccess = true
				} catch {
					this.errors = { ...this.errors, [index]: true }
				}
			}
			this.uploading = false
			if (anySuccess) {
				showSuccess(this.t('dossiq', 'Documents uploaded'))
				// The widget fetched its rows before this upload landed; without
				// this signal the new document is on the server and invisible on
				// screen until something else happens to refetch.
				emit('cn:page:refresh')
				this.$emit('submit', metadata)
				this.$emit('close')
			} else {
				showError(this.t('dossiq', 'Upload failed'))
			}
		},
	},
}
</script>

<style scoped>
.dossier-metadata-dialog {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.dossier-metadata-dialog__title {
	margin: 0 0 8px;
}

.dossier-metadata-dialog__files {
	list-style: none;
	padding: 0;
	margin: 0;
}

.dossier-metadata-dialog__file {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
}

.dossier-metadata-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}
</style>

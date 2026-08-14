<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal v-if="open" size="normal" @close="$emit('close')">
		<div class="dossier-metadata-dialog">
			<h2 class="dossier-metadata-dialog__title">
				{{ t('procest', 'Document metadata') }}
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
				:inputLabel="t('procest', 'Document type')"
				:options="typeOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				required />

			<NcSelect
				v-model="selectedClassification"
				:inputLabel="t('procest', 'Confidentiality')"
				:options="classificationOptions"
				:reduce="(option) => option.id"
				label="label"
				:clearable="false"
				required />

			<NcTextField
				v-model="titel"
				:label="t('procest', 'Title')"
				:placeholder="t('procest', 'Document title')" />

			<NcTextArea
				v-model="description"
				:label="t('procest', 'Description')"
				:placeholder="t('procest', 'Optional description')" />

			<div class="dossier-metadata-dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!canSubmit || uploading"
					@click="submit">
					{{ t('procest', 'Upload') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcProgressBar,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'

/**
 * Upload metadata dialog. Collects the required informatieobjecttype and
 * vertrouwelijkheidaanduiding (with the type's default), an editable titel and
 * an optional description, shared across all dropped/selected files, and
 * surfaces a per-file upload progress bar.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
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

		types: {
			type: Array,
			default: () => [],
		},

		progress: {
			type: Object,
			default: () => ({}),
		},

		errors: {
			type: Object,
			default: () => ({}),
		},

		uploading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'submit'],
	data() {
		return {
			selectedType: '',
			selectedClassification: '',
			titel: '',
			description: '',
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
		 * Confidentiality dropdown options (ordered lowest to highest).
		 *
		 * @return {Array} The classification options.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		classificationOptions() {
			return [
				{ id: 'openbaar', label: this.t('procest', 'Public') },
				{
					id: 'beperkt_openbaar',
					label: this.t('procest', 'Limited public'),
				},
				{ id: 'intern', label: this.t('procest', 'Internal') },
				{
					id: 'zaakvertrouwelijk',
					label: this.t('procest', 'Case-confidential'),
				},
				{ id: 'vertrouwelijk', label: this.t('procest', 'Confidential') },
				{ id: 'confidentieel', label: this.t('procest', 'Restricted') },
				{ id: 'geheim', label: this.t('procest', 'Secret') },
				{ id: 'zeer_geheim', label: this.t('procest', 'Top secret') },
			]
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
			if (files.length === 1 && this.titel === '') {
				this.titel = files[0].name
			}
		},
	},

	methods: {
		/**
		 * Emit the collected shared metadata for upload.
		 *
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		submit() {
			if (!this.canSubmit) {
				return
			}
			this.$emit('submit', {
				informatieobjecttype: this.selectedType,
				vertrouwelijkheidaanduiding: this.selectedClassification,
				titel: this.titel,
				description: this.description,
			})
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

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
						:error="Boolean(errors[idx])" />
					<!-- The reason, in the server's words, under the bar that went
					     red. Without it a full bar that then turned red read as an
					     upload that finished, and the dialog that stayed open read
					     as a dialog that forgot to close. -->
					<span
						v-if="typeof errors[idx] === 'string' && errors[idx] !== ''"
						class="dossier-metadata-dialog__file-error"
						role="alert">
						{{ errors[idx] }}
					</span>
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
					type="primary"
					:disabled="!canSubmit || uploading"
					@click="submit">
					{{ t('dossiq', 'Upload') }}
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
import { DEFAULT_DIRECTION, DOCUMENT_DIRECTIONS } from '../utils/dossierHelpers.js'

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
			selectedDirection: DEFAULT_DIRECTION,
			keywords: [],
			title: '',
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
				{ id: 'openbaar', label: this.t('dossiq', 'Public') },
				{
					id: 'beperkt_openbaar',
					label: this.t('dossiq', 'Limited public'),
				},
				{ id: 'intern', label: this.t('dossiq', 'Internal') },
				{
					id: 'zaakvertrouwelijk',
					label: this.t('dossiq', 'Case-confidential'),
				},
				{ id: 'vertrouwelijk', label: this.t('dossiq', 'Confidential') },
				{ id: 'confidentieel', label: this.t('dossiq', 'Restricted') },
				{ id: 'geheim', label: this.t('dossiq', 'Secret') },
				{ id: 'zeer_geheim', label: this.t('dossiq', 'Top secret') },
			]
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
				// The schema default, spelled out rather than left to the
				// server: an upload that names no direction is Internal, and a
				// blank column would read as "nobody knows" instead.
				direction: this.selectedDirection || DEFAULT_DIRECTION,
				keywords: this.normalisedKeywords,
				title: this.title,
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
.dossier-metadata-dialog__file-error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>

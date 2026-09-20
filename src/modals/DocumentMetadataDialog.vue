<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="dossier-metadata-dialog">
			<h2 class="dossier-metadata-dialog__title">
				{{
					isEdit
						? t('dossiq', 'Document properties')
						: t('dossiq', 'Document metadata')
				}}
			</h2>

			<!-- Edit mode: opened from a file row of the Files tab, on the
			     record that file already has. -->
			<p
				v-if="isEdit"
				class="dossier-metadata-dialog__file-name"
				data-testid="document-properties-file">
				{{ fileName }}
			</p>
			<p
				v-if="isEdit && recordMissing"
				class="dossier-metadata-dialog__missing"
				data-testid="document-properties-missing">
				{{
					t(
						'dossiq',
						'This file has no document record yet; saving creates one.',
					)
				}}
			</p>

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
				v-if="allowed.sender"
				v-model="sender"
				data-testid="document-sender"
				:inputLabel="t('dossiq', 'Sender')"
				:placeholder="senderPlaceholder"
				:options="partyOptions"
				label="label"
				:disabled="partyOptions.length === 0" />

			<NcSelect
				v-if="allowed.recipients"
				v-model="recipients"
				data-testid="document-recipients"
				:inputLabel="t('dossiq', 'Recipients')"
				:placeholder="recipientsPlaceholder"
				:options="partyOptions"
				label="label"
				:disabled="partyOptions.length === 0"
				multiple />

			<p
				v-if="partyOptions.length === 0"
				class="dossier-metadata-dialog__missing"
				data-testid="document-no-parties">
				{{
					t(
						'dossiq',
						'Add a party on the People tab to say who this document is from or to.',
					)
				}}
			</p>

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

			<dl
				v-if="isEdit"
				class="dossier-metadata-dialog__integrity"
				data-testid="document-integrity">
				<dt>{{ t('dossiq', 'Scan') }}</dt>
				<dd data-testid="document-scan-verdict">{{ scanLabel }}</dd>
				<dt>{{ t('dossiq', 'Checksum') }}</dt>
				<dd data-testid="document-hash">{{ hashLabel }}</dd>
			</dl>

			<section
				v-if="isEdit && recordId"
				class="dossier-metadata-dialog__approval"
				data-testid="document-approval-chain">
				<h3>{{ t('dossiq', 'Approval chain') }}</h3>
				<!--
				  approval-chain-on-the-document REQ-BVL-004. decidiq holds the
				  route; dossiq shows its leaf here, on the DOCUMENT record,
				  because the leaf takes a register, a schema and an object id
				  and a file is not an object. The section is beside the
				  metadata the same person maintains, which is why it is here
				  rather than on a tab of its own.
				-->
				<ApprovalChainLeafTab
					register="dossiq"
					schema="informatieobject"
					:objectId="recordId"
					:title="title" />
			</section>

			<div class="dossier-metadata-dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!canSubmit || uploading"
					data-testid="document-properties-save"
					@click="submit">
					{{ isEdit ? t('dossiq', 'Save') : t('dossiq', 'Upload') }}
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
import ApprovalChainLeafTab from '../components/tabs/ApprovalChainLeafTab.vue'
import { fetchCaseParties } from '../services/caseParties.js'
import {
	allowedFor,
	partyOptions as buildPartyOptions,
	identifiersOf,
	selectedOptions,
} from '../services/documentCorrespondents.js'
import { scanVerdictLabel } from '../services/scanVerdict.js'
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
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export default {
	name: 'DocumentMetadataDialog',
	components: {
		ApprovalChainLeafTab,
		NcButton,
		NcModal,
		NcProgressBar,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		files: {
			type: Array,
			default: () => [],
		},

		// May arrive as the unresolved `@objectId` token; see resolvedCaseId.
		caseId: {
			type: String,
			default: '',
		},

		/**
		 * The Nextcloud file id of an EXISTING file on the case: the Files
		 * tab's Document properties action opens the dialog on that file's
		 * record (documents-live-on-the-case). Zero means an upload.
		 */
		fileId: {
			type: [Number, String],
			default: 0,
		},

		/** The existing file's name, shown in edit mode. */
		fileName: {
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
			/** The parties listing, or null when OpenRegister could not answer. */
			parties: null,
			/** The picked sender, as the picker's own option object. */
			sender: null,
			/** The picked addressees, as the picker's own option objects. */
			recipients: [],
			uploading: false,
			progress: {},
			errors: {},
			// Edit mode: the record the file already has, or null.
			record: null,
			recordMissing: false,
			// What files_antivirus recorded for this file, or null until the
			// read lands. Never defaulted to a clean verdict.
			scanVerdict: null,
		}
	},

	computed: {
		/**
		 * The informatieobject this dialog is editing, by id.
		 *
		 * Empty while the record is still being resolved, and for a file that
		 * has no informatieobject behind it at all. The approval section is
		 * hidden in both cases rather than rendered against an empty id, which
		 * the leaf would answer with a timeline of nothing: an empty timeline
		 * reads as "nobody has approved anything", and that is a claim about
		 * the document that nobody made.
		 *
		 * @return {string} The record id, or an empty string.
		 * @spec openspec/changes/archive/2026-09-20-approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
		 */
		recordId() {
			return String(this.record?.id || this.record?.['@self']?.id || '')
		},

		/**
		 * What the scanner recorded about this file, as a sentence.
		 *
		 * @return {string} The verdict.
		 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
		 */
		scanLabel() {
			return scanVerdictLabel(this.scanVerdict)
		},

		/**
		 * The checksum of the file content, or a sentence saying there is none.
		 *
		 * The hash sits beside the verdict because the two answer one
		 * question together: whether this is the file it says it is, and
		 * whether anyone has checked it.
		 *
		 * @return {string} The hash, with its algorithm, or a sentence.
		 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
		 */
		hashLabel() {
			const integrity = this.record?.integrity || {}
			const value = String(integrity.value || '')
			if (value === '') {
				return t('dossiq', 'Not recorded')
			}
			const algorithm = String(integrity.algorithm || 'sha256')
			return `${algorithm}: ${value}`
		},

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
		 * The parties of the case, each offered once.
		 *
		 * @return {Array} The picker options.
		 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
		 */
		partyOptions() {
			return buildPartyOptions(this.parties)
		},

		/**
		 * Which correspondent fields this direction lets a document carry.
		 *
		 * The server drops the contradiction either way; this stops offering
		 * it, so nobody picks a sender on a letter that went out and watches
		 * it disappear on save.
		 *
		 * @return {object} `{sender, recipients}`.
		 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
		 */
		allowed() {
			return allowedFor(this.selectedDirection)
		},

		/**
		 * What the empty sender picker says.
		 *
		 * @return {string} The placeholder.
		 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
		 */
		senderPlaceholder() {
			return this.partyOptions.length === 0
				? this.t('dossiq', 'No parties on this case yet')
				: this.t('dossiq', 'Pick the party this document came from')
		},

		/**
		 * What the empty addressees picker says.
		 *
		 * @return {string} The placeholder.
		 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
		 */
		recipientsPlaceholder() {
			return this.partyOptions.length === 0
				? this.t('dossiq', 'No parties on this case yet')
				: this.t('dossiq', 'Pick the parties this document went to')
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

		/**
		 * Whether the dialog edits an existing file's record rather than
		 * uploading new files.
		 *
		 * @return {boolean} True with a file id.
		 * @spec openspec/specs/document-projection/spec.md
		 */
		isEdit() {
			return Number(this.fileId) > 0
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

	/**
	 * A mounted dialog is an open one: the registry mounts this component
	 * when the Files tab's Document properties action (or any other
	 * `open-modal` action) names it, with the action's props and nothing
	 * else, and unmounts it on close. So the type catalog and, on a file,
	 * its record load here rather than behind an `open` prop nobody sets
	 * (measured 2026-09-13: a false `open` default left the modal
	 * unrendered with no warning anywhere).
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	created() {
		this.fetchTypes()
		this.loadParties()
		if (this.isEdit) {
			this.loadRecord()
			this.loadScanVerdict()
		}
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
		 * Read the parties of the case, which are the whole vocabulary the
		 * two correspondent pickers offer.
		 *
		 * The SAME listing the People tab renders (openregister#3761), so a
		 * party picked here and a party shown there cannot drift apart. A
		 * failed read leaves the pickers empty and the dialog says so; it does
		 * not fall back to a free-text field, because a typed name is exactly
		 * what this change removes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
		 */
		async loadParties() {
			this.parties = await fetchCaseParties(this.resolvedCaseId)
			this.applyStoredCorrespondents()
		},

		/**
		 * Show the correspondents the record already stores, once both the
		 * record and the parties have arrived.
		 *
		 * Both reads are in flight at once and either may land first, so this
		 * runs from both rather than from whichever one happens to be slower.
		 *
		 * @return {void}
		 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
		 */
		applyStoredCorrespondents() {
			if (this.record === null) {
				return
			}
			const options = this.partyOptions
			this.sender = selectedOptions(this.record.sender, options)[0] || null
			this.recipients = selectedOptions(this.record.recipients, options)
		},

		/**
		 * Edit mode: read the record the file has and fill the form from it.
		 *
		 * The case's dossier list is the one endpoint that carries every
		 * record with its `fileId`; the projection listener may not have run
		 * yet for a file dropped a moment ago, in which case the form starts
		 * from the defaults and saving creates the record.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/document-projection/spec.md
		 */
		async loadRecord() {
			this.record = null
			this.recordMissing = false
			if (this.resolvedCaseId === '') {
				return
			}
			try {
				const url = generateUrl(
					`/apps/dossiq/api/cases/${encodeURIComponent(this.resolvedCaseId)}/dossier`,
				)
				const { data } = await axios.get(url)
				const rows = Array.isArray(data?.informatieobjecten)
					? data.informatieobjecten
					: []
				const record =
					rows.find((row) => Number(row.fileId) === Number(this.fileId))
					|| null
				this.record = record
				this.recordMissing = record === null
				if (record === null) {
					if (this.title === '') {
						this.title = this.fileName.replace(/\.[^.]+$/, '')
					}
					return
				}
				this.selectedType = String(record.informatieobjecttype || '')
				this.selectedClassification = String(
					record.vertrouwelijkheidaanduiding || '',
				)
				this.selectedDirection = String(
					record.direction || DEFAULT_DIRECTION,
				)
				this.keywords = Array.isArray(record.keywords)
					? [...record.keywords]
					: []
				this.title = String(record.title || '')
				this.description = String(record.description || '')
				this.applyStoredCorrespondents()
			} catch {
				this.recordMissing = true
			}
		},

		/**
		 * Read what the virus scanner recorded for this file.
		 *
		 * A read that fails leaves the verdict null, and null reads Not
		 * scanned. It never reads clean, because an unanswered question is
		 * not a cleared file (company ADR-102).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
		 */
		async loadScanVerdict() {
			this.scanVerdict = null
			if (!this.fileId) {
				return
			}
			try {
				const url = generateUrl(
					`/apps/dossiq/api/files/${encodeURIComponent(this.fileId)}/scan`,
				)
				const { data } = await axios.get(url)
				this.scanVerdict = data ?? null
			} catch {
				// No scanner, no permission, no answer. All three read the
				// same, and none of them reads clean.
			}
		},

		/**
		 * Edit mode: save the form onto the file's record.
		 *
		 * Only a record that exists is patched. A file without one yet (the
		 * projection listener has not run for it) cannot be saved from here;
		 * the dialog says so, and the record appears on the next write.
		 *
		 * @param {object} metadata The form as metadata.
		 * @return {Promise<boolean>} True when saved.
		 * @spec openspec/specs/document-projection/spec.md
		 */
		async saveRecord(metadata) {
			if (this.record === null) {
				return false
			}
			const id = this.record.id || this.record['@self']?.id || ''
			if (id === '') {
				return false
			}
			try {
				const url = generateUrl(
					`/apps/dossiq/api/informatieobjecten/${encodeURIComponent(id)}`,
				)
				await axios.patch(url, metadata)
				return true
			} catch {
				return false
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
		 * @spec openspec/specs/document-zaakdossier/spec.md
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
				// Identifiers, never labels. The picker hands back its option
				// object, and posting that would store a display name nothing
				// can filter on.
				sender: this.allowed.sender
					? identifiersOf(this.sender)[0] || ''
					: '',

				recipients: this.allowed.recipients
					? identifiersOf(this.recipients)
					: [],
			}
			this.uploading = true
			this.progress = {}
			this.errors = {}
			if (this.isEdit) {
				const saved = await this.saveRecord(metadata)
				this.uploading = false
				if (saved) {
					showSuccess(this.t('dossiq', 'Document properties saved'))
					emit('cn:page:refresh')
					this.$emit('submit', metadata)
					this.$emit('close')
				} else {
					showError(
						this.t('dossiq', 'Document properties could not be saved'),
					)
				}
				return
			}
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
									[index]: Math.round(
										(event.loaded / event.total) * 100,
									),
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

.dossier-metadata-dialog__file-name {
	font-weight: bold;
	margin: 0;
}

.dossier-metadata-dialog__missing {
	color: var(--color-text-maxcontrast);
	margin: 0;
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

.dossier-metadata-dialog__integrity {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin-top: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.dossier-metadata-dialog__integrity dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.dossier-metadata-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}
</style>

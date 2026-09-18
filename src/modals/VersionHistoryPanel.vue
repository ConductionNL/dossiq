<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal v-if="open" size="normal" @close="$emit('close')">
		<div class="dossier-version-panel">
			<h4 class="dossier-version-panel__title">
				{{ t('dossiq', 'Version history') }}
			</h4>

			<p v-if="documentName !== ''" class="dossier-version-panel__document">
				{{ documentName }}
			</p>

			<NcEmptyContent
				v-if="!loading && versions.length === 0"
				:name="t('dossiq', 'No previous versions')">
				<template #icon>
					<History :size="20" />
				</template>
			</NcEmptyContent>

			<NcLoadingIcon v-if="loading" :size="24" />

			<ul v-if="versions.length > 0" class="dossier-version-panel__list">
				<li
					v-for="version in versions"
					:key="version.id"
					class="dossier-version-panel__item">
					<div class="dossier-version-panel__info">
						<span class="dossier-version-panel__number"
							>{{ t('dossiq', 'Version') }} {{ version.number }}</span
						>
						<span class="dossier-version-panel__meta">
							{{ formatDate(version.timestamp) }} ·
							{{ version.author || t('dossiq', 'Unknown') }}
						</span>
					</div>
					<div class="dossier-version-panel__actions">
						<NcButton
							variant="tertiary"
							@click="downloadVersion(version)">
							{{ t('dossiq', 'Download') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="restoreDisabled"
							:title="
								restoreDisabled
									? t(
											'dossiq',
											'Final documents cannot be modified',
										)
									: ''
							"
							@click="restoreVersion(version)">
							{{ t('dossiq', 'Restore') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</div>
	</NcModal>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'

/**
 * Version-history modal for one dossier document, over the Nextcloud Files
 * versions WebDAV API. Each version is downloadable; the restore action is
 * disabled when the informatieobject status is definitief (mirroring the
 * server-side immutability rule).
 *
 * TWO HOSTS, BECAUSE THE FIRST ONE WAS RETIRED. It was written for the
 * Documents tab's object-list, which handed it `props.row`: the raw
 * `zaakinformatieobject` row with `informatieobject` inlined by
 * `content.extend`. That tab went away with documents-live-on-the-case on
 * 2026-09-13 and nothing replaced the entry point, so the panel sat in the
 * registry, named by no manifest, for five days.
 *
 * Its host now is the Files tab (`case-files`), whose row actions hand a
 * `fileId` and a `fileName` and nothing else. So the panel takes either
 * shape: a `row` when one is given, a `fileId` otherwise. With only a
 * `fileId` it reads the case's dossier listing to find the record, the same
 * one endpoint DocumentMetadataDialog reads for the same reason.
 *
 * WHY RESTORE FAILS CLOSED. Restore is disabled on a document whose status is
 * `final`, mirroring the server-side immutability rule. When the panel is
 * opened from a file whose record it could not read, it does not know the
 * status, and it disables restore rather than offering it. An unknown status
 * that reads as "not final" would let a handler overwrite a definitive
 * document, which is the one outcome this rule exists to prevent.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export default {
	name: 'VersionHistoryPanel',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		History,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** The clicked zaakinformatieobject row, with informatieobject extended in. */
		row: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The Nextcloud file id, as the Files tab's row actions hand it.
		 * Used when no `row` is given.
		 */
		fileId: {
			type: [Number, String],
			default: 0,
		},

		/** The file's name, shown while the record is still being read. */
		fileName: {
			type: String,
			default: '',
		},

		/** May arrive as the unresolved `@objectId` token; see resolvedCaseId. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],
	data() {
		return {
			versions: [],
			loading: false,
			/** The informatieobject read from the dossier listing, or null. */
			record: null,
			/** Whether the record read has finished, successfully or not. */
			recordRead: false,
		}
	},

	computed: {
		/**
		 * The informatieobject the version history belongs to.
		 *
		 * @return {object} The referenced informatieobject, or an empty object.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		document() {
			const informatieobject = this.row && this.row.informatieobject
			if (informatieobject && typeof informatieobject === 'object') {
				return informatieobject
			}
			// Opened from the Files tab: the record read from the dossier
			// listing, or just the file id until that read lands.
			if (this.record !== null) {
				return this.record
			}
			return Number(this.fileId) > 0 ? { fileId: this.fileId } : {}
		},

		/**
		 * The document's name, so a panel opened from a file row says which
		 * file it is showing the history of.
		 *
		 * @return {string} The name, or an empty string.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		documentName() {
			return String(
				this.document.title || this.document.name || this.fileName || '',
			)
		},

		/**
		 * The case this panel is reading a document of.
		 *
		 * @return {string} The case id, or an empty string.
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
		 * The signed-in user id, for the versions DAV path.
		 *
		 * @return {string} The user id, or empty string.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		userId() {
			const user = getCurrentUser()
			return user ? user.uid : ''
		},

		/**
		 * Whether the restore action is disabled (definitief documents).
		 *
		 * @return {boolean} True when restore must be blocked.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		restoreDisabled() {
			if (this.document.status === 'final') {
				return true
			}
			// Opened on a file whose record could not be read: the status is
			// unknown, so restore is refused rather than offered. See the
			// header.
			if (this.row && this.row.informatieobject) {
				return false
			}
			return this.record === null
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Fetch versions the moment the modal opens.
			 *
			 * @param {boolean} isOpen Whether the modal is showing.
			 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
			 */
			async handler(isOpen) {
				if (isOpen === false) {
					return
				}
				await this.loadRecord()
				await this.fetchVersions()
			},
		},
	},

	methods: {
		/**
		 * Fetch the document's versions from the Nextcloud versions API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		async loadRecord() {
			this.record = null
			this.recordRead = false
			// A row carries the record already; nothing to read.
			if (this.row && this.row.informatieobject) {
				this.recordRead = true
				return
			}
			if (Number(this.fileId) <= 0 || this.resolvedCaseId === '') {
				this.recordRead = true
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
				this.record =
					rows.find((row) => Number(row.fileId) === Number(this.fileId)) || null
			} catch {
				// Leaves `record` null, which disables restore. See the header.
				this.record = null
			} finally {
				this.recordRead = true
			}
		},

		/**
		 * Fetch the document's versions from the Nextcloud versions API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		async fetchVersions() {
			if (!this.document.fileId || !this.userId) {
				this.versions = []
				return
			}
			this.loading = true
			try {
				// 🔴 `generateRemoteUrl`, NOT `generateUrl` (dossiq#2477). `generateUrl`
				// builds an index.php-routed APP url: where the front controller is
				// inactive it prefixes `/index.php`, so this PROPFIND went to
				// `/index.php/remote.php/dav/versions/…`, which routes nowhere. The
				// catch below turns that into an empty list, so the panel silently
				// said "No previous versions" on every such instance — including the
				// `php -S` instance this app's own E2E job runs on.
				const url = generateRemoteUrl(
					`dav/versions/${this.userId}/versions/${this.document.fileId}`,
				)
				const { data } = await axios.request({
					method: 'PROPFIND',
					url,
					headers: { Depth: '1' },
				})
				this.versions = this.parseVersions(data)
			} catch {
				this.versions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Parse the WebDAV multistatus response into a version list.
		 *
		 * @param {string} xml The PROPFIND response body.
		 * @return {Array} The parsed versions.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		parseVersions(xml) {
			if (typeof xml !== 'string' || xml === '') {
				return []
			}
			const parser = new DOMParser()
			const doc = parser.parseFromString(xml, 'application/xml')
			const responses = Array.from(
				doc.getElementsByTagNameNS('DAV:', 'response'),
			)
			const versions = []
			responses.forEach((node, index) => {
				const href = node.getElementsByTagNameNS('DAV:', 'href')[0]
				const lastModified = node.getElementsByTagNameNS(
					'DAV:',
					'getlastmodified',
				)[0]
				if (
					!href
					|| href.textContent.endsWith(
						'/versions/' + this.document.fileId + '/',
					)
				) {
					return
				}
				versions.push({
					id: href.textContent,
					number: responses.length - index,
					timestamp: lastModified ? lastModified.textContent : '',
					author: '',
				})
			})
			return versions
		},

		/**
		 * Format an ISO/HTTP date for display.
		 *
		 * @param {string} dateStr The date string.
		 * @return {string} The localised date.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		formatDate(dateStr) {
			if (!dateStr) {
				return '---'
			}
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) {
				return dateStr
			}
			return d.toLocaleString('nl-NL')
		},

		/**
		 * Download one previous version.
		 *
		 * `version.id` is the DAV href PROPFIND returned, which is already the
		 * download URL for that version. It is opened rather than fetched
		 * because the browser must own the save dialog.
		 *
		 * @param {object} version The version to download.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		downloadVersion(version) {
			if (!version || !version.id) {
				return
			}
			window.open(version.id, '_blank')
		},

		/**
		 * Restore the open document to one of its previous versions.
		 *
		 * Nextcloud restores a version by MOVEing its DAV node onto the
		 * `restore/target` endpoint. The page's object-list is refetched
		 * afterwards because the size and the modification date both change.
		 *
		 * @param {object} version The version to restore.
		 * @return {Promise<void>}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		async restoreVersion(version) {
			if (!version || !version.id || !this.userId) {
				return
			}
			try {
				await axios.request({
					method: 'MOVE',
					url: version.id,
					headers: {
						// The same generateRemoteUrl rule and the same defect
						// (dossiq#2477): a remote.php path built with generateUrl gains
						// an /index.php prefix wherever the front controller is
						// inactive, which lands here in a MOVE Destination header.
						Destination: generateRemoteUrl(
							`dav/versions/${this.userId}/restore/target`,
						),
					},
				})
				showSuccess(this.t('dossiq', 'Version restored'))
				emit('cn:page:refresh')
				await this.fetchVersions()
			} catch {
				showError(this.t('dossiq', 'Could not restore this version'))
			}
		},
	},
}
</script>

<style scoped>
.dossier-version-panel {
	padding: 12px;
}

.dossier-version-panel__document {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.dossier-version-panel__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.dossier-version-panel__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.dossier-version-panel__info {
	display: flex;
	flex-direction: column;
}

.dossier-version-panel__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.dossier-version-panel__actions {
	display: flex;
	gap: 4px;
}
</style>

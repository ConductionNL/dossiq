<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal v-if="open" size="normal" @close="$emit('close')">
		<div class="dossier-version-panel">
			<h4 class="dossier-version-panel__title">
				{{ t('dossiq', 'Version history') }}
			</h4>

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
						<NcButton variant="tertiary" @click="downloadVersion(version)">
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
import { generateRemoteUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'

/**
 * Version-history modal for one dossier document, over the Nextcloud Files
 * versions WebDAV API. Each version is downloadable; the restore action is
 * disabled when the informatieobject status is definitief (mirroring the
 * server-side immutability rule).
 *
 * Self-sufficient (documents-on-the-case task 2.2, the CnObjectListWidget
 * swap): opened as a manifest `open-modal` row action, which
 * CnObjectListWidget hands `props.row` — the RAW `zaakinformatieobject` row,
 * `informatieobject` inlined by `content.extend` — rather than the plain
 * `document` object a parent DossierTab used to pass down directly, and no
 * `userId` prop either, since there is no parent to read `getCurrentUser()`
 * for it any more.
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
	},

	emits: ['close'],
	data() {
		return {
			versions: [],
			loading: false,
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
			return informatieobject && typeof informatieobject === 'object'
				? informatieobject
				: {}
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
			return this.document.status === 'final'
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
			handler(isOpen) {
				if (isOpen) {
					this.fetchVersions()
				}
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

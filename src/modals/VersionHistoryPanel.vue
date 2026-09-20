<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal v-if="open" size="normal" @close="$emit('close')">
		<div class="dossier-version-panel">
			<h4 class="dossier-version-panel__title">
				{{ t('dossiq', 'Version history') }}
			</h4>

			<NcEmptyContent
				v-if="hasNoFile"
				class="dossier-version-panel__refusal"
				:name="t('dossiq', 'No file to read versions of')"
				:description="refusalDescription">
				<template #icon>
					<History :size="20" />
				</template>
				<template #action>
					<NcButton variant="secondary" @click="showInFiles">
						{{ t('dossiq', 'Show in Files') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-if="!hasNoFile && !loading && versions.length === 0"
				:name="t('dossiq', 'No previous versions')">
				<template #icon>
					<History :size="20" />
				</template>
			</NcEmptyContent>

			<NcLoadingIcon v-if="loading && !hasNoFile" :size="24" />

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
							<template v-if="formatSize(version.size) !== ''">
								· {{ formatSize(version.size) }}
							</template>
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
 * Self-sufficient (documents-on-the-case task 2.2, the CnObjectListWidget
 * swap): opened as a manifest `open-modal` row action, which
 * CnObjectListWidget hands `props.row` — the RAW `zaakinformatieobject` row,
 * `informatieobject` inlined by `content.extend` — rather than the plain
 * `document` object a parent DossierTab used to pass down directly, and no
 * `userId` prop either, since there is no parent to read `getCurrentUser()`
 * for it any more.
 *
 * TWO CALLERS, ONE FILE (document-acts-reach-a-surface REQ-ZAK-020). This
 * panel was registered and named by no manifest action at all between
 * 2026-09-13, when the Documents tab that opened it was retired, and this
 * change. The `case-files` leaf opens it now, and CnFilesBrowser merges the
 * clicked node's `fileId`, `fileName` and `path` onto the action's props
 * rather than a row, so `fileId` is read first and `row.informatieobject`
 * second. Handed NEITHER, it says which file it could not find and offers
 * Show in Files; it does NOT render the empty-versions state, because a file
 * with no history and no file at all are two different sentences and only one
 * of them is about the document.
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
		 * The Nextcloud file id of the clicked node, merged onto an
		 * `open-modal` row action's props by CnFilesBrowser.
		 *
		 * This is the path the `case-files` leaf uses and the only one that
		 * still has a caller: the Documents tab that handed `row` down was
		 * retired on 2026-09-13. `row` is kept because a widget row action on
		 * an object-list still passes it, and losing that would swap one dark
		 * caller for another.
		 */
		fileId: {
			type: [String, Number],
			default: '',
		},

		/** The clicked node's name, for the refusal sentence. */
		fileName: {
			type: String,
			default: '',
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
		 * The Nextcloud file id whose versions this panel reads.
		 *
		 * The `fileId` prop wins over the row, because a row action on the
		 * files browser names the node that was clicked while `row` is empty
		 * there.
		 *
		 * @return {number} The file id, or 0 when neither prop carries one.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		resolvedFileId() {
			const fromProp = Number(this.fileId)
			if (Number.isFinite(fromProp) && fromProp > 0) {
				return fromProp
			}
			const fromRow = Number(this.document.fileId)
			return Number.isFinite(fromRow) && fromRow > 0 ? fromRow : 0
		},

		/**
		 * Whether the panel was handed no file at all.
		 *
		 * A panel with no file must SAY so. Rendering the empty-versions state
		 * instead reads as "this file has no previous versions", which is a
		 * different sentence and the wrong one: no versions and no file look
		 * identical to a reader.
		 *
		 * @return {boolean} True when neither prop named a file.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		hasNoFile() {
			return this.resolvedFileId === 0
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

		/**
		 * The sentence the refusal shows, naming the file when one was named.
		 *
		 * @return {string} The description.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		refusalDescription() {
			if (this.fileName !== '') {
				return this.t(
					'dossiq',
					'{name} could not be resolved to a file on this server, so its versions cannot be read here.',
					{ name: this.fileName },
				)
			}
			return this.t(
				'dossiq',
				'This panel was opened without a file, so there is nothing to read versions of.',
			)
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
			if (this.resolvedFileId === 0 || !this.userId) {
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
					`dav/versions/${this.userId}/versions/${this.resolvedFileId}`,
				)
				// 🔴 THE PROPERTIES ARE NAMED, NOT LEFT TO ALLPROP. A PROPFIND
				// with an empty body returns the DAV: live properties and the
				// dead ones, and Nextcloud's own `nc:` live properties are in
				// neither set. So the author has to be ASKED for, or it never
				// arrives and the panel goes on reading Unknown. A property
				// the server does not know comes back in a 404 propstat,
				// which the parser simply does not find, so naming one costs
				// nothing where it is absent.
				const { data } = await axios.request({
					method: 'PROPFIND',
					url,
					data:
						'<?xml version="1.0"?>'
						+ '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
						+ '<d:prop>'
						+ '<d:getlastmodified/>'
						+ '<d:getcontentlength/>'
						+ '<nc:version-author/>'
						+ '</d:prop>'
						+ '</d:propfind>',
					headers: { Depth: '1', 'Content-Type': 'application/xml' },
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
						'/versions/' + this.resolvedFileId + '/',
					)
				) {
					return
				}
				// 🔴 `author` USED TO BE THE LITERAL `''` HERE, and the template
				// renders `version.author || 'Unknown'`. So every version of
				// every document read Unknown, which looks exactly like a
				// server that did not send an author and is really a field
				// nobody ever parsed. REQ-ZAK-020 asks for the moment, the
				// author and the size, and two of the three were never read.
				//
				// The author lives in Nextcloud's own namespace, not in DAV:.
				// A server that does not send it still renders Unknown, which
				// is now a real absence rather than a hardcoded one.
				const author = node.getElementsByTagNameNS(
					'http://nextcloud.org/ns',
					'version-author',
				)[0]
				const size = node.getElementsByTagNameNS(
					'DAV:',
					'getcontentlength',
				)[0]
				versions.push({
					id: href.textContent,
					number: responses.length - index,
					timestamp: lastModified ? lastModified.textContent : '',
					author: author ? author.textContent : '',
					size: size ? Number(size.textContent) : null,
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
		 * Format a version's byte count for the meta line.
		 *
		 * A version whose size the server did not send renders NOTHING
		 * rather than `0 B`: an unsent size and an empty file are different
		 * facts, and only one of them is worth a reader's attention.
		 *
		 * @param {number|null} bytes The byte count, or null when unsent.
		 * @return {string} The formatted size, or an empty string.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		formatSize(bytes) {
			if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes < 0) {
				return ''
			}
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			let value = bytes
			let unit = 0
			while (value >= 1024 && unit < units.length - 1) {
				value /= 1024
				unit += 1
			}
			const rounded = unit === 0 ? value : Math.round(value * 10) / 10
			return `${rounded} ${units[unit]}`
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
		 * Open the Files app when this panel has no file of its own to read.
		 *
		 * The panel refuses rather than showing an empty list, and a refusal
		 * that offers nothing to do next is a dead end; the Files app is where
		 * the versions of any node can still be reached.
		 *
		 * @return {void}
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		showInFiles() {
			if (typeof window === 'undefined') {
				return
			}
			window.open(generateUrl('/apps/files'), '_blank', 'noopener')
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

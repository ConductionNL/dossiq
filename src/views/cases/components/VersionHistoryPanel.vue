<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="dossier-version-panel">
		<h4 class="dossier-version-panel__title">
			{{ t('procest', 'Version history') }}
		</h4>

		<NcEmptyContent
			v-if="!loading && versions.length === 0"
			:name="t('procest', 'No previous versions')">
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
						>{{ t('procest', 'Version') }} {{ version.number }}</span
					>
					<span class="dossier-version-panel__meta">
						{{ formatDate(version.timestamp) }} ·
						{{ version.author || t('procest', 'Unknown') }}
					</span>
				</div>
				<div class="dossier-version-panel__actions">
					<NcButton
						type="tertiary"
						@click="$emit('download-version', version)">
						{{ t('procest', 'Download') }}
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="restoreDisabled"
						:title="
							restoreDisabled
								? t('procest', 'Final documents cannot be modified')
								: ''
						"
						@click="$emit('restore-version', version)">
						{{ t('procest', 'Restore') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import History from 'vue-material-design-icons/History.vue'

/**
 * Side panel exposing the Nextcloud Files versions of a dossier document via
 * the WebDAV versions API. Each version is downloadable; the restore action is
 * disabled when the informatieobject status is definitief (mirroring the
 * server-side immutability rule).
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
 */
export default {
	name: 'VersionHistoryPanel',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		History,
	},
	props: {
		document: {
			type: Object,
			required: true,
		},
		userId: {
			type: String,
			default: '',
		},
	},
	emits: ['download-version', 'restore-version'],
	data() {
		return {
			versions: [],
			loading: false,
		}
	},
	computed: {
		/**
		 * Whether the restore action is disabled (definitief documents).
		 *
		 * @return {boolean} True when restore must be blocked.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
		 */
		restoreDisabled() {
			return this.document.status === 'definitief'
		},
	},
	watch: {
		document: {
			immediate: true,
			/**
			 * Refetch versions when the active document changes.
			 *
			 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
			 */
			handler() {
				this.fetchVersions()
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
				const url = generateUrl(
					`/remote.php/dav/versions/${this.userId}/versions/${this.document.fileId}`,
				)
				const { data } = await axios.request({
					method: 'PROPFIND',
					url,
					headers: { Depth: '1' },
				})
				this.versions = this.parseVersions(data)
			} catch (error) {
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

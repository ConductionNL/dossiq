<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="dossier-document-row">
		<NcCheckboxRadioSwitch
			:modelValue="selected"
			:aria-label="
				t('procest', 'Select document {title}', { title: document.title })
			"
			class="dossier-document-row__select"
			@update:modelValue="$emit('toggle-select', document)" />

		<img
			class="dossier-document-row__thumb"
			:src="thumbnailUrl"
			:alt="document.title"
			loading="lazy"
			@error="onThumbError" />

		<div class="dossier-document-row__main">
			<span class="dossier-document-row__title">{{ document.title }}</span>
			<span class="dossier-document-row__meta">
				{{ formatDate(document.creatiedatum) }} ·
				{{ document.auteur || t('procest', 'Unknown') }} ·
				{{ formatSize(document.bestandsomvang) }}
			</span>
		</div>

		<span
			class="dossier-document-row__badge dossier-document-row__status"
			:class="'dossier-document-row__status--' + document.status">
			{{ statusLabel }}
		</span>

		<span
			class="dossier-document-row__badge dossier-document-row__confidentiality">
			{{ confidentialityLabel }}
		</span>

		<NcActions :inline="0">
			<NcActionButton @click="$emit('open', document)">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ t('procest', 'Open in Files') }}
			</NcActionButton>
			<NcActionButton @click="$emit('version-history', document)">
				<template #icon>
					<History :size="20" />
				</template>
				{{ t('procest', 'Version history') }}
			</NcActionButton>
			<NcActionButton :disabled="!canShare" @click="$emit('share', document)">
				<template #icon>
					<ShareVariant :size="20" />
				</template>
				{{ t('procest', 'Share') }}
			</NcActionButton>
			<NcActionButton
				v-if="document.status === 'concept'"
				@click="$emit('delete', document)">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('procest', 'Delete') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActions, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import History from 'vue-material-design-icons/History.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import {
	canShare as canShareLevel,
	formatSize as formatBytes,
} from '../../../utils/dossierHelpers.js'

/**
 * A single dossier document row: selection checkbox, preview thumbnail, title
 * and metadata, status and confidentiality badges, and an action menu. The
 * share action is disabled and the delete action hidden by the document's
 * confidentiality/status so the UI mirrors the server-side guards.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export default {
	name: 'DocumentRow',
	components: {
		NcActionButton,
		NcActions,
		NcCheckboxRadioSwitch,
		Delete,
		History,
		OpenInNew,
		ShareVariant,
	},

	props: {
		document: {
			type: Object,
			required: true,
		},

		selected: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggle-select', 'open', 'share', 'version-history', 'delete'],
	data() {
		return {
			thumbFailed: false,
		}
	},

	computed: {
		/**
		 * Nextcloud preview API URL for the file thumbnail.
		 *
		 * @return {string} The thumbnail URL or a generic icon when unavailable.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		thumbnailUrl() {
			if (this.thumbFailed || !this.document.fileId) {
				return generateUrl('/apps/theming/img/core/filetypes/file.svg')
			}
			return generateUrl(
				`/core/preview?fileId=${this.document.fileId}&x=64&y=64`,
			)
		},

		/**
		 * Human-readable status label.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		statusLabel() {
			const labels = {
				concept: this.t('procest', 'Draft'),
				definitief: this.t('procest', 'Final'),
				gearchiveerd: this.t('procest', 'Archived'),
			}
			return labels[this.document.status] || this.document.status
		},

		/**
		 * Human-readable confidentiality label.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		confidentialityLabel() {
			const labels = {
				openbaar: this.t('procest', 'Public'),
				beperkt_openbaar: this.t('procest', 'Limited public'),
				intern: this.t('procest', 'Internal'),
				zaakvertrouwelijk: this.t('procest', 'Case-confidential'),
				vertrouwelijk: this.t('procest', 'Confidential'),
				confidentieel: this.t('procest', 'Restricted'),
				geheim: this.t('procest', 'Secret'),
				zeer_geheim: this.t('procest', 'Top secret'),
			}
			return (
				labels[this.document.vertrouwelijkheidaanduiding]
				|| this.document.vertrouwelijkheidaanduiding
			)
		},

		/**
		 * Whether the document may be publicly shared (mirrors server guard).
		 *
		 * @return {boolean} True when below the vertrouwelijk threshold.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		canShare() {
			return canShareLevel(this.document.vertrouwelijkheidaanduiding)
		},
	},

	methods: {
		/**
		 * Format an ISO date for display.
		 *
		 * @param {string} dateStr The ISO date string.
		 * @return {string} The localised date.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		formatDate(dateStr) {
			if (!dateStr) {
				return '---'
			}
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) {
				return dateStr
			}
			return d.toLocaleDateString('nl-NL')
		},

		/**
		 * Format a byte count for display.
		 *
		 * @param {number} bytes The size in bytes.
		 * @return {string} The human-readable size.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		formatSize(bytes) {
			return formatBytes(bytes)
		},

		/**
		 * Fall back to a generic icon when the preview fails to load.
		 *
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		onThumbError() {
			this.thumbFailed = true
		},
	},
}
</script>

<style scoped>
.dossier-document-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 4px;
	border-bottom: 1px solid var(--color-border);
}

.dossier-document-row__thumb {
	width: 32px;
	height: 32px;
	object-fit: cover;
	border-radius: var(--border-radius);
}

.dossier-document-row__main {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-width: 0;
}

.dossier-document-row__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dossier-document-row__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.dossier-document-row__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.8em;
	white-space: nowrap;
}

.dossier-document-row__status--concept {
	background-color: var(--color-warning, #e9a23b);
	color: var(--color-primary-text, #fff);
}

.dossier-document-row__status--definitief {
	background-color: var(--color-success, #46ba61);
	color: var(--color-primary-text, #fff);
}

.dossier-document-row__status--gearchiveerd {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.dossier-document-row__confidentiality {
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>

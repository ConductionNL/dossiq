<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="dossier-document-row">
		<NcCheckboxRadioSwitch
			:modelValue="selected"
			:aria-label="
				t('dossiq', 'Select document {title}', { title: document.title })
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
				{{ formatSize(document.bestandsomvang) }} ·
				{{ confidentialityLabel }}
			</span>
			<ul v-if="keywords.length > 0" class="dossier-document-row__keywords">
				<li
					v-for="keyword in keywords"
					:key="keyword"
					class="dossier-document-row__keyword"
					data-testid="dossier-keyword">
					{{ keyword }}
				</li>
			</ul>
		</div>

		<span class="dossier-document-row__cell" data-testid="dossier-cell-type">
			{{ typeLabel || t('dossiq', 'Unknown type') }}
		</span>

		<span
			class="dossier-document-row__badge dossier-document-row__status"
			:class="'dossier-document-row__status--' + document.status"
			data-testid="dossier-cell-status">
			{{ statusLabel }}
		</span>

		<span
			class="dossier-document-row__cell"
			data-testid="dossier-cell-direction">
			{{ directionLabel }}
		</span>

		<span class="dossier-document-row__cell" data-testid="dossier-cell-date">
			{{ formatDate(document.creatiedatum) }}
		</span>

		<span class="dossier-document-row__cell" data-testid="dossier-cell-author">
			{{ document.auteur || t('dossiq', 'Unknown') }}
		</span>

		<NcActions :inline="0">
			<NcActionButton @click="$emit('open', document)">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ t('dossiq', 'Open in Files') }}
			</NcActionButton>
			<NcActionButton @click="$emit('version-history', document)">
				<template #icon>
					<History :size="20" />
				</template>
				{{ t('dossiq', 'Version history') }}
			</NcActionButton>
			<NcActionButton
				v-if="document.status === 'draft'"
				@click="$emit('delete', document)">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('dossiq', 'Delete') }}
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
import {
	DEFAULT_DIRECTION,
	documentKeywords,
	formatSize as formatBytes,
} from '../../../utils/dossierHelpers.js'

/**
 * A single dossier document row: selection checkbox, preview thumbnail, title
 * and metadata, status and confidentiality badges, and an action menu. The
 * delete action is hidden on a final document, so the UI mirrors the
 * server-side guard.
 *
 * There is no Share action. One shipped, and it made no request at all: it
 * emitted `count-changed` and raised a success toast, so a user was told a
 * share had been created every time nothing happened. Sharing a case document
 * is a real feature and it belongs to OpenRegister, which owns the folder the
 * bytes now live in and decides who may read the object. It comes back as a
 * route, not as a toast.
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

		// The document's type, resolved to its catalogue description by the
		// tab. The row renders the label rather than the reference: an
		// informatieobjecttype uuid in a Type column tells nobody anything.
		typeLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['toggle-select', 'open', 'version-history', 'delete'],
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
				draft: this.t('dossiq', 'Draft'),
				final: this.t('dossiq', 'Final'),
				archived: this.t('dossiq', 'Archived'),
			}
			return labels[this.document.status] || this.document.status
		},

		/**
		 * Human-readable direction label.
		 *
		 * A document written before `direction` existed carries none, and the
		 * schema default is what a write would have given it, so the column
		 * reads Internal rather than blank.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		directionLabel() {
			const labels = {
				incoming: this.t('dossiq', 'Incoming'),
				outgoing: this.t('dossiq', 'Outgoing'),
				internal: this.t('dossiq', 'Internal'),
			}
			const direction = this.document.direction || DEFAULT_DIRECTION
			return labels[direction] || direction
		},

		/**
		 * The document's keywords, rendered as chips under its title.
		 *
		 * @return {string[]} The keywords, possibly empty.
		 * @spec openspec/specs/document-zaakdossier/spec.md
		 */
		keywords() {
			return documentKeywords(this.document)
		},

		/**
		 * Human-readable confidentiality label.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		confidentialityLabel() {
			const labels = {
				openbaar: this.t('dossiq', 'Public'),
				beperkt_openbaar: this.t('dossiq', 'Limited public'),
				intern: this.t('dossiq', 'Internal'),
				zaakvertrouwelijk: this.t('dossiq', 'Case-confidential'),
				vertrouwelijk: this.t('dossiq', 'Confidential'),
				confidentieel: this.t('dossiq', 'Restricted'),
				geheim: this.t('dossiq', 'Secret'),
				zeer_geheim: this.t('dossiq', 'Top secret'),
			}
			return (
				labels[this.document.vertrouwelijkheidaanduiding]
				|| this.document.vertrouwelijkheidaanduiding
			)
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
/* The six columns the case file is read by, plus the select, the thumbnail
   and the actions menu. The header strip in DossierTab declares the same
   track list, so the headings sit over the values they name. */
.dossier-document-row {
	display: grid;
	grid-template-columns: var(--dossier-columns);
	align-items: center;
	gap: 12px;
	padding: 8px 4px;
	border-bottom: 1px solid var(--color-border);
}

.dossier-document-row__cell {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dossier-document-row__keywords {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	list-style: none;
	margin: 2px 0 0;
	padding: 0;
}

.dossier-document-row__keyword {
	padding: 0 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element-light);
	color: var(--color-main-text);
	font-size: 0.8em;
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

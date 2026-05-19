<!--
  DocumentRow.vue
  Single informatieobject row with thumbnail, badges, and action menu.
  @spec openspec/changes/document-zaakdossier/tasks.md#task-T06
-->
<template>
	<div class="document-row" :class="{ 'document-row--selected': selected }">
		<input type="checkbox"
			class="document-row__checkbox"
			:checked="selected"
			@change="$emit('select')">

		<img v-if="item.fileId"
			class="document-row__thumb"
			:src="previewUrl"
			:alt="item.titel"
			loading="lazy">
		<span v-else class="document-row__thumb document-row__thumb--placeholder">📄</span>

		<div class="document-row__meta">
			<span class="document-row__title">{{ item.titel || item.bestandsnaam }}</span>
			<span class="document-row__date">{{ formatDate(item.creatiedatum) }}</span>
			<span class="document-row__author">{{ item.auteur }}</span>
			<span class="document-row__size">{{ formatSize(item.bestandsomvang) }}</span>
		</div>

		<span class="document-row__badge document-row__badge--status" :class="statusClass">
			{{ item.status }}
		</span>
		<span class="document-row__badge document-row__badge--class">
			{{ item.vertrouwelijkheidaanduiding }}
		</span>

		<NcActions>
			<NcActionButton @click="$emit('open-version-history', item)">
				{{ t('procest', 'Version history') }}
			</NcActionButton>
			<NcActionButton v-if="item.status === 'concept'" @click="$emit('delete', item.id)">
				{{ t('procest', 'Remove from dossier') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcActions, NcActionButton } from '@nextcloud/vue'

export default {
	name: 'DocumentRow',
	components: { NcActions, NcActionButton },
	props: {
		item: { type: Object, required: true },
		selected: { type: Boolean, default: false },
	},
	emits: ['select', 'open-version-history', 'delete'],
	computed: {
		previewUrl() {
			return generateUrl('/core/preview') + '?fileId=' + encodeURIComponent(this.item.fileId) + '&x=64&y=64&forceIcon=0'
		},
		statusClass() {
			const map = { concept: 'document-row__badge--concept', definitief: 'document-row__badge--definitief', gearchiveerd: 'document-row__badge--gearchiveerd' }
			return map[this.item.status] || ''
		},
	},
	methods: {
		formatDate(d) {
			if (!d) return ''
			return new Date(d).toLocaleDateString()
		},
		formatSize(bytes) {
			if (!bytes) return ''
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / 1048576).toFixed(1) + ' MB'
		},
	},
}
</script>

<style scoped>
.document-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--color-border-dark); }
.document-row--selected { background: var(--color-primary-light); }
.document-row__thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.document-row__thumb--placeholder { font-size: 24px; }
.document-row__meta { flex: 1; min-width: 0; }
.document-row__title { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.document-row__date, .document-row__author, .document-row__size { font-size: 0.75rem; color: var(--color-text-lighter); margin-right: 8px; }
.document-row__badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; white-space: nowrap; }
.document-row__badge--concept { background: #f0ad4e; color: #fff; }
.document-row__badge--definitief { background: #5cb85c; color: #fff; }
.document-row__badge--gearchiveerd { background: #999; color: #fff; }
.document-row__badge--class { background: var(--color-background-dark); color: var(--color-text-light); margin-left: 4px; }
</style>

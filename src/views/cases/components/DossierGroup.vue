<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="dossier-group">
		<button
			type="button"
			class="dossier-group__header"
			:aria-expanded="expanded ? 'true' : 'false'"
			@click="expanded = !expanded">
			<ChevronDown v-if="expanded" :size="20" />
			<ChevronRight v-else :size="20" />
			<span class="dossier-group__title">{{ groupLabel }}</span>
			<span class="dossier-group__count">({{ documents.length }})</span>
		</button>

		<div v-if="expanded" class="dossier-group__body">
			<DocumentRow
				v-for="doc in sortedDocuments"
				:key="doc.id"
				:document="doc"
				:selected="selectedIds.includes(doc.id)"
				@toggle-select="$emit('toggle-select', $event)"
				@open="$emit('open', $event)"
				@share="$emit('share', $event)"
				@version-history="$emit('version-history', $event)"
				@delete="$emit('delete', $event)" />
		</div>
	</div>
</template>

<script>
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import DocumentRow from './DocumentRow.vue'

/**
 * A collapsible dossier group for one informatieobjecttype, rendering its
 * document rows sorted by the active sort key/direction.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export default {
	name: 'DossierGroup',
	components: {
		ChevronDown,
		ChevronRight,
		DocumentRow,
	},
	props: {
		groupLabel: {
			type: String,
			required: true,
		},
		documents: {
			type: Array,
			default: () => [],
		},
		selectedIds: {
			type: Array,
			default: () => [],
		},
		sortKey: {
			type: String,
			default: 'creatiedatum',
		},
		sortDirection: {
			type: String,
			default: 'desc',
		},
	},
	emits: ['toggle-select', 'open', 'share', 'version-history', 'delete'],
	data() {
		return {
			expanded: true,
		}
	},
	computed: {
		/**
		 * The documents sorted by the active key and direction.
		 *
		 * @return {Array} The sorted documents.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		sortedDocuments() {
			const key = this.sortKey
			const dir = this.sortDirection === 'asc' ? 1 : -1
			return [...this.documents].sort((a, b) => {
				const av = a[key] || ''
				const bv = b[key] || ''
				if (av < bv) {
					return -1 * dir
				}
				if (av > bv) {
					return 1 * dir
				}
				return 0
			})
		},
	},
}
</script>

<style scoped>
.dossier-group {
	margin-bottom: 8px;
}

.dossier-group__header {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
	background: none;
	border: none;
	padding: 8px 4px;
	cursor: pointer;
	font-weight: 600;
	text-align: left;
	color: var(--color-main-text);
}

.dossier-group__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.dossier-group__body {
	padding-left: 12px;
}
</style>

<!--
  DossierGroup.vue
  Collapsible group of informatieobjecten per informatieobjecttype.
  @spec openspec/changes/document-zaakdossier/tasks.md#task-T06
-->
<template>
	<div class="dossier-group">
		<div class="dossier-group__header" @click="expanded = !expanded">
			<span class="dossier-group__arrow">{{ expanded ? '▾' : '▸' }}</span>
			<span class="dossier-group__type">{{ typeLabel }}</span>
			<span class="dossier-group__count">{{ items.length }}</span>
		</div>
		<div v-if="expanded" class="dossier-group__body">
			<DocumentRow
				v-for="item in sorted"
				:key="item.id"
				:item="item"
				:selected="selectedIds.includes(item.id)"
				@select="$emit('select', item.id)"
				@open-version-history="$emit('open-version-history', item)"
				@delete="$emit('delete', item.id)" />
		</div>
	</div>
</template>

<script>
import DocumentRow from './DocumentRow.vue'

export default {
	name: 'DossierGroup',
	components: { DocumentRow },
	props: {
		typeLabel: { type: String, default: 'Overig' },
		items: { type: Array, default: () => [] },
		selectedIds: { type: Array, default: () => [] },
	},
	emits: ['select', 'open-version-history', 'delete'],
	data() {
		return { expanded: true }
	},
	computed: {
		sorted() {
			return [...this.items].sort((a, b) => (a.titel || '').localeCompare(b.titel || ''))
		},
	},
}
</script>

<style scoped>
.dossier-group { margin-bottom: 8px; border: 1px solid var(--color-border); border-radius: 6px; overflow: hidden; }
.dossier-group__header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer; background: var(--color-background-hover); user-select: none; }
.dossier-group__type { flex: 1; font-weight: 600; }
.dossier-group__count { font-size: 0.8rem; color: var(--color-text-lighter); }
.dossier-group__body { padding: 4px 0; }
</style>

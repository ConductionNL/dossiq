<!--
  BulkActionsBar.vue
  Multi-select toolbar shown when documents are selected in the dossier.
  @spec openspec/changes/document-zaakdossier/tasks.md#task-T08
-->
<template>
	<div class="bulk-bar">
		<span class="bulk-bar__count">
			{{ n('procest', '{n} document selected', '{n} documents selected', selectedCount, { n: selectedCount }) }}
		</span>
		<NcButton @click="showTransitionMenu = !showTransitionMenu">
			{{ t('procest', 'Mark as definitief') }}
		</NcButton>
		<div v-if="showTransitionMenu" class="bulk-bar__menu">
			<button v-for="status in statuses" :key="status" class="bulk-bar__menu-item" @click="emit('transition', { status }); showTransitionMenu = false">
				{{ status }}
			</button>
		</div>
		<NcButton @click="$emit('download-zip')">
			{{ t('procest', 'Download as ZIP') }}
		</NcButton>
		<NcButton @click="$emit('clear')">
			{{ t('procest', 'Clear selection') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'BulkActionsBar',
	components: { NcButton },
	props: {
		selectedCount: { type: Number, default: 0 },
	},
	emits: ['transition', 'download-zip', 'clear'],
	data() {
		return {
			showTransitionMenu: false,
			statuses: ['definitief', 'gearchiveerd'],
		}
	},
	methods: {
		emit(event, payload) {
			this.$emit(event, payload)
		},
	},
}
</script>

<style scoped>
.bulk-bar { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--color-primary-light); border-radius: 6px; margin-bottom: 8px; flex-wrap: wrap; position: relative; }
.bulk-bar__count { font-weight: 600; flex: 1; }
.bulk-bar__menu { position: absolute; top: 100%; left: 120px; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: 4px; z-index: 10; }
.bulk-bar__menu-item { display: block; width: 100%; padding: 8px 16px; text-align: left; background: none; border: none; cursor: pointer; }
.bulk-bar__menu-item:hover { background: var(--color-background-hover); }
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="checklists-tab">
		<div class="checklists-tab__header">
			<h3>{{ t('procest', 'Inspection Checklists') }}</h3>
			<p class="checklists-tab__description">
				{{ t('procest', 'Configure reusable inspection checklists per case type. Checklists are versioned — active inspections always use the version they started with.') }}
			</p>
			<NcButton type="primary" @click="showEditor = true; editingChecklist = null">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('procest', 'New checklist') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- Checklist list -->
		<div v-if="!loading && !showEditor" class="checklists-tab__list">
			<div
				v-for="checklist in checklists"
				:key="checklist.id"
				class="checklists-tab__item">
				<div class="checklists-tab__item-info">
					<strong class="checklists-tab__item-name">{{ checklist.name }}</strong>
					<span class="checklists-tab__badge" :class="'checklists-tab__badge--' + (checklist.active ? 'active' : 'inactive')">
						{{ checklist.active ? t('procest', 'Active') : t('procest', 'Inactive') }}
					</span>
					<span class="checklists-tab__version">v{{ checklist.version || 1 }}</span>
					<span class="checklists-tab__item-count">
						{{ t('procest', '{count} items', { count: (checklist.items || []).length }) }}
					</span>
				</div>
				<div class="checklists-tab__item-actions">
					<NcButton size="small" @click="openEditor(checklist)">
						{{ t('procest', 'Edit') }}
					</NcButton>
					<NcButton size="small" type="error" @click="confirmDelete(checklist)">
						{{ t('procest', 'Delete') }}
					</NcButton>
				</div>
			</div>

			<NcEmptyContent v-if="checklists.length === 0"
				:name="t('procest', 'No checklists')"
				:description="t('procest', 'No inspection checklists configured. Create one to get started.')">
				<template #icon>
					<ClipboardListOutline :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<!-- Inline editor -->
		<InspectionChecklistEditor
			v-if="showEditor"
			:checklist="editingChecklist"
			@save="onSave"
			@cancel="showEditor = false" />

		<!-- Delete confirmation dialog (extracted to src/dialogs/ per ADR-004) -->
		<DeleteChecklistDialog
			v-if="deletingChecklist"
			:checklist="deletingChecklist"
			@confirm="doDelete"
			@cancel="deletingChecklist = null" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import DeleteChecklistDialog from '../../../dialogs/DeleteChecklistDialog.vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import InspectionChecklistEditor from '../../../components/InspectionChecklistEditor.vue'

export default {
	name: 'ChecklistsTab',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ClipboardListOutline,
		Plus,
		InspectionChecklistEditor,
		DeleteChecklistDialog,
	},

	data() {
		return {
			loading: false,
			error: null,
			checklists: [],
			showEditor: false,
			editingChecklist: null,
			deletingChecklist: null,
		}
	},

	/** @spec openspec/changes/vth-module/tasks.md#task-5 */
	async created() {
		await this.loadChecklists()
	},

	methods: {
		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		async loadChecklists() {
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('/apps/procest/api/vth/checklists'))
				this.checklists = Array.isArray(data) ? data : (data.results || [])
			} catch (err) {
				this.error = err?.response?.data?.message || t('procest', 'Failed to load checklists')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		openEditor(checklist) {
			this.editingChecklist = { ...checklist }
			this.showEditor = true
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		async onSave(checklist) {
			this.showEditor = false
			await this.loadChecklists()
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		confirmDelete(checklist) {
			this.deletingChecklist = checklist
		},

		/** @spec openspec/changes/vth-module/tasks.md#task-5 */
		async doDelete() {
			if (!this.deletingChecklist) return
			try {
				await axios.delete(generateUrl('/apps/procest/api/vth/checklists/' + encodeURIComponent(this.deletingChecklist.id)))
				this.deletingChecklist = null
				await this.loadChecklists()
			} catch (err) {
				this.error = err?.response?.data?.message || t('procest', 'Failed to delete checklist')
				this.deletingChecklist = null
			}
		},

		t,
	},
}
</script>

<style scoped>
.checklists-tab__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.checklists-tab__description {
	flex: 1 1 100%;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.checklists-tab__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.checklists-tab__item-info {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.checklists-tab__badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8em;
}

.checklists-tab__badge--active { background: var(--color-success-hover); color: var(--color-success); }
.checklists-tab__badge--inactive { background: var(--color-warning-hover); color: var(--color-warning); }

.checklists-tab__version {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.checklists-tab__item-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.checklists-tab__item-actions {
	display: flex;
	gap: 8px;
}
</style>

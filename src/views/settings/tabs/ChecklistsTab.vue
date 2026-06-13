<!--
  Procest VTH Checklist Admin Tab
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  @spec openspec/changes/vth-module/tasks.md#task-5
-->
<template>
	<div class="checklists-tab">
		<div class="checklists-tab__header">
			<h3>{{ t('procest', 'VTH Inspection Checklists') }}</h3>
			<NcButton type="primary" @click="openEditor(null)">
				{{ t('procest', 'New checklist') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && checklists.length === 0 && !editing"
			:name="t('procest', 'No checklists')"
			:description="t('procest', 'Create an inspection checklist to get started.')">
			<template #icon>
				<NcIconSvgWrapper :svg="clipboardIcon" />
			</template>
		</NcEmptyContent>

		<div v-if="!loading && !editing" class="checklists-tab__list">
			<div
				v-for="checklist in checklists"
				:key="checklist.id"
				class="checklists-tab__item">
				<div class="checklists-tab__item-info">
					<strong>{{ checklist.name }}</strong>
					<span class="checklists-tab__badge" :class="checklist.active ? 'checklists-tab__badge--active' : 'checklists-tab__badge--inactive'">
						{{ checklist.active ? t('procest', 'Active') : t('procest', 'Inactive') }}
					</span>
					<span class="checklists-tab__meta">v{{ checklist.version || 1 }} &bull; {{ (checklist.items || []).length }} {{ t('procest', 'items') }}</span>
				</div>
				<div class="checklists-tab__item-actions">
					<NcButton @click="openEditor(checklist)">
						{{ t('procest', 'Edit') }}
					</NcButton>
					<NcButton type="error" @click="confirmDelete(checklist)">
						{{ t('procest', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</div>

		<InspectionChecklistEditor
			v-if="editing"
			:checklist="editingChecklist"
			:saving="saving"
			@save="saveChecklist"
			@cancel="closeEditor" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import InspectionChecklistEditor from '../../../components/InspectionChecklistEditor.vue'

export default {
	name: 'ChecklistsTab',

	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcIconSvgWrapper,
		InspectionChecklistEditor,
	},

	data() {
		return {
			checklists: [],
			loading: false,
			editing: false,
			saving: false,
			editingChecklist: null,
			clipboardIcon: '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M19,3H14.82C14.25,1.44 12.53,0.64 11,1.2C10.14,1.5 9.5,2.16 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3M7,7H17V5H19V19H5V5H7V7Z" /></svg>',
		}
	},

	mounted() {
		this.loadChecklists()
	},

	methods: {
		async loadChecklists() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/objects/inspectionChecklist')
				const response = await axios.get(url)
				this.checklists = response.data?.results || response.data || []
			} catch (e) {
				showError(t('procest', 'Failed to load checklists'))
			} finally {
				this.loading = false
			}
		},

		openEditor(checklist) {
			this.editingChecklist = checklist ? { ...checklist } : {
				name: '',
				version: 1,
				caseTypeRef: '',
				items: [],
				active: false,
				validFrom: new Date().toISOString().split('T')[0],
			}
			this.editing = true
		},

		closeEditor() {
			this.editing = false
			this.editingChecklist = null
		},

		async saveChecklist(checklist) {
			this.saving = true
			try {
				const url = checklist.id
					? generateUrl('/apps/procest/api/objects/inspectionChecklist/' + encodeURIComponent(checklist.id))
					: generateUrl('/apps/procest/api/objects/inspectionChecklist')
				const method = checklist.id ? 'put' : 'post'
				await axios[method](url, checklist)
				showSuccess(t('procest', 'Checklist saved'))
				this.closeEditor()
				await this.loadChecklists()
			} catch (e) {
				showError(t('procest', 'Failed to save checklist'))
			} finally {
				this.saving = false
			}
		},

		async confirmDelete(checklist) {
			if (!window.confirm(t('procest', 'Delete checklist "{name}"?', { name: checklist.name }))) {
				return
			}
			try {
				const url = generateUrl('/apps/procest/api/objects/inspectionChecklist/' + encodeURIComponent(checklist.id))
				await axios.delete(url)
				showSuccess(t('procest', 'Checklist deleted'))
				await this.loadChecklists()
			} catch (e) {
				showError(t('procest', 'Failed to delete checklist'))
			}
		},
	},
}
</script>

<style scoped>
.checklists-tab__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
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
	gap: 8px;
}

.checklists-tab__item-actions {
	display: flex;
	gap: 8px;
}

.checklists-tab__badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8em;
}

.checklists-tab__badge--active {
	background: var(--color-success);
	color: white;
}

.checklists-tab__badge--inactive {
	background: var(--color-warning);
	color: white;
}

.checklists-tab__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>

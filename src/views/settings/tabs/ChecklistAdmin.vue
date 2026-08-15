<template>
	<div class="checklist-admin">
		<div class="checklist-admin__header">
			<h3>{{ t('procest', 'Inspection Checklists') }}</h3>
			<NcButton type="primary" @click="createChecklist">
				{{ t('procest', 'New checklist') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<!-- Checklist list -->
		<div v-if="!loading && !editingChecklist" class="checklist-admin__list">
			<div
				v-for="checklist in checklists"
				:key="checklist.id"
				class="checklist-admin__item">
				<div class="checklist-admin__item-info">
					<strong>{{ checklist.name }}</strong>
					<span
						class="checklist-admin__badge"
						:class="'checklist-admin__badge--' + checklist.status">
						{{ checklist.status }} (v{{ checklist.version || 1 }})
					</span>
					<span class="checklist-admin__item-count">
						{{
							t('procest', '{count} items', {
								count: (checklist.items || []).length,
							})
						}}
					</span>
				</div>
				<div class="checklist-admin__item-actions">
					<NcButton @click="editChecklist(checklist)">
						{{ t('procest', 'Edit') }}
					</NcButton>
					<NcButton
						v-if="checklist.status === 'active'"
						type="secondary"
						@click="createVersion(checklist)">
						{{ t('procest', 'New version') }}
					</NcButton>
					<NcButton
						v-if="checklist.status === 'draft'"
						type="error"
						@click="deleteChecklist(checklist)">
						{{ t('procest', 'Delete') }}
					</NcButton>
				</div>
			</div>

			<p v-if="checklists.length === 0" class="checklist-admin__empty">
				{{ t('procest', 'No checklists configured for this case type.') }}
			</p>
		</div>

		<!-- Checklist editor -->
		<div v-if="editingChecklist" class="checklist-admin__editor">
			<div class="checklist-admin__editor-header">
				<NcButton @click="cancelEdit">
					{{ t('procest', 'Back to list') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="saveChecklist">
					{{
						saving
							? t('procest', 'Saving...')
							: t('procest', 'Save checklist')
					}}
				</NcButton>
			</div>

			<div class="checklist-admin__field">
				<label for="checklist-admin-name">{{
					t('procest', 'Checklist name')
				}}</label>
				<input
					id="checklist-admin-name"
					v-model="editingChecklist.name"
					type="text"
					class="checklist-admin__input"
					:placeholder="
						t('procest', 'e.g. Bouwtoezicht fase 1 - Fundering')
					" />
			</div>

			<div class="checklist-admin__field">
				<label>{{ t('procest', 'Status') }}</label>
				<select
					v-model="editingChecklist.status"
					class="checklist-admin__input">
					<option value="draft">
						{{ t('procest', 'Draft') }}
					</option>
					<option value="active">
						{{ t('procest', 'Active') }}
					</option>
					<option value="archived">
						{{ t('procest', 'Archived') }}
					</option>
				</select>
			</div>

			<!-- Checklist items -->
			<h4>{{ t('procest', 'Checklist items') }}</h4>
			<div class="checklist-admin__items">
				<div
					v-for="(item, index) in editingChecklist.items"
					:key="index"
					class="checklist-admin__item-editor"
					draggable="true"
					@dragstart="onDragStart(index, $event)"
					@dragover.prevent="onDragOver(index, $event)"
					@drop="onDrop(index, $event)">
					<span class="checklist-admin__drag-handle">&#x2630;</span>

					<div class="checklist-admin__item-fields">
						<input
							v-model="item.label"
							type="text"
							class="checklist-admin__input"
							:placeholder="t('procest', 'Item label')"
							:aria-label="t('procest', 'Item label')" />

						<select
							v-model="item.type"
							class="checklist-admin__input checklist-admin__input--small">
							<option value="ja_nee_nvt">
								{{ t('procest', 'Yes/No/N.A.') }}
							</option>
							<option value="tekst">
								{{ t('procest', 'Text') }}
							</option>
							<option value="getal">
								{{ t('procest', 'Number') }}
							</option>
							<option value="foto">
								{{ t('procest', 'Photo') }}
							</option>
							<option value="meerkeuze">
								{{ t('procest', 'Multiple choice') }}
							</option>
						</select>

						<label class="checklist-admin__toggle">
							<input v-model="item.required" type="checkbox" />
							{{ t('procest', 'Required') }}
						</label>

						<label class="checklist-admin__toggle">
							<input v-model="item.photoRequired" type="checkbox" />
							{{ t('procest', 'Photo required') }}
						</label>
					</div>

					<div class="checklist-admin__item-extra">
						<input
							v-model="item.helpText"
							type="text"
							class="checklist-admin__input"
							:placeholder="t('procest', 'Help text for inspector')"
							:aria-label="t('procest', 'Help text for inspector')" />

						<div
							v-if="item.type === 'meerkeuze'"
							class="checklist-admin__options">
							<label :for="`checklist-admin-options-${index}`">{{
								t('procest', 'Options (comma-separated):')
							}}</label>
							<input
								:id="`checklist-admin-options-${index}`"
								:value="(item.options || []).join(', ')"
								type="text"
								class="checklist-admin__input"
								@input="
									item.options = $event.target.value
										.split(',')
										.map((o) => o.trim())
										.filter(Boolean)
								" />
						</div>
					</div>

					<NcButton type="error" @click="removeItem(index)">
						{{ t('procest', 'Remove') }}
					</NcButton>
				</div>
			</div>

			<NcButton @click="addItem">
				{{ t('procest', 'Add item') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useInspectionStore } from '../../../store/modules/inspection.js'

export default {
	name: 'ChecklistAdmin',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	props: {
		caseTypeId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			editingChecklist: null,
			saving: false,
			dragIndex: null,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		inspectionStore() {
			return useInspectionStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		checklists() {
			return this.inspectionStore.checklists
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		loading() {
			return this.inspectionStore.loading
		},
	},

	watch: {
		caseTypeId: {
			immediate: true,
			/**
			 * @param newId
			 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
			 */
			handler(newId) {
				if (newId) {
					this.inspectionStore.fetchChecklists(newId)
				}
			},
		},
	},

	methods: {
		t,

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		createChecklist() {
			this.editingChecklist = {
				name: '',
				caseType: this.caseTypeId,
				version: 1,
				status: 'draft',
				items: [],
			}
		},

		/**
		 * @param checklist
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		editChecklist(checklist) {
			this.editingChecklist = JSON.parse(JSON.stringify(checklist))
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		cancelEdit() {
			this.editingChecklist = null
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		async saveChecklist() {
			this.saving = true
			try {
				// Ensure items have correct order
				if (this.editingChecklist.items) {
					this.editingChecklist.items.forEach((item, i) => {
						item.order = i + 1
					})
				}
				await this.inspectionStore.saveChecklist(this.editingChecklist)
				this.editingChecklist = null
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param checklist
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async createVersion(checklist) {
			await this.inspectionStore.createNewVersion(checklist)
		},

		/**
		 * @param checklist
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async deleteChecklist(checklist) {
			if (
				confirm(
					t('procest', 'Are you sure you want to delete this checklist?'),
				)
			) {
				await this.inspectionStore.deleteChecklist(checklist.id)
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		addItem() {
			if (!this.editingChecklist.items) {
				this.editingChecklist.items = []
			}
			this.editingChecklist.items.push({
				order: this.editingChecklist.items.length + 1,
				label: '',
				type: 'ja_nee_nvt',
				required: true,
				photoRequired: false,
				options: [],
				helpText: '',
			})
		},

		/**
		 * @param index
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		removeItem(index) {
			this.editingChecklist.items.splice(index, 1)
		},

		/**
		 * @param index
		 * @param event
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		onDragStart(index, event) {
			this.dragIndex = index
			event.dataTransfer.effectAllowed = 'move'
		},

		/**
		 * @param index
		 * @param event
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		onDragOver(index, event) {
			event.dataTransfer.dropEffect = 'move'
		},

		/**
		 * @param index
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		onDrop(index) {
			if (this.dragIndex === null || this.dragIndex === index) {
				return
			}
			const items = this.editingChecklist.items
			const moved = items.splice(this.dragIndex, 1)[0]
			items.splice(index, 0, moved)
			this.dragIndex = null
		},
	},
}
</script>

<style scoped>
.checklist-admin__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.checklist-admin__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.checklist-admin__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.checklist-admin__item-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.checklist-admin__item-actions {
	display: flex;
	gap: 4px;
}

.checklist-admin__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
}

.checklist-admin__badge--draft {
	background: var(--color-warning);
	color: white;
}

.checklist-admin__badge--active {
	background: var(--color-success);
	color: white;
}

.checklist-admin__badge--archived {
	background: var(--color-text-maxcontrast);
	color: white;
}

.checklist-admin__item-count {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.checklist-admin__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 24px;
}

.checklist-admin__editor-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 16px;
}

.checklist-admin__field {
	margin-bottom: 12px;
}

.checklist-admin__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.checklist-admin__input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.checklist-admin__input--small {
	width: auto;
	min-width: 120px;
}

.checklist-admin__items {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.checklist-admin__item-editor {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: grab;
}

.checklist-admin__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
}

.checklist-admin__item-fields {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.checklist-admin__item-extra {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.checklist-admin__toggle {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	white-space: nowrap;
}

.checklist-admin__options {
	margin-top: 4px;
}
</style>

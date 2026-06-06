<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>{{ t('procest', 'Save the case type first before adding result types.') }}</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<p v-if="error" class="edit-error">
					{{ error }}
				</p>

				<div v-if="items.length > 0" class="sub-entity-tab__list">
					<div
						v-for="item in items"
						:key="item.id"
						class="sub-entity-row">
						<template v-if="editingId !== item.id">
							<span class="sub-entity-row__name">{{ item.name }}</span>
							<span v-if="item.archivalAction" class="sub-entity-row__badge" :class="archivalBadgeClass(item.archivalAction)">
								{{ archivalActionLabel(item.archivalAction) }}
							</span>
							<span v-if="item.archivalPeriod" class="sub-entity-row__meta">
								{{ formatPeriod(item.archivalPeriod) }}
							</span>
							<span v-if="item.archivalStatus" class="sub-entity-row__meta">
								{{ item.archivalStatus }}
							</span>
							<div class="sub-entity-row__actions">
								<NcButton type="tertiary" :aria-label="t('procest', 'Edit')" @click="startEdit(item)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton type="tertiary" :aria-label="t('procest', 'Delete')" @click="deleteItem(item)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="sub-entity-row__edit-form">
								<div class="edit-row">
									<NcTextField
										:value="editForm.name"
										:label="t('procest', 'Name')"
										:error="!!editError"
										class="edit-field"
										@update:value="v => editForm.name = v" />
								</div>
								<div class="edit-row">
									<NcSelect
										v-model="editForm.archivalAction"
										:options="archivalActionOptions"
										:input-label="t('procest', 'Archive action')"
										:placeholder="t('procest', 'Archive action')"
										class="edit-field" />
									<NcTextField
										:value="editForm.archivalPeriod"
										:label="t('procest', 'Retention period (e.g. P20Y)')"
										class="edit-field"
										@update:value="v => editForm.archivalPeriod = v" />
								</div>
								<div class="edit-row">
									<NcTextField
										:value="editForm.archivalStatus"
										:label="t('procest', 'Archival status')"
										class="edit-field"
										@update:value="v => editForm.archivalStatus = v" />
									<NcTextField
										:value="editForm.description"
										:label="t('procest', 'Description')"
										class="edit-field"
										@update:value="v => editForm.description = v" />
								</div>
								<p v-if="editError" class="edit-error">
									{{ editError }}
								</p>
								<div class="edit-actions">
									<NcButton type="primary" :disabled="saving" @click="saveEdit">
										{{ t('procest', 'Save') }}
									</NcButton>
									<NcButton :disabled="saving" @click="cancelEdit">
										{{ t('procest', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>
				<p v-else class="sub-entity-tab__empty">
					{{ t('procest', 'No result types configured yet.') }}
				</p>

				<NcButton v-if="editingId === null" @click="startAdd">
					{{ t('procest', 'Add Result Type') }}
				</NcButton>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { formatDuration } from '../../../utils/durationHelpers.js'

export default {
	name: 'ResultTypesTab',
	components: { NcButton, NcLoadingIcon, NcTextField, NcSelect, PencilIcon, DeleteIcon },
	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},
	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			items: [],
			editingId: null,
			editForm: { name: '', description: '', archivalAction: '', archivalPeriod: '', archivalStatus: '' },
			editError: '',
			archivalActionOptions: ['bewaren', 'vernietigen', 'blijvend_bewaren'],
		}
	},
	async mounted() {
		if (!this.isCreate) await this.loadItems()
	},
	methods: {
		/** @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md */
		async loadItems() {
			this.loading = true
			this.error = ''
			try {
				const objectStore = useObjectStore()
				const results = await objectStore.fetchCollection('resultType', {
					'_filters[caseType]': this.caseTypeId,
					_limit: 100,
				})
				this.items = results || []
			} catch (e) {
				this.error = t('procest', 'Failed to load result types')
			}
			this.loading = false
		},
		/**
		 * @param {string} value Archival action key
		 * @return {string} Translated label
		 * @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md
		 */
		archivalActionLabel(value) {
			if (value === 'bewaren' || value === 'blijvend_bewaren') return t('procest', 'Retain')
			if (value === 'vernietigen') return t('procest', 'Destroy')
			return value
		},
		/**
		 * @param {string} value Archival action key
		 * @return {string} Badge CSS modifier class
		 * @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md
		 */
		archivalBadgeClass(value) {
			if (value === 'vernietigen') return 'sub-entity-row__badge--destroy'
			return 'sub-entity-row__badge--retain'
		},
		/**
		 * @param {string} iso ISO 8601 duration
		 * @return {string} Human-readable period
		 * @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md
		 */
		formatPeriod(iso) {
			return formatDuration(iso)
		},
		/** @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md */
		startAdd() {
			this.editingId = 'new'
			this.editForm = { name: '', description: '', archivalAction: '', archivalPeriod: '', archivalStatus: '' }
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},
		/**
		 * @param item Result type to edit
		 * @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md
		 */
		startEdit(item) {
			this.editingId = item.id
			this.editForm = {
				name: item.name,
				description: item.description || '',
				archivalAction: item.archivalAction || '',
				archivalPeriod: item.archivalPeriod || '',
				archivalStatus: item.archivalStatus || '',
			}
			this.editError = ''
		},
		/** @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md */
		cancelEdit() {
			if (this.editingId === 'new') {
				this.items = this.items.filter(i => i.id !== 'new')
			}
			this.editingId = null
			this.editError = ''
		},
		/** @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md */
		async saveEdit() {
			if (!this.editForm.name.trim()) {
				this.editError = t('procest', 'Name is required')
				return
			}
			this.editError = ''
			this.saving = true
			const objectStore = useObjectStore()
			const data = {
				name: this.editForm.name.trim(),
				description: this.editForm.description.trim(),
				caseType: this.caseTypeId,
				archivalAction: this.editForm.archivalAction || null,
				archivalPeriod: this.editForm.archivalPeriod || null,
				archivalStatus: this.editForm.archivalStatus || null,
			}
			if (this.editingId !== 'new') data.id = this.editingId
			try {
				const result = await objectStore.saveObject('resultType', data)
				if (!result) {
					this.editError = objectStore.getError('resultType') || t('procest', 'Failed to save result type')
					this.saving = false
					return
				}
				this.editingId = null
				await this.loadItems()
			} catch (e) {
				this.editError = objectStore.getError('resultType') || t('procest', 'Failed to save result type')
			}
			this.saving = false
		},
		/**
		 * @param item Result type to delete
		 * @spec openspec/changes/case-types-03-result-role-tabs/specs/result-type-management/spec.md
		 */
		async deleteItem(item) {
			if (!confirm(t('procest', 'Delete result type "{name}"?', { name: item.name }))) return
			this.error = ''
			const objectStore = useObjectStore()
			try {
				const ok = await objectStore.deleteObject('resultType', item.id)
				if (!ok) {
					this.error = objectStore.getError('resultType') || t('procest', 'Failed to delete result type')
					return
				}
				await this.loadItems()
			} catch (e) {
				this.error = objectStore.getError('resultType') || t('procest', 'Failed to delete result type')
			}
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';

.sub-entity-row__badge--retain {
	background: var(--color-success);
	color: var(--color-primary-text, #fff);
}

.sub-entity-row__badge--destroy {
	background: var(--color-error);
	color: var(--color-primary-text, #fff);
}
</style>

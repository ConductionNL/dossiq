<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>
				{{
					t(
						'procest',
						'Save the case type first before adding role types.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<p v-if="error" class="edit-error">
					{{ error }}
				</p>

				<div v-if="items.length > 0" class="sub-entity-tab__list">
					<div v-for="item in items" :key="item.id" class="sub-entity-row">
						<template v-if="editingId !== item.id">
							<span class="sub-entity-row__name">{{ item.name }}</span>
							<span
								v-if="item.genericRole"
								class="sub-entity-row__badge">
								{{ genericRoleLabel(item.genericRole) }}
							</span>
							<span
								v-if="item.ncGroupId"
								class="sub-entity-row__badge"
								:title="
									t('procest', 'Enforced NC group for this role')
								">
								{{ item.ncGroupId }}
							</span>
							<span
								v-if="item.description"
								class="sub-entity-row__meta"
								:title="item.description">
								{{ truncate(item.description) }}
							</span>
							<div class="sub-entity-row__actions">
								<NcButton
									type="tertiary"
									:aria-label="t('procest', 'Edit')"
									@click="startEdit(item)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton
									type="tertiary"
									:aria-label="t('procest', 'Delete')"
									@click="deleteItem(item)">
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
										:model-value="editForm.name"
										:label="t('procest', 'Name')"
										:error="!!editError"
										class="edit-field"
										@update:model-value="
											(v) => (editForm.name = v)
										" />
									<NcSelect
										v-model="editForm.genericRole"
										:options="genericRoleOptions"
										:input-label="t('procest', 'Generic role')"
										:placeholder="t('procest', 'Generic role')"
										class="edit-field" />
								</div>
								<div class="edit-row">
									<NcTextField
										:model-value="editForm.ncGroupId"
										:label="t('procest', 'NC Group ID')"
										:helper-text="ncGroupHint"
										class="edit-field edit-field--full"
										@update:model-value="
											(v) => (editForm.ncGroupId = v)
										" />
								</div>
								<div class="edit-row">
									<NcTextField
										:model-value="editForm.description"
										:label="t('procest', 'Description')"
										class="edit-field edit-field--full"
										@update:model-value="
											(v) => (editForm.description = v)
										" />
								</div>
								<p v-if="editError" class="edit-error">
									{{ editError }}
								</p>
								<div class="edit-actions">
									<NcButton
										type="primary"
										:disabled="saving"
										@click="saveEdit">
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
					{{ t('procest', 'No role types configured yet.') }}
				</p>

				<NcButton v-if="editingId === null" @click="startAdd">
					{{ t('procest', 'Add Role Type') }}
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

const GENERIC_ROLE_LABELS = {
	initiator: 'Initiator',
	handler: 'Handler',
	advisor: 'Advisor',
	decision_maker: 'Decision maker',
	stakeholder: 'Stakeholder',
	coordinator: 'Coordinator',
	contact: 'Contact',
	co_initiator: 'Co-initiator',
}

export default {
	name: 'RoleTypesTab',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		PencilIcon,
		DeleteIcon,
	},
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
			editForm: { name: '', description: '', genericRole: '', ncGroupId: '' },
			editError: '',
			genericRoleOptions: Object.keys(GENERIC_ROLE_LABELS),
		}
	},
	computed: {
		/**
		 * @return {string} Helper text for the NC Group ID field.
		 * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-3.1
		 */
		ncGroupHint() {
			return t(
				'procest',
				"Nextcloud group that holds this role. OpenRegister uses it to enforce who may perform this role's workflow steps. Must be an existing Nextcloud group ID; leave empty for no group restriction.",
			)
		},
	},
	async mounted() {
		if (!this.isCreate) await this.loadItems()
	},
	methods: {
		/**
		 * @param {string} value Generic role key
		 * @return {string} Translated label
		 * @spec openspec/specs/result-type-management/spec.md
		 */
		genericRoleLabel(value) {
			return GENERIC_ROLE_LABELS[value]
				? t('procest', GENERIC_ROLE_LABELS[value])
				: value
		},
		/**
		 * @param {string} text Description text
		 * @return {string} Truncated text
		 * @spec openspec/specs/result-type-management/spec.md
		 */
		truncate(text) {
			if (!text) return ''
			return text.length > 60 ? text.slice(0, 60) + '…' : text
		},
		/** @spec openspec/specs/result-type-management/spec.md */
		async loadItems() {
			this.loading = true
			this.error = ''
			try {
				const objectStore = useObjectStore()
				const results = await objectStore.fetchCollection('roleType', {
					'_filters[caseType]': this.caseTypeId,
					_limit: 100,
				})
				this.items = results || []
			} catch (e) {
				this.error = t('procest', 'Failed to load role types')
			}
			this.loading = false
		},
		/** @spec openspec/specs/result-type-management/spec.md */
		startAdd() {
			this.editingId = 'new'
			this.editForm = {
				name: '',
				description: '',
				genericRole: '',
				ncGroupId: '',
			}
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},
		/**
		 * @param item Role type to edit
		 * @spec openspec/specs/result-type-management/spec.md
		 */
		startEdit(item) {
			this.editingId = item.id
			this.editForm = {
				name: item.name,
				description: item.description || '',
				genericRole: item.genericRole || '',
				ncGroupId: item.ncGroupId || '',
			}
			this.editError = ''
		},
		/** @spec openspec/specs/result-type-management/spec.md */
		cancelEdit() {
			if (this.editingId === 'new')
				this.items = this.items.filter((i) => i.id !== 'new')
			this.editingId = null
			this.editError = ''
		},
		/** @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-3.1 */
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
				genericRole: this.editForm.genericRole || null,
				ncGroupId: this.editForm.ncGroupId.trim() || null,
			}
			if (this.editingId !== 'new') data.id = this.editingId
			try {
				const result = await objectStore.saveObject('roleType', data)
				if (!result) {
					this.editError =
						objectStore.getError('roleType')
						|| t('procest', 'Failed to save role type')
					this.saving = false
					return
				}
				this.editingId = null
				await this.loadItems()
			} catch (e) {
				this.editError =
					objectStore.getError('roleType')
					|| t('procest', 'Failed to save role type')
			}
			this.saving = false
		},
		/**
		 * @param item Role type to delete
		 * @spec openspec/specs/result-type-management/spec.md
		 */
		async deleteItem(item) {
			if (
				!confirm(
					t('procest', 'Delete role type "{name}"?', { name: item.name }),
				)
			)
				return
			this.error = ''
			const objectStore = useObjectStore()
			try {
				const ok = await objectStore.deleteObject('roleType', item.id)
				if (!ok) {
					this.error =
						objectStore.getError('roleType')
						|| t('procest', 'Failed to delete role type')
					return
				}
				await this.loadItems()
			} catch (e) {
				this.error =
					objectStore.getError('roleType')
					|| t('procest', 'Failed to delete role type')
			}
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';
</style>

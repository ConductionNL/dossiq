<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>{{ t('procest', 'Save the case type first before adding role types.') }}</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="items.length > 0" class="sub-entity-tab__list">
					<div
						v-for="item in items"
						:key="item.id"
						class="sub-entity-row">
						<template v-if="editingId !== item.id">
							<span class="sub-entity-row__name">{{ item.name }}</span>
							<span v-if="item.genericRole" class="sub-entity-row__badge">
								{{ item.genericRole }}
							</span>
							<div class="sub-entity-row__actions">
								<NcButton type="tertiary" @click="startEdit(item)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton type="tertiary" @click="deleteItem(item)">
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
									<NcSelect
										v-model="editForm.genericRole"
										:options="genericRoleOptions"
										:placeholder="t('procest', 'Generic role')"
										class="edit-field" />
								</div>
								<p v-if="editError" class="edit-error">
									{{ editError }}
								</p>
								<div class="edit-actions">
									<NcButton type="primary" @click="saveEdit">
										{{ t('procest', 'Save') }}
									</NcButton>
									<NcButton @click="cancelEdit">
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

export default {
	name: 'RoleTypesTab',
	components: { NcButton, NcLoadingIcon, NcTextField, NcSelect, PencilIcon, DeleteIcon },
	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},
	data() {
		return {
			loading: false,
			items: [],
			editingId: null,
			editForm: { name: '', genericRole: '' },
			editError: '',
			genericRoleOptions: ['initiator', 'handler', 'advisor', 'decision_maker', 'stakeholder', 'coordinator', 'contact', 'co_initiator'],
		}
	},
	async mounted() {
		if (!this.isCreate) await this.loadItems()
	},
	methods: {
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		async loadItems() {
			this.loading = true
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('roleType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
			})
			this.items = results || []
			this.loading = false
		},
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		startAdd() {
			this.editingId = 'new'
			this.editForm = { name: '', genericRole: '' }
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		startEdit(item) {
			this.editingId = item.id
			this.editForm = { name: item.name, genericRole: item.genericRole || '' }
			this.editError = ''
		},
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		cancelEdit() {
			if (this.editingId === 'new') this.items = this.items.filter(i => i.id !== 'new')
			this.editingId = null
			this.editError = ''
		},
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		async saveEdit() {
			if (!this.editForm.name.trim()) {
				this.editError = t('procest', 'Name is required')
				return
			}
			const objectStore = useObjectStore()
			const data = {
				name: this.editForm.name.trim(),
				caseType: this.caseTypeId,
				genericRole: this.editForm.genericRole || null,
			}
			if (this.editingId !== 'new') data.id = this.editingId
			await objectStore.saveObject('roleType', data)
			this.editingId = null
			await this.loadItems()
		},
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		async deleteItem(item) {
			if (!confirm(t('procest', 'Delete role type "{name}"?', { name: item.name }))) return
			const objectStore = useObjectStore()
			await objectStore.deleteObject('roleType', item.id)
			await this.loadItems()
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';
</style>

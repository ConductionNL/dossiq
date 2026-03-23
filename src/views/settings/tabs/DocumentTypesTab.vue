<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>{{ t('procest', 'Save the case type first before adding document types.') }}</p>
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
							<span v-if="item.category" class="sub-entity-row__meta">
								{{ item.category }}
							</span>
							<span v-if="item.isRequired" class="sub-entity-row__badge">
								{{ t('procest', 'Required') }}
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
									<NcTextField
										:value="editForm.category"
										:label="t('procest', 'Category')"
										class="edit-field"
										@update:value="v => editForm.category = v" />
								</div>
								<div class="edit-row">
									<NcTextField
										:value="editForm.description"
										:label="t('procest', 'Description')"
										class="edit-field edit-field--full"
										@update:value="v => editForm.description = v" />
								</div>
								<div class="edit-row">
									<NcCheckboxRadioSwitch
										:checked="editForm.isRequired"
										@update:checked="v => editForm.isRequired = v">
										{{ t('procest', 'Required') }}
									</NcCheckboxRadioSwitch>
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
					{{ t('procest', 'No document types configured yet.') }}
				</p>

				<NcButton v-if="editingId === null" @click="startAdd">
					{{ t('procest', 'Add Document Type') }}
				</NcButton>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'DocumentTypesTab',
	components: { NcButton, NcLoadingIcon, NcTextField, NcCheckboxRadioSwitch, PencilIcon, DeleteIcon },
	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},
	data() {
		return {
			loading: false,
			items: [],
			editingId: null,
			editForm: { name: '', category: '', description: '', isRequired: false },
			editError: '',
		}
	},
	async mounted() {
		if (!this.isCreate) await this.loadItems()
	},
	methods: {
		async loadItems() {
			this.loading = true
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('documentType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
			})
			this.items = results || []
			this.loading = false
		},
		startAdd() {
			this.editingId = 'new'
			this.editForm = { name: '', category: '', description: '', isRequired: false }
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},
		startEdit(item) {
			this.editingId = item.id
			this.editForm = {
				name: item.name,
				category: item.category || '',
				description: item.description || '',
				isRequired: item.isRequired === true || item.isRequired === 'true',
			}
			this.editError = ''
		},
		cancelEdit() {
			if (this.editingId === 'new') this.items = this.items.filter(i => i.id !== 'new')
			this.editingId = null
			this.editError = ''
		},
		async saveEdit() {
			if (!this.editForm.name.trim()) {
				this.editError = t('procest', 'Name is required')
				return
			}
			const objectStore = useObjectStore()
			const data = {
				name: this.editForm.name.trim(),
				category: this.editForm.category.trim(),
				description: this.editForm.description.trim(),
				caseType: this.caseTypeId,
				isRequired: this.editForm.isRequired,
			}
			if (this.editingId !== 'new') data.id = this.editingId
			await objectStore.saveObject('documentType', data)
			this.editingId = null
			await this.loadItems()
		},
		async deleteItem(item) {
			if (!confirm(t('procest', 'Delete document type "{name}"?', { name: item.name }))) return
			const objectStore = useObjectStore()
			await objectStore.deleteObject('documentType', item.id)
			await this.loadItems()
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';
</style>

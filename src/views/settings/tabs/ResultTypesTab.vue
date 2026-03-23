<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>{{ t('procest', 'Save the case type first before adding result types.') }}</p>
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
							<span v-if="item.archivalAction" class="sub-entity-row__badge">
								{{ item.archivalAction === 'bewaren' ? t('procest', 'Retain') : item.archivalAction === 'vernietigen' ? t('procest', 'Destroy') : item.archivalAction }}
							</span>
							<span v-if="item.archivalPeriod" class="sub-entity-row__meta">
								{{ item.archivalPeriod }}
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
								</div>
								<div class="edit-row">
									<NcSelect
										v-model="editForm.archivalAction"
										:options="archivalActionOptions"
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
										:value="editForm.description"
										:label="t('procest', 'Description')"
										class="edit-field edit-field--full"
										@update:value="v => editForm.description = v" />
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
			items: [],
			editingId: null,
			editForm: { name: '', description: '', archivalAction: '', archivalPeriod: '' },
			editError: '',
			archivalActionOptions: ['bewaren', 'vernietigen', 'blijvend_bewaren'],
		}
	},
	async mounted() {
		if (!this.isCreate) await this.loadItems()
	},
	methods: {
		async loadItems() {
			this.loading = true
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('resultType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
			})
			this.items = results || []
			this.loading = false
		},
		startAdd() {
			this.editingId = 'new'
			this.editForm = { name: '', description: '', archivalAction: '', archivalPeriod: '' }
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},
		startEdit(item) {
			this.editingId = item.id
			this.editForm = { name: item.name, description: item.description || '', archivalAction: item.archivalAction || '', archivalPeriod: item.archivalPeriod || '' }
			this.editError = ''
		},
		cancelEdit() {
			if (this.editingId === 'new') {
				this.items = this.items.filter(i => i.id !== 'new')
			}
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
				description: this.editForm.description.trim(),
				caseType: this.caseTypeId,
				archivalAction: this.editForm.archivalAction || null,
				archivalPeriod: this.editForm.archivalPeriod || null,
			}
			if (this.editingId !== 'new') data.id = this.editingId
			await objectStore.saveObject('resultType', data)
			this.editingId = null
			await this.loadItems()
		},
		async deleteItem(item) {
			if (!confirm(t('procest', 'Delete result type "{name}"?', { name: item.name }))) return
			const objectStore = useObjectStore()
			await objectStore.deleteObject('resultType', item.id)
			await this.loadItems()
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';
</style>

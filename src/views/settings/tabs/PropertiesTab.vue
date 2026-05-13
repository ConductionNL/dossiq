<template>
	<div class="properties-tab">
		<div v-if="isCreate" class="properties-tab__notice">
			<p>{{ t('procest', 'Save the case type first before adding property definitions.') }}</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="propertyDefs.length > 0" class="properties-tab__list">
					<div
						v-for="pd in propertyDefs"
						:key="pd.id"
						class="property-row"
						:class="{ 'property-row--editing': editingId === pd.id }">
						<template v-if="editingId !== pd.id">
							<span class="property-row__name">{{ pd.name }}</span>
							<span class="property-row__format">{{ pd.format || 'text' }}</span>
							<span v-if="pd.maxLength" class="property-row__max">
								{{ t('procest', 'max {n}', { n: pd.maxLength }) }}
							</span>
							<span class="property-row__required">
								{{ pd.requiredAtStatus || t('procest', 'Optional') }}
							</span>
							<div class="property-row__actions">
								<NcButton type="tertiary" @click="startEdit(pd)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton type="tertiary" @click="deleteProperty(pd)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="property-row__edit-form">
								<div class="edit-row">
									<NcTextField
										:value="editForm.name"
										:label="t('procest', 'Name')"
										:error="!!editError"
										class="edit-field"
										@update:value="v => editForm.name = v" />
								</div>
								<div class="edit-row">
									<NcTextField
										:value="editForm.definition"
										:label="t('procest', 'Definition')"
										class="edit-field"
										@update:value="v => editForm.definition = v" />
								</div>
								<div class="edit-row">
									<div class="edit-field">
										<label class="field-label">{{ t('procest', 'Format') }}</label>
										<select
											:value="editForm.format"
											class="format-select"
											@change="editForm.format = $event.target.value">
											<option value="text">
												{{ t('procest', 'Text') }}
											</option>
											<option value="number">
												{{ t('procest', 'Number') }}
											</option>
											<option value="date">
												{{ t('procest', 'Date') }}
											</option>
											<option value="datetime">
												{{ t('procest', 'Date & Time') }}
											</option>
										</select>
									</div>
									<NcTextField
										:value="editForm.maxLength ? String(editForm.maxLength) : ''"
										:label="t('procest', 'Max length')"
										type="number"
										class="edit-field edit-field--small"
										@update:value="v => editForm.maxLength = parseInt(v, 10) || null" />
								</div>
								<div class="edit-row">
									<div class="edit-field">
										<label class="field-label">{{ t('procest', 'Required at status') }}</label>
										<select
											:value="editForm.requiredAtStatus || ''"
											class="format-select"
											@change="editForm.requiredAtStatus = $event.target.value || null">
											<option value="">
												{{ t('procest', 'Optional') }}
											</option>
											<option v-for="st in statusTypes" :key="st.id" :value="st.name">
												{{ st.name }}
											</option>
										</select>
									</div>
								</div>
								<span v-if="editError" class="field-error">{{ editError }}</span>
								<div class="edit-row edit-row--actions">
									<NcButton type="primary" :disabled="editSaving" @click="saveEdit">
										{{ t('procest', 'Save') }}
									</NcButton>
									<NcButton type="tertiary" @click="cancelEdit">
										{{ t('procest', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>

				<p v-else class="properties-tab__empty">
					{{ t('procest', 'No property definitions yet.') }}
				</p>

				<div class="properties-tab__add">
					<h4>{{ t('procest', 'Add Property Definition') }}</h4>
					<div class="add-form">
						<div class="add-form__row">
							<NcTextField
								:value="newForm.name"
								:label="t('procest', 'Name *')"
								class="add-form__field"
								@update:value="v => newForm.name = v" />
						</div>
						<div class="add-form__row">
							<NcTextField
								:value="newForm.definition"
								:label="t('procest', 'Definition')"
								class="add-form__field"
								@update:value="v => newForm.definition = v" />
						</div>
						<div class="add-form__row">
							<div class="add-form__field">
								<label class="field-label">{{ t('procest', 'Format') }}</label>
								<select
									:value="newForm.format"
									class="format-select"
									@change="newForm.format = $event.target.value">
									<option value="text">
										{{ t('procest', 'Text') }}
									</option>
									<option value="number">
										{{ t('procest', 'Number') }}
									</option>
									<option value="date">
										{{ t('procest', 'Date') }}
									</option>
									<option value="datetime">
										{{ t('procest', 'Date & Time') }}
									</option>
								</select>
							</div>
							<NcTextField
								:value="newForm.maxLength ? String(newForm.maxLength) : ''"
								:label="t('procest', 'Max length')"
								type="number"
								class="add-form__field add-form__field--small"
								@update:value="v => newForm.maxLength = parseInt(v, 10) || null" />
						</div>
						<div class="add-form__row">
							<div class="add-form__field">
								<label class="field-label">{{ t('procest', 'Required at status') }}</label>
								<select
									:value="newForm.requiredAtStatus || ''"
									class="format-select"
									@change="newForm.requiredAtStatus = $event.target.value || null">
									<option value="">
										{{ t('procest', 'Optional') }}
									</option>
									<option v-for="st in statusTypes" :key="st.id" :value="st.name">
										{{ st.name }}
									</option>
								</select>
							</div>
						</div>
						<span v-if="addError" class="field-error">{{ addError }}</span>
						<NcButton type="primary" :disabled="addSaving" @click="addProperty">
							{{ t('procest', 'Add') }}
						</NcButton>
					</div>
				</div>
			</template>

			<p v-if="error" class="properties-tab__error">
				{{ error }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'PropertiesTab',
	components: { NcButton, NcLoadingIcon, NcTextField, PencilIcon, DeleteIcon },
	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},
	data() {
		return {
			propertyDefs: [],
			statusTypes: [],
			loading: false,
			error: '',
			newForm: { name: '', definition: '', format: 'text', maxLength: null, requiredAtStatus: null },
			addError: '',
			addSaving: false,
			editingId: null,
			editForm: {},
			editError: '',
			editSaving: false,
		}
	},
	computed: {
		objectStore() { return useObjectStore() },
	},
	async mounted() {
		if (!this.isCreate && this.caseTypeId) {
			await Promise.all([this.fetchPropertyDefs(), this.fetchStatusTypes()])
		}
	},
	methods: {
		async fetchPropertyDefs() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchCollection('propertyDefinition', {
					'_filters[caseType]': this.caseTypeId, _limit: 100,
				})
				this.propertyDefs = result || []
			} catch (e) { this.error = e.message }
			this.loading = false
		},
		async fetchStatusTypes() {
			try {
				const result = await this.objectStore.fetchCollection('statusType', {
					'_filters[caseType]': this.caseTypeId, _limit: 100,
				})
				this.statusTypes = result || []
			} catch (e) { /* ignore — status types are optional for property definitions */ }
		},
		async addProperty() {
			this.addError = ''
			if (!this.newForm.name?.trim()) { this.addError = t('procest', 'Name is required'); return }
			this.addSaving = true
			const result = await this.objectStore.saveObject('propertyDefinition', { ...this.newForm, caseType: this.caseTypeId })
			this.addSaving = false
			if (result) {
				this.propertyDefs.push(result)
				this.newForm = { name: '', definition: '', format: 'text', maxLength: null, requiredAtStatus: null }
			} else {
				this.addError = this.objectStore.getError('propertyDefinition') || t('procest', 'Failed to add property')
			}
		},
		startEdit(pd) { this.editingId = pd.id; this.editForm = { ...pd }; this.editError = '' },
		cancelEdit() { this.editingId = null; this.editForm = {}; this.editError = '' },
		async saveEdit() {
			this.editError = ''
			if (!this.editForm.name?.trim()) { this.editError = t('procest', 'Name is required'); return }
			this.editSaving = true
			const result = await this.objectStore.saveObject('propertyDefinition', this.editForm)
			this.editSaving = false
			if (result) {
				const idx = this.propertyDefs.findIndex(p => p.id === this.editingId)
				if (idx !== -1) this.$set(this.propertyDefs, idx, result)
				this.editingId = null; this.editForm = {}
			} else {
				this.editError = this.objectStore.getError('propertyDefinition') || t('procest', 'Failed to save')
			}
		},
		async deleteProperty(pd) {
			if (!confirm(t('procest', 'Delete property "{name}"?', { name: pd.name }))) return
			const ok = await this.objectStore.deleteObject('propertyDefinition', pd.id)
			if (ok) {
				this.propertyDefs = this.propertyDefs.filter(p => p.id !== pd.id)
			} else {
				this.error = this.objectStore.getError('propertyDefinition') || t('procest', 'Failed to delete property')
			}
		},
	},
}
</script>

<style scoped>
.properties-tab__notice { padding: 16px; background: var(--color-background-dark); border-radius: var(--border-radius); color: var(--color-text-maxcontrast); }
.properties-tab__list { margin-bottom: 24px; }
.property-row { display: flex; align-items: center; gap: 12px; padding: 8px 12px; border-bottom: 1px solid var(--color-border); transition: background 0.15s; }
.property-row:hover { background: var(--color-background-hover); }
.property-row--editing { background: var(--color-background-dark); padding: 12px; flex-direction: column; align-items: stretch; }
.property-row__name { flex: 1; font-weight: 500; }
.property-row__format { padding: 2px 8px; border-radius: var(--border-radius-pill); font-size: 11px; font-weight: 500; background: var(--color-background-dark); }
.property-row__max { font-size: 12px; color: var(--color-text-maxcontrast); }
.property-row__required { font-size: 12px; color: var(--color-text-maxcontrast); font-style: italic; }
.property-row__actions { display: flex; gap: 2px; margin-left: auto; }
.property-row__edit-form { width: 100%; }
.format-select { width: 100%; padding: 8px; border: 1px solid var(--color-border-dark); border-radius: var(--border-radius); background: var(--color-main-background); }
.edit-row { display: flex; gap: 12px; margin-bottom: 8px; align-items: center; }
.edit-row--actions { margin-top: 8px; }
.edit-field { flex: 1; }
.edit-field--small { max-width: 100px; }
.field-label { display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px; color: var(--color-text-maxcontrast); }
.properties-tab__add { border-top: 2px solid var(--color-border); padding-top: 16px; }
.properties-tab__add h4 { margin-bottom: 12px; }
.add-form__row { display: flex; gap: 12px; margin-bottom: 8px; align-items: center; }
.add-form__field { flex: 1; }
.add-form__field--small { max-width: 100px; }
.properties-tab__empty { color: var(--color-text-maxcontrast); padding: 20px; text-align: center; }
.properties-tab__error { color: var(--color-error); margin-top: 12px; }
.field-error { display: block; color: var(--color-error); font-size: 12px; margin-bottom: 8px; }
</style>

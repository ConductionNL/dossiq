<template>
	<div class="custom-properties-panel">
		<div v-if="loading" class="custom-properties-panel__loading">
			<NcLoadingIcon :size="20" />
		</div>

		<template v-else-if="propertyDefinitions.length === 0">
			<p class="custom-properties-panel__empty">
				{{ t('procest', 'No required properties for this case type') }}
			</p>
		</template>

		<template v-else>
			<div class="custom-properties-panel__summary">
				{{ t('procest', '{filled} of {total} properties filled', { filled: filledCount, total: propertyDefinitions.length }) }}
			</div>

			<div class="custom-properties-panel__list">
				<div
					v-for="propDef in propertyDefinitions"
					:key="propDef.id"
					class="property-item">
					<div class="property-item__header">
						<span class="property-item__name">{{ propDef.name }}</span>
						<span v-if="propDef.isRequired" class="property-item__required">*</span>
					</div>

					<template v-if="editing && editingPropId === propDef.id">
						<div class="property-item__edit">
							<NcTextField
								:value="editValue"
								:placeholder="propDef.definition || t('procest', 'Enter value...')"
								@update:value="v => editValue = v" />
							<div class="property-item__edit-actions">
								<NcButton type="primary" @click="saveProperty(propDef)">
									{{ t('procest', 'Save') }}
								</NcButton>
								<NcButton @click="cancelEdit">
									{{ t('procest', 'Cancel') }}
								</NcButton>
							</div>
						</div>
					</template>

					<template v-else>
						<div class="property-item__value" @click="startEdit(propDef)">
							<span v-if="getPropertyValue(propDef.id)">
								{{ getPropertyValue(propDef.id) }}
							</span>
							<span v-else class="property-item__placeholder">
								{{ t('procest', 'Not set') }}
							</span>
						</div>
					</template>
				</div>
			</div>

			<NcButton v-if="!isReadOnly && !editing" @click="editAll">
				{{ t('procest', 'Edit Properties') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'CustomPropertiesPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		caseTypeId: {
			type: String,
			default: null,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			loading: false,
			propertyDefinitions: [],
			caseProperties: [],
			editing: false,
			editingPropId: null,
			editValue: '',
		}
	},
	computed: {
		filledCount() {
			return this.propertyDefinitions.filter(pd => this.getPropertyValue(pd.id)).length
		},
	},
	watch: {
		caseTypeId() {
			if (this.caseTypeId) {
				this.loadData()
			}
		},
	},
	async mounted() {
		if (this.caseTypeId) {
			await this.loadData()
		}
	},
	methods: {
		async loadData() {
			this.loading = true
			const objectStore = useObjectStore()

			const [propDefs, caseProps] = await Promise.all([
				objectStore.fetchCollection('propertyDefinition', {
					'_filters[caseType]': this.caseTypeId,
					_limit: 100,
				}),
				objectStore.fetchCollection('caseProperty', {
					'_filters[case]': this.caseId,
					_limit: 100,
				}),
			])

			this.propertyDefinitions = propDefs || []
			this.caseProperties = caseProps || []
			this.loading = false
		},

		getPropertyValue(propDefId) {
			const prop = this.caseProperties.find(cp => cp.propertyDefinition === propDefId)
			return prop?.value || ''
		},

		startEdit(propDef) {
			if (this.isReadOnly) return
			this.editing = true
			this.editingPropId = propDef.id
			this.editValue = this.getPropertyValue(propDef.id)
		},

		editAll() {
			if (this.propertyDefinitions.length > 0) {
				this.startEdit(this.propertyDefinitions[0])
			}
		},

		cancelEdit() {
			this.editing = false
			this.editingPropId = null
			this.editValue = ''
		},

		async saveProperty(propDef) {
			const objectStore = useObjectStore()
			const existing = this.caseProperties.find(cp => cp.propertyDefinition === propDef.id)

			if (existing) {
				await objectStore.saveObject('caseProperty', {
					...existing,
					value: this.editValue,
				})
			} else {
				await objectStore.saveObject('caseProperty', {
					case: this.caseId,
					propertyDefinition: propDef.id,
					value: this.editValue,
				})
			}

			await this.loadData()
			this.cancelEdit()
		},
	},
}
</script>

<style scoped>
.custom-properties-panel__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 12px;
}

.custom-properties-panel__summary {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.custom-properties-panel__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.property-item {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.property-item__header {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-bottom: 4px;
}

.property-item__name {
	font-weight: 500;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.property-item__required {
	color: var(--color-error);
}

.property-item__value {
	cursor: pointer;
	padding: 4px 0;
}

.property-item__value:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.property-item__placeholder {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.property-item__edit {
	margin-top: 4px;
}

.property-item__edit-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>

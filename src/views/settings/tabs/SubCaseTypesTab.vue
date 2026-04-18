<template>
	<div class="sub-case-types-tab">
		<div class="form-group">
			<label>{{ t('procest', 'Allowed sub-case types') }}</label>
			<p class="form-description">{{ t('procest', 'Select which case types are allowed as sub-cases for this case type') }}</p>

			<NcLoadingIcon v-if="loading" />

			<template v-else-if="availableCaseTypes.length > 0">
				<div class="checkbox-list">
					<div v-for="ct in availableCaseTypes" :key="ct.id" class="checkbox-item">
						<input
							:id="'ct-' + ct.id"
							v-model="selectedTypeIds"
							type="checkbox"
							:value="ct.id"
							class="checkbox-input">
						<label :for="'ct-' + ct.id" class="checkbox-label">
							{{ ct.title }}
							<span v-if="ct.description" class="description">{{ ct.description }}</span>
						</label>
					</div>
				</div>
			</template>

			<template v-else>
				<p class="form-hint">{{ t('procest', 'No other case types available') }}</p>
			</template>
		</div>

		<div class="form-info">
			<p>{{ t('procest', 'Changes have no effect on existing sub-cases') }}</p>
		</div>

		<div class="form-actions">
			<NcButton
				type="primary"
				:disabled="saving || !hasChanges"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('procest', 'Save') }}
			</NcButton>
		</div>

		<p v-if="saveError" class="form-error">
			{{ saveError }}
		</p>
		<p v-if="saveSuccess" class="form-success">
			{{ t('procest', 'Saved successfully') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'SubCaseTypesTab',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		caseTypeId: {
			type: String,
			required: true,
		},
		isCreate: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			loading: false,
			saving: false,
			availableCaseTypes: [],
			selectedTypeIds: [],
			originalTypeIds: [],
			saveError: '',
			saveSuccess: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		hasChanges() {
			return JSON.stringify(this.selectedTypeIds.sort()) !== JSON.stringify(this.originalTypeIds.sort())
		},
	},
	async mounted() {
		if (!this.isCreate) {
			await this.loadData()
		}
	},
	methods: {
		async loadData() {
			this.loading = true
			try {
				// Fetch all case types
				const allTypes = await this.objectStore.fetchCollection('caseType', { _limit: 100 })
				if (allTypes) {
					// Exclude the current case type from the list
					this.availableCaseTypes = allTypes.filter(ct => ct.id !== this.caseTypeId)
				}

				// Fetch the current case type's sub-case types
				const currentCaseType = await this.objectStore.fetchObject('caseType', this.caseTypeId)
				if (currentCaseType && currentCaseType.subCaseTypes) {
					this.selectedTypeIds = [...currentCaseType.subCaseTypes]
					this.originalTypeIds = [...currentCaseType.subCaseTypes]
				}
			} catch (err) {
				console.error('Failed to load case types:', err)
				this.saveError = t('procest', 'Failed to load case types')
			} finally {
				this.loading = false
			}
		},

		async save() {
			this.saveError = ''
			this.saveSuccess = false

			try {
				this.saving = true

				// Fetch the current case type
				const currentCaseType = await this.objectStore.fetchObject('caseType', this.caseTypeId)
				if (!currentCaseType) {
					this.saveError = t('procest', 'Failed to load case type')
					return
				}

				// Update the subCaseTypes
				currentCaseType.subCaseTypes = this.selectedTypeIds

				// Save the updated case type
				const result = await this.objectStore.saveObject('caseType', currentCaseType)
				if (result) {
					this.originalTypeIds = [...this.selectedTypeIds]
					this.saveSuccess = true
					setTimeout(() => { this.saveSuccess = false }, 3000)
				} else {
					this.saveError = this.objectStore.getError('caseType')
						|| t('procest', 'Failed to save case type')
				}
			} catch (err) {
				console.error('Failed to save:', err)
				this.saveError = t('procest', 'Failed to save case type')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.sub-case-types-tab {
	padding: 20px;
}

.form-group {
	margin-bottom: 20px;
}

.form-group label {
	display: block;
	font-weight: 500;
	margin-bottom: 8px;
	color: var(--color-text);
}

.form-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.form-hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.checkbox-list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.checkbox-item {
	display: flex;
	align-items: flex-start;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
}

.checkbox-item:last-child {
	border-bottom: none;
}

.checkbox-input {
	margin-right: 12px;
	margin-top: 2px;
	cursor: pointer;
}

.checkbox-label {
	flex: 1;
	cursor: pointer;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.description {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.form-info {
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-primary-element);
	padding: 12px;
	margin-bottom: 20px;
	border-radius: var(--border-radius);
	font-size: 13px;
}

.form-info p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.form-actions {
	margin-bottom: 20px;
}

.form-error {
	color: var(--color-error);
	font-size: 13px;
	margin-top: 12px;
}

.form-success {
	color: var(--color-success);
	font-size: 13px;
	margin-top: 12px;
}
</style>

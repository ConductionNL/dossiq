<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  - @spec openspec/changes/deelzaak-support/tasks.md#T12
  -->

<template>
	<div class="sub-case-types-tab">
		<div v-if="isCreate" class="sub-case-types-tab__notice">
			<p>
				{{
					t(
						'procest',
						'Save the case type first before configuring sub-case types.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div class="sub-case-types-tab__intro">
					<p>
						{{
							t(
								'procest',
								'Select which case types can be created as sub-cases (deelzaken) under this case type. Existing sub-cases are unaffected by changes here.',
							)
						}}
					</p>
				</div>

				<div
					v-if="availableCaseTypes.length === 0"
					class="sub-case-types-tab__empty">
					<p>
						{{
							t(
								'procest',
								'No other case types available to use as sub-case types.',
							)
						}}
					</p>
				</div>

				<div v-else class="sub-case-types-tab__list">
					<label
						v-for="ct in availableCaseTypes"
						:key="ct.id"
						class="sub-case-type-row">
						<NcCheckboxRadioSwitch
							:model-value="selected.includes(ct.id)"
							@update:model-value="toggleSelection(ct.id, $event)">
							<span class="sub-case-type-row__title">{{
								ct.title || ct.identifier || ct.id
							}}</span>
						</NcCheckboxRadioSwitch>
						<span
							v-if="ct.identifier"
							class="sub-case-type-row__identifier"
							>{{ ct.identifier }}</span
						>
					</label>
				</div>

				<div class="sub-case-types-tab__actions">
					<NcButton
						type="primary"
						:disabled="saving || !dirty"
						@click="save">
						{{
							saving
								? t('procest', 'Saving...')
								: t('procest', 'Save sub-case types')
						}}
					</NcButton>
					<span
						v-if="saveSuccess"
						class="sub-case-types-tab__success"
						role="status">
						{{ t('procest', 'Saved.') }}
					</span>
					<span
						v-if="saveError"
						class="sub-case-types-tab__error"
						role="alert">
						{{ saveError }}
					</span>
				</div>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'SubCaseTypesTab',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},
	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: true,
			saving: false,
			saveError: '',
			saveSuccess: false,
			selected: [],
			initialSelected: [],
			caseTypes: [],
		}
	},
	computed: {
		/** @spec openspec/changes/deelzaak-support/tasks.md#T12 */
		objectStore() {
			return useObjectStore()
		},
		isCreate() {
			return !this.caseTypeId
		},
		/** @spec openspec/changes/deelzaak-support/tasks.md#T12 */
		availableCaseTypes() {
			return (this.caseTypes || []).filter((ct) => ct.id !== this.caseTypeId)
		},
		/** @spec openspec/changes/deelzaak-support/tasks.md#T12 */
		dirty() {
			const current = [...this.selected].sort().join(',')
			const initial = [...this.initialSelected].sort().join(',')
			return current !== initial
		},
	},
	/** @spec openspec/changes/deelzaak-support/tasks.md#T12 */
	async mounted() {
		if (!this.isCreate) {
			await this.loadData()
		} else {
			this.loading = false
		}
	},
	methods: {
		/** @spec openspec/changes/deelzaak-support/tasks.md#T12 */
		async loadData() {
			this.loading = true
			try {
				const [allCaseTypes, currentCaseType] = await Promise.all([
					this.objectStore.fetchCollection('caseType', { _limit: 500 }),
					this.objectStore.fetchObject('caseType', this.caseTypeId),
				])
				this.caseTypes = allCaseTypes || []
				const existing = Array.isArray(currentCaseType?.subCaseTypes)
					? currentCaseType.subCaseTypes
					: []
				this.selected = [...existing]
				this.initialSelected = [...existing]
			} catch (err) {
				this.saveError =
					err?.message || t('procest', 'Failed to load case types.')
			}
			this.loading = false
		},
		/**
		 * @param ctId The case type id being toggled
		 * @param checked The new checked state
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T12
		 */
		toggleSelection(ctId, checked) {
			if (checked) {
				if (!this.selected.includes(ctId)) {
					this.selected = [...this.selected, ctId]
				}
			} else {
				this.selected = this.selected.filter((id) => id !== ctId)
			}
		},
		/** @spec openspec/changes/deelzaak-support/tasks.md#T12 */
		async save() {
			this.saving = true
			this.saveError = ''
			this.saveSuccess = false
			try {
				await this.objectStore.saveObject('caseType', {
					id: this.caseTypeId,
					subCaseTypes: this.selected,
				})
				this.initialSelected = [...this.selected]
				this.saveSuccess = true
			} catch (err) {
				this.saveError =
					err?.message || t('procest', 'Failed to save sub-case types.')
			}
			this.saving = false
		},
		t,
	},
}
</script>

<style scoped>
.sub-case-types-tab {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem 0;
}

.sub-case-types-tab__intro p {
	margin: 0 0 0.5rem 0;
	color: var(--color-text-maxcontrast);
}

.sub-case-types-tab__list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.sub-case-type-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.sub-case-type-row:hover {
	background: var(--color-background-hover);
}

.sub-case-type-row__title {
	font-weight: 500;
}

.sub-case-type-row__identifier {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

.sub-case-types-tab__actions {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.sub-case-types-tab__success {
	color: var(--color-success);
}

.sub-case-types-tab__error {
	color: var(--color-error);
}

.sub-case-types-tab__empty {
	padding: 1rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.sub-case-types-tab__notice {
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}
</style>

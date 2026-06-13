<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="consultation-types-tab">
		<h3 class="consultation-types-tab__title">
			{{ t('procest', 'Adviestypen per zaaktype') }}
		</h3>
		<p class="consultation-types-tab__description">
			{{ t('procest', 'Configureer welke consultaties verplicht of optioneel zijn voor elk zaaktype.') }}
		</p>

		<!-- Zaaktype selector -->
		<div class="consultation-types-tab__selector">
			<label class="consultation-types-tab__label">{{ t('procest', 'Zaaktype') }}</label>
			<NcSelect
				v-model="selectedCaseTypeId"
				:options="caseTypeOptions"
				:aria-label-combobox="t('procest', 'Selecteer zaaktype')"
				label="label"
				:reduce="opt => opt.value"
				:placeholder="t('procest', 'Selecteer een zaaktype')" />
		</div>

		<template v-if="selectedCaseTypeId">
			<NcNoteCard v-if="saveError" type="error">
				{{ saveError }}
			</NcNoteCard>

			<!-- Consultation type list -->
			<div class="consultation-types-tab__list">
				<div
					v-for="(ct, idx) in consultationTypes"
					:key="idx"
					class="consultation-types-tab__row">
					<NcTextField
						:value="ct.name"
						:label="t('procest', 'Naam')"
						required
						@update:value="v => ct.name = v" />

					<NcSelect
						v-model="ct.advisoryBodyId"
						:options="advisoryBodyOptions"
						:aria-label-combobox="t('procest', 'Standaard adviesinstantie')"
						label="label"
						:reduce="opt => opt.value"
						:placeholder="t('procest', 'Standaard adviesinstantie')" />

					<NcTextField
						:value="String(ct.defaultDeadlineWeeks)"
						:label="t('procest', 'Standaard doorlooptijd (weken)')"
						type="number"
						@update:value="v => ct.defaultDeadlineWeeks = parseInt(v) || 4" />

					<NcCheckboxRadioSwitch
						:checked="ct.mandatory"
						@update:checked="v => ct.mandatory = v">
						{{ t('procest', 'Verplicht') }}
					</NcCheckboxRadioSwitch>

					<NcButton type="tertiary-no-background" @click="removeType(idx)">
						{{ t('procest', 'Verwijderen') }}
					</NcButton>
				</div>
			</div>

			<div class="consultation-types-tab__actions">
				<NcButton @click="addType">
					{{ t('procest', 'Adviestype toevoegen') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving"
					@click="saveTypes">
					{{ saving ? t('procest', 'Opslaan...') : t('procest', 'Opslaan') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ConsultationTypesTab',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			selectedCaseTypeId: null,
			caseTypeOptions: [],
			advisoryBodyOptions: [],
			consultationTypes: [],
			saving: false,
			saveError: '',
		}
	},
	watch: {
		/**
		 * @param newId
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07
		 */
		selectedCaseTypeId(newId) {
			if (newId) {
				this.loadConsultationTypes(newId)
			} else {
				this.consultationTypes = []
			}
		},
	},
	mounted() {
		this.loadCaseTypes()
		this.loadAdvisoryBodies()
	},
	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07 */
		async loadCaseTypes() {
			try {
				const { data } = await axios.get('/apps/procest/api/settings')
				const types = data.caseTypes || []
				this.caseTypeOptions = types.map(ct => ({
					label: ct.title || ct.id,
					value: ct.id,
				}))
			} catch (e) {
				console.error('Failed to load case types', e)
			}
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07 */
		async loadAdvisoryBodies() {
			try {
				const { data } = await axios.get('/apps/procest/api/advisory-bodies')
				const bodies = data.results || []
				this.advisoryBodyOptions = bodies.map(b => ({
					label: b.name,
					value: b.id,
				}))
			} catch (e) {
				console.error('Failed to load advisory bodies', e)
			}
		},
		/**
		 * @param caseTypeId
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07
		 */
		async loadConsultationTypes(caseTypeId) {
			try {
				const { data } = await axios.get('/apps/procest/api/settings')
				const ct = (data.consultationTypes || {})[caseTypeId] || []
				this.consultationTypes = ct.map(t => ({ ...t }))
			} catch (e) {
				console.error('Failed to load consultation types', e)
			}
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07 */
		addType() {
			this.consultationTypes.push({
				name: '',
				advisoryBodyId: null,
				defaultDeadlineWeeks: 4,
				mandatory: false,
			})
		},
		/**
		 * @param idx
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07
		 */
		removeType(idx) {
			this.consultationTypes.splice(idx, 1)
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-07 */
		async saveTypes() {
			this.saving = true
			this.saveError = ''
			try {
				await axios.post('/apps/procest/api/settings', {
					key: 'consultationTypes',
					value: { [this.selectedCaseTypeId]: this.consultationTypes },
				})
			} catch (e) {
				this.saveError = this.t('procest', 'Opslaan mislukt. Probeer het opnieuw.')
				console.error('Failed to save consultation types', e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.consultation-types-tab__title {
	margin-bottom: 8px;
}

.consultation-types-tab__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.consultation-types-tab__selector {
	margin-bottom: 16px;
}

.consultation-types-tab__label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
	font-size: 0.875rem;
}

.consultation-types-tab__list {
	margin: 16px 0;
}

.consultation-types-tab__row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
	flex-wrap: wrap;
}

.consultation-types-tab__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Mandaat editor — create/edit a Mandaat (MandateringsBesluit). Own
  file under src/modals/ per ADR-004. NcSelect inputs declare inputLabel.
-->
<template>
	<NcModal :name="title" size="large" @close="$emit('close')">
		<div class="mandaat-editor">
			<h2>{{ title }}</h2>

			<div class="form-group">
				<label class="required" for="me-num">{{
					t('dossiq', 'Mandate number')
				}}</label>
				<NcTextField
					id="me-num"
					:modelValue="form.mandateNumber"
					:error="!!errors.mandateNumber"
					:helperText="errors.mandateNumber"
					@update:modelValue="(v) => (form.mandateNumber = v)" />
			</div>

			<div class="form-group">
				<label class="required" for="me-omschr">{{
					t('dossiq', 'Description')
				}}</label>
				<textarea
					id="me-omschr"
					v-model="form.description"
					class="mandaat-editor__textarea"
					rows="3" />
				<span v-if="errors.description" class="field-error">{{
					errors.description
				}}</span>
			</div>

			<div class="form-group">
				<label class="required" for="me-type">{{
					t('dossiq', 'Bevoegdheidstype')
				}}</label>
				<NcSelect
					id="me-type"
					:modelValue="selectedType"
					:options="typeOptions"
					:inputLabel="t('dossiq', 'Bevoegdheidstype')"
					@update:modelValue="
						(v) => (form.competenceType = v ? v.id : '')
					" />
				<span v-if="errors.competenceType" class="field-error">{{
					errors.competenceType
				}}</span>
			</div>

			<div class="form-group">
				<label class="required" for="me-grond">{{
					t('dossiq', 'Legal basis')
				}}</label>
				<NcTextField
					id="me-grond"
					:modelValue="form.legalBasis"
					:error="!!errors.legalBasis"
					:helperText="errors.legalBasis"
					@update:modelValue="(v) => (form.legalBasis = v)" />
			</div>

			<div class="form-group">
				<label for="me-voorw">{{ t('dossiq', 'Conditions (JSON)') }}</label>
				<textarea
					id="me-voorw"
					v-model="voorwaardenJson"
					class="mandaat-editor__textarea mandaat-editor__textarea--mono"
					rows="6"
					:placeholder="
						t(
							'dossiq',
							'e.g. { \&quot;maxBedrag\&quot;: 50000, \&quot;categorie\&quot;: [\&quot;subsidie\&quot;] }',
						)
					" />
				<span v-if="errors.terms" class="field-error">{{
					errors.terms
				}}</span>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label class="required" for="me-inwerkingtreding">{{
						t('dossiq', 'In werkingtreding')
					}}</label>
					<input
						id="me-inwerkingtreding"
						type="date"
						class="mandaat-editor__date"
						:value="form.inWerkingtreding"
						@input="form.inWerkingtreding = $event.target.value" />
				</div>
				<div class="form-group">
					<label for="me-verval">{{ t('dossiq', 'Expiry date') }}</label>
					<input
						id="me-verval"
						type="date"
						class="mandaat-editor__date"
						:value="form.vervaldatum"
						@input="form.vervaldatum = $event.target.value" />
				</div>
			</div>

			<div class="form-group">
				<label for="me-rol">{{ t('dossiq', 'Assigned role') }}</label>
				<NcSelect
					id="me-rol"
					:modelValue="selectedRole"
					:options="roleOptions"
					:inputLabel="t('dossiq', 'Assigned role')"
					@update:modelValue="
						(v) => (form.toegewezenRol = v ? v.id : '')
					" />
			</div>

			<div class="mandaat-editor__actions">
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="18" />
						<ContentSave v-else :size="18" />
					</template>
					{{ saving ? t('dossiq', 'Saving…') : t('dossiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { savingFormEditor } from '../mixins/formEditor.js'

export default {
	name: 'MandaatEditor',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		ContentSave,
	},

	mixins: [savingFormEditor],

	props: {
		mandaat: { type: Object, default: null },
		roleOptions: { type: Array, default: () => [] },
	},

	emits: ['save', 'close'],
	data() {
		return {
			saving: false,
			errors: {},
			form: {
				mandateNumber: this.mandaat?.mandateNumber || '',
				description: this.mandaat?.description || '',
				competenceType:
					this.mandaat?.competenceType || 'beslissingsbevoegdheid',

				legalBasis: this.mandaat?.legalBasis || '',
				inWerkingtreding:
					this.mandaat?.inWerkingtreding
					|| new Date().toISOString().slice(0, 10),

				vervaldatum: this.mandaat?.vervaldatum || '',
				toegewezenRol: this.mandaat?.toegewezenRol || '',
				terms: this.mandaat?.terms || {},
			},

			voorwaardenJson: JSON.stringify(this.mandaat?.terms || {}, null, 2),
		}
	},

	computed: {
		/** @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md */
		title() {
			return this.mandaat
				? t('dossiq', 'Edit mandaat')
				: t('dossiq', 'New mandaat')
		},

		/** @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md */
		typeOptions() {
			return [
				{
					id: 'beslissingsbevoegdheid',
					label: t('dossiq', 'Decision authority'),
				},
				{
					id: 'ondertekeningsbevoegdheid',
					label: t('dossiq', 'Signing authority'),
				},
				{
					id: 'gemandateerde-bevoegdheid',
					label: t('dossiq', 'Mandated authority'),
				},
				{ id: 'doormandaat', label: t('dossiq', 'Doormandaat') },
			]
		},

		/** @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md */
		selectedType() {
			return (
				this.typeOptions.find((o) => o.id === this.form.competenceType)
				|| this.typeOptions[0]
			)
		},

		/** @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md */
		selectedRole() {
			return (
				this.roleOptions.find((o) => o.id === this.form.toegewezenRol)
				|| null
			)
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md */
		formErrors() {
			const errs = {}
			if (!this.form.mandateNumber)
				errs.mandateNumber = t('dossiq', 'Mandaatnummer is required')
			if (!this.form.description)
				errs.description = t('dossiq', 'Omschrijving is required')
			if (!this.form.competenceType)
				errs.competenceType = t('dossiq', 'Bevoegdheidstype is required')
			if (!this.form.legalBasis)
				errs.legalBasis = t('dossiq', 'Wettelijke grondslag is required')
			try {
				this.form.terms = this.voorwaardenJson.trim()
					? JSON.parse(this.voorwaardenJson)
					: {}
			} catch (e) {
				errs.terms = t('dossiq', 'Voorwaarden must be valid JSON')
			}
			return errs
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/_editorForm.scss' as editor;

.mandaat-editor {
	padding: 24px;
	max-width: 720px;
}

.form-group {
	margin-bottom: 14px;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

@include editor.required-labels;

.mandaat-editor__textarea {
	@include editor.bordered-input;
	font-family: inherit;
}

.mandaat-editor__textarea--mono {
	font-family: monospace;
	font-size: 12px;
}

.mandaat-editor__date {
	@include editor.bordered-input;
}

@include editor.field-error;

.mandaat-editor__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>

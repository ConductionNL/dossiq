<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal :name="title" size="normal" @close="$emit('close')">
		<div class="termijn-definitie-editor">
			<h2>{{ title }}</h2>

			<div class="form-group">
				<label class="required" for="td-zaaktype">{{
					t('procest', 'Zaaktype')
				}}</label>
				<NcSelect
					id="td-zaaktype"
					:modelValue="selectedZaaktype"
					:options="zaaktypeOptions"
					:taggable="true"
					:inputLabel="t('procest', 'Zaaktype')"
					:placeholder="t('procest', 'Select or type a zaaktype slug')"
					@update:modelValue="
						(v) => (form.zaaktype = v ? v.id || v.label || v : '')
					" />
				<span v-if="errors.zaaktype" class="field-error">{{
					errors.zaaktype
				}}</span>
			</div>

			<div class="form-group">
				<label class="required" for="td-grondslag">{{
					t('procest', 'Legal basis')
				}}</label>
				<NcTextField
					id="td-grondslag"
					:modelValue="form.grondslag"
					:placeholder="t('procest', 'e.g. AWB art. 4:13 lid 2')"
					@update:modelValue="(v) => (form.grondslag = v)" />
				<span v-if="errors.grondslag" class="field-error">{{
					errors.grondslag
				}}</span>
			</div>

			<div class="form-group">
				<label class="required" for="td-duur">{{
					t('procest', 'Duration (days)')
				}}</label>
				<NcTextField
					id="td-duur"
					type="number"
					:modelValue="String(form.duurDagen)"
					@update:modelValue="(v) => (form.duurDagen = Number(v) || 0)" />
				<span v-if="errors.duurDagen" class="field-error">{{
					errors.duurDagen
				}}</span>
			</div>

			<div class="form-group">
				<label for="td-categorie">{{ t('procest', 'Category') }}</label>
				<NcSelect
					id="td-categorie"
					:modelValue="selectedCategorie"
					:options="categorieOptions"
					:inputLabel="t('procest', 'Category')"
					@update:modelValue="(v) => (form.categorie = v ? v.id : '')" />
			</div>

			<div class="form-group">
				<label for="td-extendable">{{
					t('procest', 'Extension allowed')
				}}</label>
				<NcCheckboxRadioSwitch
					:modelValue="form.extendable"
					@update:modelValue="(v) => (form.extendable = v)">
					{{ t('procest', 'Tenant may grant an extension on this term') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="form.extendable" class="form-group">
				<label for="td-ext-dagen">{{
					t('procest', 'Max extension (days)')
				}}</label>
				<NcTextField
					id="td-ext-dagen"
					type="number"
					:modelValue="String(form.maxExtensionDagen)"
					@update:modelValue="
						(v) => (form.maxExtensionDagen = Number(v) || 0)
					" />
			</div>

			<div class="form-group form-group--note">
				<p class="termijn-editor__note">
					{{
						t(
							'procest',
							'Saving creates a new version effective tomorrow; the prior version stays valid until end-of-day today. Cases in flight keep the version they started with.',
						)
					}}
				</p>
			</div>

			<div class="termijn-definitie-editor__actions">
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="18" />
						<ContentSave v-else :size="18" />
					</template>
					{{
						saving
							? t('procest', 'Saving…')
							: t('procest', 'Save new version')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

export default {
	name: 'TermijnDefinitieEditor',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		ContentSave,
	},

	props: {
		definition: {
			type: Object,
			default: null,
		},

		zaaktypeOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['save', 'close'],
	data() {
		return {
			saving: false,
			errors: {},
			form: {
				zaaktype: this.definition?.zaaktype || '',
				grondslag: this.definition?.grondslag || '',
				duurDagen: this.definition?.duurDagen || this.definition?.duur || 0,
				categorie: this.definition?.categorie || 'beslis',
				extendable: this.definition?.extendable || false,
				maxExtensionDagen: this.definition?.maxExtensionDagen || 0,
			},
		}
	},

	computed: {
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		title() {
			return this.definition
				? t('procest', 'New version of {z}', { z: this.definition.zaaktype })
				: t('procest', 'New term definition')
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		selectedZaaktype() {
			if (!this.form.zaaktype) return null
			const hit = this.zaaktypeOptions.find((o) => o.id === this.form.zaaktype)
			return hit || { id: this.form.zaaktype, label: this.form.zaaktype }
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		categorieOptions() {
			return [
				{ id: 'beslis', label: t('procest', 'Decision deadline') },
				{ id: 'herstel', label: t('procest', 'Remediation period') },
				{ id: 'bezwaar', label: t('procest', 'Objection period') },
				{ id: 'beroep', label: t('procest', 'Appeal period') },
			]
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		selectedCategorie() {
			return (
				this.categorieOptions.find((o) => o.id === this.form.categorie)
				|| this.categorieOptions[0]
			)
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		validate() {
			const errs = {}
			if (!this.form.zaaktype)
				errs.zaaktype = t('procest', 'Zaaktype is required')
			if (!this.form.grondslag)
				errs.grondslag = t('procest', 'Wettelijke grondslag is required')
			if (!this.form.duurDagen || this.form.duurDagen < 1) {
				errs.duurDagen = t('procest', 'Duration must be at least 1 day')
			}
			this.errors = errs
			return Object.keys(errs).length === 0
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		async save() {
			if (!this.validate()) return
			this.saving = true
			try {
				this.$emit('save', { ...this.form })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.termijn-definitie-editor {
	padding: 24px;
	max-width: 560px;
}

.form-group {
	margin-bottom: 14px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-group label.required::after {
	content: ' *';
	color: var(--color-error);
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.termijn-editor__note {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 8px 10px;
	border-radius: 6px;
}

.termijn-definitie-editor__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>

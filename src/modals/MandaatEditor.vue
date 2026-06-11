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
				<label class="required" for="me-num">{{ t('procest', 'Mandaatnummer') }}</label>
				<NcTextField
					id="me-num"
					:value="form.mandaatNummer"
					:error="!!errors.mandaatNummer"
					:helper-text="errors.mandaatNummer"
					@update:value="v => form.mandaatNummer = v" />
			</div>

			<div class="form-group">
				<label class="required" for="me-omschr">{{ t('procest', 'Omschrijving') }}</label>
				<textarea
					id="me-omschr"
					v-model="form.omschrijving"
					class="mandaat-editor__textarea"
					rows="3" />
				<span v-if="errors.omschrijving" class="field-error">{{ errors.omschrijving }}</span>
			</div>

			<div class="form-group">
				<label class="required" for="me-type">{{ t('procest', 'Bevoegdheidstype') }}</label>
				<NcSelect
					id="me-type"
					:value="selectedType"
					:options="typeOptions"
					:input-label="t('procest', 'Bevoegdheidstype')"
					@input="v => form.bevoegdheidType = v ? v.id : ''" />
				<span v-if="errors.bevoegdheidType" class="field-error">{{ errors.bevoegdheidType }}</span>
			</div>

			<div class="form-group">
				<label class="required" for="me-grond">{{ t('procest', 'Wettelijke grondslag') }}</label>
				<NcTextField
					id="me-grond"
					:value="form.wettelijkeGrondslag"
					:error="!!errors.wettelijkeGrondslag"
					:helper-text="errors.wettelijkeGrondslag"
					@update:value="v => form.wettelijkeGrondslag = v" />
			</div>

			<div class="form-group">
				<label for="me-voorw">{{ t('procest', 'Voorwaarden (JSON)') }}</label>
				<textarea
					id="me-voorw"
					v-model="voorwaardenJson"
					class="mandaat-editor__textarea mandaat-editor__textarea--mono"
					rows="6"
					:placeholder="t('procest', 'e.g. { \&quot;maxBedrag\&quot;: 50000, \&quot;categorie\&quot;: [\&quot;subsidie\&quot;] }')" />
				<span v-if="errors.voorwaarden" class="field-error">{{ errors.voorwaarden }}</span>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label class="required" for="me-inwerkingtreding">{{ t('procest', 'In werkingtreding') }}</label>
					<input
						id="me-inwerkingtreding"
						type="date"
						class="mandaat-editor__date"
						:value="form.inWerkingtreding"
						@input="form.inWerkingtreding = $event.target.value">
				</div>
				<div class="form-group">
					<label for="me-verval">{{ t('procest', 'Vervaldatum') }}</label>
					<input
						id="me-verval"
						type="date"
						class="mandaat-editor__date"
						:value="form.vervaldatum"
						@input="form.vervaldatum = $event.target.value">
				</div>
			</div>

			<div class="form-group">
				<label for="me-rol">{{ t('procest', 'Toegewezen rol') }}</label>
				<NcSelect
					id="me-rol"
					:value="selectedRole"
					:options="roleOptions"
					:input-label="t('procest', 'Toegewezen rol')"
					@input="v => form.toegewezenRol = v ? v.id : ''" />
			</div>

			<div class="mandaat-editor__actions">
				<NcButton @click="$emit('close')">{{ t('procest', 'Cancel') }}</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="18" />
						<ContentSave v-else :size="18" />
					</template>
					{{ saving ? t('procest', 'Saving…') : t('procest', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcButton,
	NcTextField,
	NcSelect,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

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
				mandaatNummer: this.mandaat?.mandaatNummer || '',
				omschrijving: this.mandaat?.omschrijving || '',
				bevoegdheidType: this.mandaat?.bevoegdheidType || 'beslissingsbevoegdheid',
				wettelijkeGrondslag: this.mandaat?.wettelijkeGrondslag || '',
				inWerkingtreding: this.mandaat?.inWerkingtreding || new Date().toISOString().slice(0, 10),
				vervaldatum: this.mandaat?.vervaldatum || '',
				toegewezenRol: this.mandaat?.toegewezenRol || '',
				voorwaarden: this.mandaat?.voorwaarden || {},
			},
			voorwaardenJson: JSON.stringify(this.mandaat?.voorwaarden || {}, null, 2),
		}
	},
	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		title() {
			return this.mandaat
				? t('procest', 'Edit mandaat')
				: t('procest', 'New mandaat')
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		typeOptions() {
			return [
				{ id: 'beslissingsbevoegdheid', label: t('procest', 'Beslissingsbevoegdheid') },
				{ id: 'ondertekeningsbevoegdheid', label: t('procest', 'Ondertekeningsbevoegdheid') },
				{ id: 'gemandateerde-bevoegdheid', label: t('procest', 'Gemandateerde bevoegdheid') },
				{ id: 'doormandaat', label: t('procest', 'Doormandaat') },
			]
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedType() {
			return this.typeOptions.find(o => o.id === this.form.bevoegdheidType) || this.typeOptions[0]
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedRole() {
			return this.roleOptions.find(o => o.id === this.form.toegewezenRol) || null
		},
	},
	methods: {
		t,
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		validate() {
			const errs = {}
			if (!this.form.mandaatNummer) errs.mandaatNummer = t('procest', 'Mandaatnummer is required')
			if (!this.form.omschrijving) errs.omschrijving = t('procest', 'Omschrijving is required')
			if (!this.form.bevoegdheidType) errs.bevoegdheidType = t('procest', 'Bevoegdheidstype is required')
			if (!this.form.wettelijkeGrondslag) errs.wettelijkeGrondslag = t('procest', 'Wettelijke grondslag is required')
			try {
				this.form.voorwaarden = this.voorwaardenJson.trim() ? JSON.parse(this.voorwaardenJson) : {}
			} catch (e) {
				errs.voorwaarden = t('procest', 'Voorwaarden must be valid JSON')
			}
			this.errors = errs
			return Object.keys(errs).length === 0
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
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

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-group label.required::after {
	content: ' *';
	color: var(--color-error);
}

.mandaat-editor__textarea {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: inherit;
}

.mandaat-editor__textarea--mono {
	font-family: monospace;
	font-size: 12px;
}

.mandaat-editor__date {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.mandaat-editor__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Role create/edit dialog — own file per ADR-004 modal-isolation.
-->
<template>
	<NcDialog
		:open="true"
		:name="title"
		size="normal"
		@update:open="v => { if (!v) $emit('close') }">
		<div class="rol-editor">
			<div class="form-group">
				<label class="required" for="rol-naam">{{ t('procest', 'Naam') }}</label>
				<NcTextField
					id="rol-naam"
					:value="form.naam"
					:error="!!errors.naam"
					:helper-text="errors.naam"
					@update:value="v => form.naam = v" />
			</div>

			<div class="form-group">
				<label for="rol-type">{{ t('procest', 'Type') }}</label>
				<NcSelect
					id="rol-type"
					:value="selectedType"
					:options="typeOptions"
					:input-label="t('procest', 'Type')"
					@input="v => form.type = v ? v.id : ''" />
			</div>

			<div class="form-group">
				<label for="rol-parent">{{ t('procest', 'Parent role') }}</label>
				<NcSelect
					id="rol-parent"
					:value="selectedParent"
					:options="parentOptions"
					:input-label="t('procest', 'Parent role')"
					@input="v => form.parentRole = v ? v.id : ''" />
			</div>

			<div class="form-group">
				<label for="rol-afdeling">{{ t('procest', 'Afdeling') }}</label>
				<NcTextField
					id="rol-afdeling"
					:value="form.afdeling"
					@update:value="v => form.afdeling = v" />
			</div>

			<div class="form-group">
				<label for="rol-team">{{ t('procest', 'Team') }}</label>
				<NcTextField
					id="rol-team"
					:value="form.team"
					@update:value="v => form.team = v" />
			</div>

			<div class="form-group">
				<label for="rol-niveau">{{ t('procest', 'Mandaat niveau') }}</label>
				<NcTextField
					id="rol-niveau"
					type="number"
					:value="String(form.mandaatNiveau)"
					@update:value="v => form.mandaatNiveau = Number(v) || 0" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" @click="save">
				{{ t('procest', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'RolEditorDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	props: {
		role: { type: Object, default: null },
		parentOptions: { type: Array, default: () => [] },
	},
	emits: ['save', 'close'],
	data() {
		return {
			errors: {},
			form: {
				naam: this.role?.naam || '',
				type: this.role?.type || 'medewerker',
				parentRole: this.role?.parentRole || '',
				afdeling: this.role?.afdeling || '',
				team: this.role?.team || '',
				mandaatNiveau: this.role?.mandaatNiveau || 1,
			},
		}
	},
	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		title() {
			return this.role ? t('procest', 'Edit role') : t('procest', 'New role')
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		typeOptions() {
			return [
				{ id: 'bestuurder', label: t('procest', 'Bestuurder') },
				{ id: 'manager', label: t('procest', 'Manager') },
				{ id: 'teamleider', label: t('procest', 'Teamleider') },
				{ id: 'medewerker', label: t('procest', 'Medewerker') },
				{ id: 'waarnemer', label: t('procest', 'Waarnemer') },
			]
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedType() {
			return this.typeOptions.find(o => o.id === this.form.type) || this.typeOptions[3]
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedParent() {
			return this.parentOptions.find(o => o.id === this.form.parentRole) || this.parentOptions[0]
		},
	},
	methods: {
		t,
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		validate() {
			const errs = {}
			if (!this.form.naam) errs.naam = t('procest', 'Naam is required')
			this.errors = errs
			return Object.keys(errs).length === 0
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		save() {
			if (!this.validate()) return
			this.$emit('save', { ...this.form })
		},
	},
}
</script>

<style scoped>
.rol-editor {
	padding: 8px 4px;
	min-width: 420px;
}

.form-group {
	margin-bottom: 12px;
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
</style>

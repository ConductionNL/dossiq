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
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="rol-editor">
			<div class="form-group">
				<label class="required" for="rol-naam">{{
					t('procest', 'Naam')
				}}</label>
				<NcTextField
					id="rol-naam"
					:modelValue="form.name"
					:error="!!errors.name"
					:helperText="errors.name"
					@update:modelValue="(v) => (form.name = v)" />
			</div>

			<div class="form-group">
				<label for="rol-type">{{ t('procest', 'Type') }}</label>
				<NcSelect
					id="rol-type"
					:modelValue="selectedType"
					:options="typeOptions"
					:inputLabel="t('procest', 'Type')"
					@update:modelValue="(v) => (form.type = v ? v.id : '')" />
			</div>

			<div class="form-group">
				<label for="rol-parent">{{ t('procest', 'Parent role') }}</label>
				<NcSelect
					id="rol-parent"
					:modelValue="selectedParent"
					:options="parentOptions"
					:inputLabel="t('procest', 'Parent role')"
					@update:modelValue="(v) => (form.parentRole = v ? v.id : '')" />
			</div>

			<div class="form-group">
				<label for="rol-afdeling">{{ t('procest', 'Department') }}</label>
				<NcTextField
					id="rol-afdeling"
					:modelValue="form.department"
					@update:modelValue="(v) => (form.department = v)" />
			</div>

			<div class="form-group">
				<label for="rol-team">{{ t('procest', 'Team') }}</label>
				<NcTextField
					id="rol-team"
					:modelValue="form.team"
					@update:modelValue="(v) => (form.team = v)" />
			</div>

			<div class="form-group">
				<label for="rol-niveau">{{ t('procest', 'Mandaat niveau') }}</label>
				<NcTextField
					id="rol-niveau"
					type="number"
					:modelValue="String(form.mandateLevel)"
					@update:modelValue="
						(v) => (form.mandateLevel = Number(v) || 0)
					" />
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
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'

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
				name: this.role?.name || '',
				type: this.role?.type || 'medewerker',
				parentRole: this.role?.parentRole || '',
				department: this.role?.department || '',
				team: this.role?.team || '',
				mandateLevel: this.role?.mandateLevel || 1,
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
				{ id: 'bestuurder', label: t('procest', 'Director') },
				{ id: 'manager', label: t('procest', 'Manager') },
				{ id: 'teamleider', label: t('procest', 'Team leader') },
				{ id: 'medewerker', label: t('procest', 'Employee') },
				{ id: 'waarnemer', label: t('procest', 'Substitute') },
			]
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedType() {
			return (
				this.typeOptions.find((o) => o.id === this.form.type)
				|| this.typeOptions[3]
			)
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedParent() {
			return (
				this.parentOptions.find((o) => o.id === this.form.parentRole)
				|| this.parentOptions[0]
			)
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		validate() {
			const errs = {}
			if (!this.form.name) errs.name = t('procest', 'Naam is required')
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
.role-editor {
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

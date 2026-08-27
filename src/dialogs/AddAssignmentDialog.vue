<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="true"
		:name="t('dossiq', 'Add role assignment')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="add-assignment">
			<div class="form-group">
				<label class="required" for="aa-person">{{
					t('dossiq', 'Person (UID / email)')
				}}</label>
				<NcTextField
					id="aa-person"
					:modelValue="form.persoonId"
					:error="!!errors.persoonId"
					:helperText="errors.persoonId"
					@update:modelValue="(v) => (form.persoonId = v)" />
			</div>

			<div class="form-group">
				<label class="required" for="aa-role">{{
					t('dossiq', 'Role')
				}}</label>
				<NcSelect
					id="aa-role"
					:modelValue="selectedRole"
					:options="roleOptions"
					:inputLabel="t('dossiq', 'Role')"
					@update:modelValue="(v) => (form.roleId = v ? v.id : '')" />
				<span v-if="errors.roleId" class="field-error">{{
					errors.roleId
				}}</span>
			</div>

			<div class="form-group">
				<label for="aa-type">{{ t('dossiq', 'Type') }}</label>
				<NcSelect
					id="aa-type"
					:modelValue="selectedType"
					:options="typeOptions"
					:inputLabel="t('dossiq', 'Type')"
					@update:modelValue="
						(v) => (form.allocationType = v ? v.id : '')
					" />
			</div>

			<div class="form-group">
				<label class="required" for="aa-vanaf">{{
					t('dossiq', 'From')
				}}</label>
				<input
					id="aa-vanaf"
					type="date"
					class="add-assignment__date"
					:value="form.vanaf"
					@input="form.vanaf = $event.target.value" />
			</div>

			<div class="form-group">
				<label for="aa-tot">{{ t('dossiq', 'Up to and including') }}</label>
				<input
					id="aa-tot"
					type="date"
					class="add-assignment__date"
					:value="form.totEnMet"
					@input="form.totEnMet = $event.target.value" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" @click="save">
				{{ t('dossiq', 'Add') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AddAssignmentDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	props: {
		roleOptions: { type: Array, default: () => [] },
	},

	emits: ['save', 'close'],
	data() {
		return {
			errors: {},
			form: {
				persoonId: '',
				roleId: '',
				allocationType: 'reguliere',
				vanaf: new Date().toISOString().slice(0, 10),
				totEnMet: '',
			},
		}
	},

	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		typeOptions() {
			return [
				{ id: 'reguliere', label: t('dossiq', 'Regular assignment') },
				{ id: 'observer', label: t('dossiq', 'Substitute') },
				{ id: 'plaatsvervanger', label: t('dossiq', 'Deputy') },
			]
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedRole() {
			return this.roleOptions.find((o) => o.id === this.form.roleId) || null
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		selectedType() {
			return (
				this.typeOptions.find((o) => o.id === this.form.allocationType)
				|| this.typeOptions[0]
			)
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		validate() {
			const errs = {}
			if (!this.form.persoonId)
				errs.persoonId = t('dossiq', 'Person is required')
			if (!this.form.roleId) errs.roleId = t('dossiq', 'Role is required')
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
.add-assignment {
	min-width: 420px;
	padding: 8px 4px;
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

.add-assignment__date {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
}
</style>

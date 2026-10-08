<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One field the target case type requires, asked for in the rebind dialog.

  The widget follows the field's kind as the server reports it, so a number is
  asked as a number, a date as a date, a list as a choice and a yes/no as a
  switch. The value goes back as text, which is how the case stores every
  answer; the server decides whether it fits, and this field only shows that
  verdict.

  @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
-->
<template>
	<div class="rebind-field" :data-testid="`case-rebind-required-${field.name}`">
		<NcSelect
			v-if="field.kind === 'choice'"
			:modelValue="modelValue || null"
			:inputLabel="field.name"
			:options="field.choices"
			:clearable="true"
			@update:modelValue="(v) => $emit('update:modelValue', v || '')" />

		<NcCheckboxRadioSwitch
			v-else-if="field.kind === 'boolean'"
			type="switch"
			:modelValue="modelValue === 'true'"
			@update:modelValue="
				(v) => $emit('update:modelValue', v ? 'true' : 'false')
			">
			{{ field.name }}
		</NcCheckboxRadioSwitch>

		<NcTextField
			v-else
			:modelValue="modelValue"
			:label="field.name"
			:type="inputType"
			:error="Boolean(problem)"
			@update:modelValue="
				(v) => $emit('update:modelValue', String(v ?? ''))
			" />

		<p v-if="field.description" class="rebind-field__help">
			{{ field.description }}
		</p>
		<p
			v-if="problem"
			class="rebind-field__problem"
			:data-testid="`case-rebind-required-${field.name}-problem`">
			{{ problem }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

/** The HTML input type for each kind the text field asks for. */
const INPUT_TYPES = {
	number: 'number',
	integer: 'number',
	date: 'date',
	'date-time': 'datetime-local',
	email: 'email',
	url: 'url',
}

export default {
	name: 'RebindPropertyField',

	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * One `required` row of the impact: name, kind, choices, description,
		 * valid.
		 */
		field: {
			type: Object,
			required: true,
		},

		/** The answer, as text. */
		modelValue: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The input type for a text-like field.
		 *
		 * @return {string} The type.
		 *
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		inputType() {
			return INPUT_TYPES[this.field.kind] || 'text'
		},

		/**
		 * What is wrong with the answer, as the server judged it.
		 *
		 * @return {string} The sentence, or '' when the answer is fine.
		 *
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		problem() {
			if (this.field.valid) {
				return ''
			}
			if (!this.modelValue) {
				return t('dossiq', 'Fill this in to continue.')
			}
			return t('dossiq', 'This value does not fit this field.')
		},
	},
}
</script>

<style scoped>
.rebind-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rebind-field__help {
	color: var(--color-text-maxcontrast);
}

.rebind-field__problem {
	color: var(--color-error-text, var(--color-error));
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Asks for what the case type declared before the case may exist.

  The declaration decides which fields appear: the before-creation list, plus
  the classification when the case type made it the access rule. A field with a
  known vocabulary is a select, one without is a text field, and the button
  stays disabled while anything is unanswered.

  🔴 THIS IS NOT THE ENFORCEMENT. `IntakeRequirementsListener` refuses the write
  on the pre-persist event, and it goes on refusing for an import or an
  integration that never opens this dialog. What this buys is that a handler is
  asked in the form rather than told after the save failed.

  Modal isolation per ADR-004: lives in src/modals/.

  @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
-->
<template>
	<NcModal
		size="normal"
		:name="t('dossiq', 'Before this case can be opened')"
		@close="$emit('close')">
		<div class="intake-requirements-modal">
			<p class="intake-requirements-modal__hint">
				{{
					t(
						'dossiq',
						'This case type asks for a few things before the case exists. Answer them and the case is opened.',
					)
				}}
			</p>

			<div
				v-for="field in fields"
				:key="field"
				class="intake-requirements-modal__field">
				<NcSelect
					v-if="optionsFor(field).length > 0"
					:inputLabel="labelFor(field)"
					:options="optionsFor(field)"
					:modelValue="values[field] || null"
					:data-testid="`intake-field-${field}`"
					@update:modelValue="setValue(field, $event)" />
				<NcTextField
					v-else
					:label="labelFor(field)"
					:modelValue="values[field] || ''"
					:data-testid="`intake-field-${field}`"
					@update:modelValue="setValue(field, $event)" />
			</div>

			<p
				v-if="schemeUnresolved"
				class="intake-requirements-modal__warning"
				data-testid="intake-scheme-unresolved">
				{{
					t(
						'dossiq',
						'This case type classifies against a scheme this instance does not know, so the case cannot be opened yet. Ask an administrator to add the scheme.',
					)
				}}
			</p>

			<div class="intake-requirements-modal__actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!canOpen"
					data-testid="intake-requirements-confirm"
					@click="$emit('confirm', { ...values })">
					{{ t('dossiq', 'Open the case') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	fieldsToAsk,
	missingFrom,
	normaliseDeclaration,
	optionsFor,
} from '../utils/intakeRequirements.js'

export default {
	name: 'IntakeRequirementsModal',
	components: {
		NcButton,
		NcModal,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The declaration the requirements endpoint answered with. */
		declaration: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			values: {},
		}
	},

	computed: {
		/** @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md */
		read() {
			return normaliseDeclaration(this.declaration)
		},

		/** @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md */
		fields() {
			return fieldsToAsk(this.declaration)
		},

		/** @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md */
		schemeUnresolved() {
			return this.read.classificationIsAccessRule && !this.read.schemeResolves
		},

		/** @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md */
		canOpen() {
			if (this.schemeUnresolved) {
				return false
			}

			return missingFrom(this.values, this.declaration).length === 0
		},
	},

	methods: {
		/**
		 * The values one field may carry.
		 *
		 * @param {string} field The case field.
		 * @return {string[]} The allowed values, empty when it is free text.
		 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
		 */
		optionsFor(field) {
			return optionsFor(field, this.declaration)
		},

		/**
		 * The label one field is asked under.
		 *
		 * @param {string} field The case field.
		 * @return {string} The label, translated.
		 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
		 */
		labelFor(field) {
			// 🔴 EIGHT LITERAL `t()` CALLS, NOT A MAP OF BARE STRINGS RUN THROUGH
			// ONE `t()`. The extractor in tests/l10n/check-l10n.js reads literal
			// call sites; a map would hand `t()` a variable, the keys would never
			// be extracted, and every label would render English inside an
			// otherwise Dutch form with nothing to say so.
			switch (field) {
				case 'communicationChannel':
					return t('dossiq', 'Communication channel')
				case 'confidentiality':
					return t('dossiq', 'Confidentiality')
				case 'classification':
					return t('dossiq', 'Classification')
				case 'sensitivity':
					return t('dossiq', 'Sensitivity')
				case 'actionFacet':
					return t('dossiq', 'Action facet')
				case 'insightLevel':
					return t('dossiq', 'Insight level')
				case 'assignedGroup':
					return t('dossiq', 'Team')
				case 'assignee':
					return t('dossiq', 'Assignee')
				default:
					return field
			}
		},

		/**
		 * Record one answer.
		 *
		 * NcSelect hands back the option, which may be an object when a caller
		 * supplied one, so the reference is read off it rather than assumed.
		 *
		 * @param {string} field The case field.
		 * @param {string|object} value The chosen value.
		 * @return {void}
		 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
		 */
		setValue(field, value) {
			const chosen =
				value && typeof value === 'object' ? value.id || value.label : value

			this.values = { ...this.values, [field]: chosen ?? '' }
		},
	},
}
</script>

<style scoped>
.intake-requirements-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.intake-requirements-modal__hint {
	color: var(--color-text-maxcontrast);
}

.intake-requirements-modal__warning {
	color: var(--color-error);
}

.intake-requirements-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

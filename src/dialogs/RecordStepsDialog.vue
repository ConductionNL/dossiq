<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  A wizard that writes one record on the case and, when the action asks for it,
  a follow-up task. The steps, the fields, what prefills them and the task are
  the action's `props` in the manifest, so a procedure is configuration here
  (board DqHandhaving "Handhaving starten").

  It reads the case from the route, because an open-modal action forwards its
  props verbatim.

  @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
-->
<template>
	<CnWizardDialog
		ref="wizard"
		:steps="wizardSteps"
		:dialogTitle="t('dossiq', dialogTitle)"
		:validate="validateStep"
		:submitLabel="t('dossiq', submitLabel)"
		:successText="t('dossiq', successText)"
		data-testid="record-steps-dialog"
		@submit="save"
		@close="$emit('close')">
		<template v-for="step in steps" #[`step-${step.id}`] :key="step.id">
			<p v-if="step.intro" class="record-steps__intro">
				{{ t('dossiq', step.intro) }}
			</p>
			<div class="record-steps__fields">
				<CnSettingsField
					v-for="field in visibleFields(step)"
					:key="field.key"
					:field="translatedField(field)"
					:hint="field.hint ? t('dossiq', field.hint) : ''"
					:modelValue="values[field.key]"
					@update:modelValue="values[field.key] = $event" />
			</div>
		</template>
	</CnWizardDialog>
</template>

<script>
import { CnSettingsField, CnWizardDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { useEngineTaskStore } from '../store/modules/engineTask.js'
import {
	defaultValues,
	firstMissingField,
	followUpTask,
	prefillValues,
	recordFromValues,
	stepFields,
} from '../utils/recordSteps.js'

/** The event the page reads its lists again on. */
const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'RecordStepsDialog',

	components: { CnSettingsField, CnWizardDialog },

	props: {
		/** The case the record belongs to. Absent when the manifest opened the dialog: the route answers. */
		caseId: {
			type: String,
			default: '',
		},

		/** The dialog's title. */
		dialogTitle: {
			type: String,
			required: true,
		},

		/** The schema of the record the wizard writes, in the dossiq register. */
		schema: {
			type: String,
			required: true,
		},

		/**
		 * The steps: `{ id, title, intro?, fields: [{ key, label, type?, options?, required?, hint?, when?, transient? }] }`.
		 * `when` (`{ field, in }`) shows a field for those values of another; a
		 * `transient` field is asked but not stored on the record.
		 */
		steps: {
			type: Array,
			required: true,
		},

		/**
		 * A record of the case to start from: `{ schema, map }`, `map` naming
		 * the wizard's field for each of that record's fields.
		 */
		prefillFrom: {
			type: Object,
			default: null,
		},

		/** The task to add after saving: `{ title, dueInDaysField? }`. */
		task: {
			type: Object,
			default: null,
		},

		/** The label of the last step's button. */
		submitLabel: {
			type: String,
			default: 'Save',
		},

		/** What the dialog says after saving. */
		successText: {
			type: String,
			default: 'Saved.',
		},
	},

	emits: ['close'],

	/**
	 * @return {object} The engine task store.
	 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
	 */
	setup() {
		return { tasks: useEngineTaskStore() }
	},

	data() {
		return {
			values: defaultValues(this.steps),
		}
	},

	computed: {
		/**
		 * @return {string} The case: the prop, else the route.
		 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {Array<object>} The steps as the stepper reads them.
		 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
		 */
		wizardSteps() {
			return this.steps.map((step) => ({ id: step.id, title: t('dossiq', step.title) }))
		},
	},

	/**
	 * Start from the case's record, when the action names one.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
	 */
	async mounted() {
		if (!this.prefillFrom || !this.targetCaseId) {
			return
		}
		try {
			const { data } = await axios.get(
				generateUrl(`/apps/openregister/api/objects/dossiq/${this.prefillFrom.schema}`),
				{ params: { case: this.targetCaseId, _limit: 1, '_order[@self.created]': 'DESC' } },
			)
			this.values = { ...this.values, ...prefillValues(data?.results?.[0], this.prefillFrom.map, this.steps) }
		} catch {
			// Without the record the fields open empty, and the handler fills them in.
		}
	},

	methods: {
		t,

		/**
		 * @param {object} step A step.
		 * @return {Array<object>} The fields that apply now.
		 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
		 */
		visibleFields(step) {
			return stepFields(step, this.values)
		},

		/**
		 * @param {object} field A field.
		 * @return {object} The field with its label and option labels translated.
		 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
		 */
		translatedField(field) {
			return {
				...field,
				label: t('dossiq', field.label),
				options: field.options?.map((option) => ({ ...option, label: t('dossiq', option.label) })),
			}
		},

		/**
		 * @param {string} stepId The step the wizard is leaving.
		 * @return {string|boolean} A sentence naming the missing field, else true.
		 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
		 */
		validateStep(stepId) {
			const step = this.steps.find((candidate) => candidate.id === stepId)
			const missing = step ? firstMissingField(step, this.values) : null
			return missing ? t('dossiq', '{field} is required.', { field: t('dossiq', missing.label) }) : true
		},

		/**
		 * Write the record, then the task. A refused record stays on the step
		 * with the server's sentence; a record saved without its task says so.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
		 */
		async save() {
			try {
				await axios.post(
					generateUrl(`/apps/openregister/api/objects/dossiq/${this.schema}`),
					recordFromValues(this.values, this.steps, this.targetCaseId),
				)
			} catch (error) {
				this.$refs.wizard.setError(error?.response?.data?.message || t('dossiq', 'This could not be saved.'))
				return
			}
			const task = followUpTask(this.task, this.values, this.targetCaseId)
			const created = task === null || await this.tasks.create({ ...task, title: t('dossiq', task.title) })
			emit(PAGE_REFRESH)
			this.$refs.wizard.setResult(created
				? { success: true }
				: { success: false, error: t('dossiq', 'The record is saved, but its task could not be made.') })
		},
	},
}
</script>

<style scoped>
.record-steps__intro {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.record-steps__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px 20px;
}
</style>

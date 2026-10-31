<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Add or edit one conditional rule of a case type: when, the conditions over
  this type's own fields (the library's condition editor) and what happens.
  Emits the rule; the Rules tab stores it on the case type.

  @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
-->
<template>
	<NcDialog
		:name="rule ? t('dossiq', 'Edit rule') : t('dossiq', 'Add rule')"
		:open="true"
		size="normal"
		@update:open="(open) => !open && $emit('close')">
		<div class="rule-dialog">
			<NcTextField
				:modelValue="form.name"
				:label="t('dossiq', 'Name (optional)')"
				data-testid="rule-name"
				@update:modelValue="(v) => (form.name = v)" />

			<NcSelect
				:modelValue="whenOption"
				:options="whenOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('dossiq', 'When')"
				data-testid="rule-when"
				@update:modelValue="(o) => (form.event = o ? o.id : 'saved')" />
			<NcSelect
				v-if="form.event === 'statusEntered'"
				:modelValue="statusOption"
				:options="statusOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('dossiq', 'Status')"
				data-testid="rule-status"
				@update:modelValue="(o) => (form.status = o ? o.id : '')" />

			<CnConditionListEditor
				v-model="form.conditions"
				:fields="fields"
				:label="t('dossiq', 'If')"
				data-testid="rule-conditions" />

			<NcSelect
				:modelValue="actionOption"
				:options="actionOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('dossiq', 'Then')"
				data-testid="rule-action"
				@update:modelValue="(o) => (form.action = o ? o.id : 'addTask')" />
			<NcTextField
				v-if="form.action === 'addTask'"
				:modelValue="form.title"
				:label="t('dossiq', 'Task')"
				data-testid="rule-task-title"
				@update:modelValue="(v) => (form.title = v)" />
			<NcSelect
				v-else
				:modelValue="targetOption"
				:options="targetOptions"
				label="label"
				:clearable="false"
				:inputLabel="targetLabel"
				data-testid="rule-target"
				@update:modelValue="(o) => (form.target = o ? o.id : '')" />
			<NcSelect
				v-if="form.action === 'sendMessage'"
				:modelValue="roleOption"
				:options="roleOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('dossiq', 'To')"
				data-testid="rule-recipient-role"
				@update:modelValue="(o) => (form.recipientRole = o ? o.id : '')" />
			<NcTextField
				v-if="form.action === 'setField'"
				:modelValue="form.value"
				:label="t('dossiq', 'Value')"
				@update:modelValue="(v) => (form.value = v)" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!complete" data-testid="rule-save" @click="save">
				{{ t('dossiq', 'Save rule') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnConditionListEditor } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { fromEditorCondition, toEditorCondition } from '../services/caseTypeRules.js'

const TARGET_KEYS = { chooseVariant: 'variant', sendMessage: 'emailTemplate', requireDocument: 'documentType', setField: 'field' }

export default {
	name: 'CaseTypeRuleDialog',

	components: { CnConditionListEditor, NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		/** The rule being edited, or null to add one. */
		rule: { type: Object, default: null },
		/** The id a new rule gets. */
		newId: { type: String, default: 'rule-1' },
		/** The fields a condition can name (`ruleFields`). */
		fields: { type: Array, default: () => [] },
		/** Choices per action and for statuses: `{ statuses, variants, templates, documentTypes }`, each `[{ id, label }]`. */
		choices: { type: Object, default: () => ({}) },
	},

	emits: ['close', 'save'],

	data() {
		const rule = this.rule || {}
		const action = (rule.actions || [])[0] || { action: 'addTask' }
		return {
			form: {
				name: rule.name || '',
				event: rule.when?.event || 'saved',
				status: rule.when?.status || '',
				conditions: (rule.conditions || []).map(toEditorCondition),
				action: action.action,
				title: action.title || '',
				target: action[TARGET_KEYS[action.action]] || '',
				value: action.value ?? '',
				recipientRole: action.recipientRole || '',
			},
		}
	},

	computed: {
		whenOptions() {
			return [
				{ id: 'saved', label: t('dossiq', 'When the case is saved') },
				{ id: 'created', label: t('dossiq', 'When the case is created') },
				{ id: 'statusEntered', label: t('dossiq', 'When the case enters a status') },
			]
		},

		whenOption() {
			return this.whenOptions.find((o) => o.id === this.form.event) || null
		},

		statusOptions() {
			return this.choices.statuses || []
		},

		statusOption() {
			return this.statusOptions.find((o) => o.id === this.form.status) || null
		},

		actionOptions() {
			return [
				{ id: 'addTask', label: t('dossiq', 'Add a task') },
				{ id: 'chooseVariant', label: t('dossiq', 'Choose a variant') },
				{ id: 'sendMessage', label: t('dossiq', 'Send a message') },
				{ id: 'requireDocument', label: t('dossiq', 'Require a document') },
				{ id: 'setField', label: t('dossiq', 'Set a field') },
			]
		},

		actionOption() {
			return this.actionOptions.find((o) => o.id === this.form.action) || null
		},

		targetOptions() {
			const lists = {
				chooseVariant: this.choices.variants,
				sendMessage: this.choices.templates,
				requireDocument: this.choices.documentTypes,
				setField: this.fields.filter((f) => f.type !== 'presence' && f.id !== 'status').map((f) => ({ id: f.id, label: f.label })),
			}
			return lists[this.form.action] || []
		},

		roleOptions() {
			return [{ id: '', label: t('dossiq', 'The applicant') }, ...(this.choices.roles || [])]
		},

		roleOption() {
			return this.roleOptions.find((o) => o.id === this.form.recipientRole) || this.roleOptions[0]
		},

		targetOption() {
			return this.targetOptions.find((o) => o.id === this.form.target) || null
		},

		targetLabel() {
			return { chooseVariant: t('dossiq', 'Variant'), sendMessage: t('dossiq', 'Message'), requireDocument: t('dossiq', 'Document'), setField: t('dossiq', 'Field') }[this.form.action] || ''
		},

		/**
		 * Whether the rule says enough to be saved.
		 *
		 * @return {boolean}
		 */
		complete() {
			if (this.form.event === 'statusEntered' && !this.form.status) {
				return false
			}
			return this.form.action === 'addTask' ? this.form.title.trim() !== '' : this.form.target !== ''
		},
	},

	methods: {
		/**
		 * Emit the rule in the stored shape, keeping its id and on/off.
		 *
		 * @return {void}
		 */
		save() {
			const action = { action: this.form.action }
			if (this.form.action === 'addTask') {
				action.title = this.form.title.trim()
			} else {
				action[TARGET_KEYS[this.form.action]] = this.form.target
				if (this.form.action === 'setField') {
					action.value = this.form.value
				}
				if (this.form.action === 'sendMessage' && this.form.recipientRole !== '') {
					action.recipientRole = this.form.recipientRole
				}
			}
			const when = { event: this.form.event }
			if (this.form.event === 'statusEntered') {
				when.status = this.form.status
			}
			const rule = {
				id: this.rule?.id || this.newId,
				enabled: this.rule?.enabled !== false,
				when,
				conditions: this.form.conditions.map(fromEditorCondition).filter(Boolean),
				actions: [action],
			}
			if (this.form.name.trim() !== '') {
				rule.name = this.form.name.trim()
			}
			this.$emit('save', rule)
		},
	},
}
</script>

<style scoped>
.rule-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 8px;
}
</style>

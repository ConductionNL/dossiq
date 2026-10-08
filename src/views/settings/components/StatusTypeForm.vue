<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Everything a status type is, in one form.

  One component rather than two, because the Statuses tab needs the same fields
  when you add a status and when you edit one, and the tab used to carry two
  copies of a four-field form. Two copies of a nine-field form is how a property
  ends up editable in one place and not the other.

  @spec openspec/specs/case-types/spec.md
-->
<template>
	<div class="status-type-form">
		<div class="status-type-form__row">
			<NcTextField
				:modelValue="form.name"
				:label="t('dossiq', 'Name')"
				:error="!!error"
				class="status-type-form__field"
				data-testid="status-type-name"
				@update:modelValue="(v) => update('name', v)" />
			<NcTextField
				:modelValue="String(form.order)"
				:label="t('dossiq', 'Order')"
				type="number"
				class="status-type-form__field status-type-form__field--small"
				data-testid="status-type-order"
				@update:modelValue="(v) => update('order', parseInt(v, 10) || 0)" />
		</div>

		<div class="status-type-form__row">
			<NcTextField
				:modelValue="form.description"
				:label="t('dossiq', 'Description')"
				:placeholder="t('dossiq', 'What this phase means')"
				class="status-type-form__field"
				data-testid="status-type-description"
				@update:modelValue="(v) => update('description', v)" />
		</div>

		<div class="status-type-form__row">
			<div class="status-type-form__field">
				<NcSelect
					:modelValue="selectedColour"
					:options="colourOptions"
					:inputLabel="t('dossiq', 'Colour')"
					:placeholder="t('dossiq', 'Grey')"
					data-testid="status-type-colour"
					@update:modelValue="(v) => update('colour', v ? v.id : '')">
					<template #option="option">
						<span class="status-type-form__swatch-row">
							<span
								class="status-type-form__swatch"
								:style="swatchStyle(option.id)" />
							{{ option.label }}
						</span>
					</template>
				</NcSelect>
				<p class="status-type-form__hint">
					{{
						t(
							'dossiq',
							'The colour of the badge on the case, in the list and on the board.',
						)
					}}
				</p>
			</div>

			<div class="status-type-form__field">
				<NcSelect
					:modelValue="selectedRole"
					:options="roleOptions"
					:inputLabel="t('dossiq', 'Role')"
					:placeholder="t('dossiq', 'No role')"
					data-testid="status-type-role"
					@update:modelValue="(v) => update('role', v ? v.id : '')" />
				<p class="status-type-form__hint">
					{{
						t(
							'dossiq',
							'What this status means in a process. A shipped flow moves a case by role, so it also works on a type that calls this phase something else.',
						)
					}}
				</p>
			</div>
		</div>

		<div class="status-type-form__row">
			<div class="status-type-form__field">
				<NcSelect
					:modelValue="selectedWaitingOn"
					:options="waitingOnOptions"
					:inputLabel="t('dossiq', 'Waiting on')"
					:placeholder="t('dossiq', 'Us')"
					data-testid="status-type-waiting-on"
					@update:modelValue="(v) => update('waitingOn', v ? v.id : '')" />
				<p class="status-type-form__hint">
					{{
						t(
							'dossiq',
							'Who the case waits on while it sits here. The applicant and a third party are different: only the first suspends the term.',
						)
					}}
				</p>
			</div>

			<div class="status-type-form__field">
				<NcTextField
					:modelValue="String(form.maximumDwell)"
					:label="t('dossiq', 'Maximum working days')"
					type="number"
					data-testid="status-type-maximum-dwell"
					@update:modelValue="(v) => update('maximumDwell', v)" />
				<p class="status-type-form__hint">
					{{
						t(
							'dossiq',
							'How long a case may sit here before it is reported as stuck. This is not the term of the case, and breaching it changes nothing about the term.',
						)
					}}
				</p>
			</div>
		</div>

		<div class="status-type-form__row">
			<NcCheckboxRadioSwitch
				:modelValue="form.isFinal"
				data-testid="status-type-is-final"
				@update:modelValue="(v) => update('isFinal', v)">
				{{ t('dossiq', 'Final status') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				:modelValue="form.hiddenInLists"
				data-testid="status-type-hidden"
				@update:modelValue="(v) => update('hiddenInLists', v)">
				{{ t('dossiq', 'Keep out of the case list') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="status-type-form__public">
			<h5 class="status-type-form__public-heading">
				{{ t('dossiq', 'What the applicant sees') }}
			</h5>
			<p class="status-type-form__hint">
				{{
					t(
						'dossiq',
						'The name above is written for you. Leave these empty and the applicant reads that name.',
					)
				}}
			</p>

			<div class="status-type-form__row">
				<NcTextField
					:modelValue="form.publicLabel"
					:label="t('dossiq', 'Public label')"
					:placeholder="form.name"
					class="status-type-form__field"
					data-testid="status-type-public-label"
					@update:modelValue="(v) => update('publicLabel', v)" />
			</div>

			<div class="status-type-form__row">
				<NcTextField
					:modelValue="form.publicDescription"
					:label="t('dossiq', 'Public description')"
					:placeholder="
						t('dossiq', 'What the applicant should know right now')
					"
					class="status-type-form__field"
					data-testid="status-type-public-description"
					@update:modelValue="(v) => update('publicDescription', v)" />
				<p class="status-type-form__hint">
					{{
						t(
							'dossiq',
							'Empty shows no description. The description above is never shown to the applicant.',
						)
					}}
				</p>
			</div>
		</div>

		<div class="status-type-form__checklist">
			<h5 class="status-type-form__checklist-heading">
				{{ t('dossiq', 'Checklist') }}
			</h5>
			<p class="status-type-form__hint">
				{{
					t(
						'dossiq',
						'Every item becomes a task on the case when it enters this status. A required item holds the case here until its task is done.',
					)
				}}
			</p>

			<div
				v-for="(item, index) in form.checklist"
				:key="index"
				class="status-type-form__checklist-row">
				<NcTextField
					:modelValue="item.title"
					:label="t('dossiq', 'What has to be done')"
					class="status-type-form__field"
					:data-testid="`status-type-checklist-title-${index}`"
					@update:modelValue="(v) => updateChecklist(index, 'title', v)" />
				<NcCheckboxRadioSwitch
					:modelValue="item.required"
					:data-testid="`status-type-checklist-required-${index}`"
					@update:modelValue="
						(v) => updateChecklist(index, 'required', v)
					">
					{{ t('dossiq', 'Required') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('dossiq', 'Remove checklist item {title}', {
							title: item.title || String(index + 1),
						})
					"
					@click="removeChecklistItem(index)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</div>

			<NcButton
				variant="tertiary"
				data-testid="status-type-checklist-add"
				@click="addChecklistItem">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dossiq', 'Add checklist item') }}
			</NcButton>
		</div>

		<div class="status-type-form__rules">
			<h5 class="status-type-form__checklist-heading">
				{{ t('dossiq', 'Fields in this status') }}
			</h5>
			<p class="status-type-form__hint">
				{{
					t(
						'dossiq',
						'What this status asks of the case. Publishing the case type hands these to the platform, which refuses a save that breaks one.',
					)
				}}
			</p>

			<div
				v-for="(rule, index) in fieldRules"
				:key="index"
				class="status-type-form__rule">
				<div class="status-type-form__row">
					<div
						class="status-type-form__field status-type-form__field--rule">
						<NcSelect
							:modelValue="selectedRule(rule)"
							:options="ruleOptions"
							:inputLabel="t('dossiq', 'This field')"
							:data-testid="`status-type-rule-kind-${index}`"
							@update:modelValue="
								(v) =>
									updateRule(index, 'rule', v ? v.id : 'required')
							" />
					</div>
					<div class="status-type-form__field">
						<NcSelect
							v-if="fieldOptions.length > 0"
							:modelValue="selectedField(rule)"
							:options="fieldOptions"
							:inputLabel="t('dossiq', 'Field')"
							:placeholder="t('dossiq', 'Pick a field')"
							:data-testid="`status-type-rule-field-${index}`"
							@update:modelValue="
								(v) => updateRule(index, 'field', v ? v.id : '')
							" />
						<NcTextField
							v-else
							:modelValue="rule.field"
							:label="t('dossiq', 'Field')"
							:data-testid="`status-type-rule-field-${index}`"
							@update:modelValue="
								(v) => updateRule(index, 'field', v)
							" />
					</div>
					<NcButton
						variant="tertiary"
						:aria-label="
							t('dossiq', 'Remove rule for {field}', {
								field: rule.field || String(index + 1),
							})
						"
						@click="removeRule(index)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>

				<div class="status-type-form__row">
					<div class="status-type-form__field">
						<NcTextField
							:modelValue="groupsText(rule)"
							:label="t('dossiq', 'Only for these groups')"
							:placeholder="t('dossiq', 'Everyone')"
							:data-testid="`status-type-rule-groups-${index}`"
							@update:modelValue="
								(v) => updateRule(index, 'groups', splitGroups(v))
							" />
						<p class="status-type-form__hint">
							{{
								t(
									'dossiq',
									'Separate group names with a comma. Leave it empty and the rule holds for everyone, administrators included.',
								)
							}}
						</p>
					</div>
					<div class="status-type-form__field">
						<NcTextField
							:modelValue="rule.message"
							:label="
								t('dossiq', 'What to say when the save is refused')
							"
							:placeholder="
								t(
									'dossiq',
									'Fill in the motivation before deciding.',
								)
							"
							:data-testid="`status-type-rule-message-${index}`"
							@update:modelValue="
								(v) => updateRule(index, 'message', v)
							" />
					</div>
				</div>

				<div class="status-type-form__row">
					<NcCheckboxRadioSwitch
						:modelValue="rule.condition !== null"
						:data-testid="`status-type-rule-conditional-${index}`"
						@update:modelValue="(v) => toggleCondition(index, v)">
						{{ t('dossiq', 'Only in some cases') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="rule.condition" class="status-type-form__row">
					<div class="status-type-form__field">
						<NcSelect
							:modelValue="selectedKind(rule)"
							:options="kindOptions"
							:inputLabel="t('dossiq', 'Holds when')"
							:data-testid="`status-type-rule-kind-of-${index}`"
							@update:modelValue="
								(v) =>
									updateCondition(
										index,
										'kind',
										v ? v.id : 'fieldPresent',
									)
							" />
					</div>
					<div class="status-type-form__field">
						<NcTextField
							:modelValue="rule.condition.field"
							:label="t('dossiq', 'Reading this field')"
							:data-testid="`status-type-rule-condition-field-${index}`"
							@update:modelValue="
								(v) => updateCondition(index, 'field', v)
							" />
					</div>
					<div
						v-if="rule.condition.kind === 'fieldEquals'"
						class="status-type-form__field">
						<NcTextField
							:modelValue="rule.condition.value"
							:label="t('dossiq', 'And finding this value')"
							:data-testid="`status-type-rule-condition-value-${index}`"
							@update:modelValue="
								(v) => updateCondition(index, 'value', v)
							" />
					</div>
				</div>
			</div>

			<NcButton
				variant="tertiary"
				data-testid="status-type-rule-add"
				@click="addRule">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dossiq', 'Add a field rule') }}
			</NcButton>
		</div>

		<span v-if="error" class="status-type-form__error" role="alert">{{
			error
		}}</span>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { STATUS_COLOURS, statusColourStyle } from '../../../utils/statusColour.js'
import {
	CONDITION_KINDS,
	conditionKindLabels,
	FIELD_RULES,
	fieldRule,
	fieldRuleLabels,
	normaliseGroups,
	ruleCondition,
} from '../../../utils/statusFieldRules.js'
import {
	checklistItem,
	STATUS_ROLES,
	STATUS_WAITING_ON,
} from '../../../utils/statusTypeForm.js'

export default {
	name: 'StatusTypeForm',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		DeleteIcon,
		PlusIcon,
	},

	props: {
		/** The form, as `statusTypeToForm` shapes it. */
		form: {
			type: Object,
			required: true,
		},

		/** The refusal to show, or an empty string. */
		error: {
			type: String,
			default: '',
		},

		/**
		 * The fields a rule may name, as `{id, label}`.
		 *
		 * Empty on an instance whose case type has no properties yet, and the
		 * form falls back to a text field rather than an empty picker: a rule
		 * can name a property of the case schema itself, which is not in this
		 * list and never was.
		 */
		fields: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update'],

	computed: {
		/**
		 * The colours, labelled in the reader's language.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		colourOptions() {
			const labels = {
				blue: t('dossiq', 'Blue'),
				'blue-light': t('dossiq', 'Light blue'),
				green: t('dossiq', 'Green'),
				'green-light': t('dossiq', 'Light green'),
				orange: t('dossiq', 'Orange'),
				'orange-light': t('dossiq', 'Light orange'),
				red: t('dossiq', 'Red'),
				'red-light': t('dossiq', 'Light red'),
				purple: t('dossiq', 'Purple'),
				'purple-light': t('dossiq', 'Light purple'),
				grey: t('dossiq', 'Grey'),
				'grey-light': t('dossiq', 'Light grey'),
			}

			return STATUS_COLOURS.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The roles, labelled in the reader's language.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		roleOptions() {
			const labels = {
				intake: t('dossiq', 'Intake'),
				'pending-info': t('dossiq', 'Waiting for information'),
				'in-progress': t('dossiq', 'In progress'),
				review: t('dossiq', 'Review'),
				closed: t('dossiq', 'Closed'),
				stranded: t('dossiq', 'Stranded'),
			}

			return STATUS_ROLES.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * Who the case may be declared to be waiting on, in the reader's
		 * language.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		waitingOnOptions() {
			const labels = {
				us: t('dossiq', 'Us'),
				applicant: t('dossiq', 'The applicant'),
				thirdParty: t('dossiq', 'Someone outside the organisation'),
			}

			return STATUS_WAITING_ON.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The waiting-on option the form holds, if it holds one.
		 *
		 * @return {object|null} The option.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		selectedWaitingOn() {
			return (
				this.waitingOnOptions.find((o) => o.id === this.form.waitingOn)
				|| null
			)
		},

		/**
		 * The colour option the form holds, if it holds one.
		 *
		 * @return {object|null} The option.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		selectedColour() {
			return this.colourOptions.find((o) => o.id === this.form.colour) || null
		},

		/**
		 * The role option the form holds, if it holds one.
		 *
		 * @return {object|null} The option.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		selectedRole() {
			return this.roleOptions.find((o) => o.id === this.form.role) || null
		},

		/**
		 * The rules this status declares, always a list.
		 *
		 * @return {Array<object>} The rules.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		fieldRules() {
			return Array.isArray(this.form.fieldRules) ? this.form.fieldRules : []
		},

		/**
		 * The three things a status can do to a field, in the reader's language.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		ruleOptions() {
			const labels = fieldRuleLabels()

			return FIELD_RULES.map((id) => ({ id, label: labels[id] || id }))
		},

		/**
		 * The questions a condition may ask, in the reader's language.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		kindOptions() {
			const labels = conditionKindLabels()

			return CONDITION_KINDS.map((id) => ({ id, label: labels[id] || id }))
		},

		/**
		 * The fields a rule may name.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		fieldOptions() {
			return this.fields
				.map((field) => ({
					id: String(field?.id ?? field?.name ?? field ?? ''),
					label: String(field?.label ?? field?.name ?? field ?? ''),
				}))
				.filter((option) => option.id !== '')
		},
	},

	methods: {
		/**
		 * The swatch a colour option is drawn with.
		 *
		 * @param {string} colour The colour name.
		 * @return {object} The style object.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		swatchStyle(colour) {
			return { backgroundColor: statusColourStyle(colour).backgroundColor }
		},

		/**
		 * Report one changed field.
		 *
		 * @param {string} field The field name.
		 * @param {string|number|boolean|Array} value The new value.
		 * @return {void}
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		update(field, value) {
			this.$emit('update', field, value)
		},

		/**
		 * Report one changed checklist item.
		 *
		 * @param {number} index Which item.
		 * @param {string} field `title` or `required`.
		 * @param {string|number|boolean|Array} value The new value.
		 * @return {void}
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		updateChecklist(index, field, value) {
			const checklist = this.form.checklist.map((item, i) =>
				i === index ? { ...item, [field]: value } : item,
			)
			this.update('checklist', checklist)
		},

		/**
		 * Add an empty checklist row.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		addChecklistItem() {
			this.update('checklist', [...this.form.checklist, checklistItem()])
		},

		/**
		 * Remove one checklist row.
		 *
		 * @param {number} index Which item.
		 * @return {void}
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		removeChecklistItem(index) {
			this.update(
				'checklist',
				this.form.checklist.filter((item, i) => i !== index),
			)
		},

		/**
		 * The option standing for a rule's kind.
		 *
		 * @param {object} rule The rule row.
		 * @return {object|null} The option.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		selectedRule(rule) {
			return this.ruleOptions.find((o) => o.id === rule.rule) || null
		},

		/**
		 * The option standing for the field a rule names.
		 *
		 * @param {object} rule The rule row.
		 * @return {object|null} The option.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		selectedField(rule) {
			return this.fieldOptions.find((o) => o.id === rule.field) || null
		},

		/**
		 * The option standing for a condition's kind.
		 *
		 * @param {object} rule The rule row.
		 * @return {object|null} The option.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		selectedKind(rule) {
			return (
				this.kindOptions.find((o) => o.id === rule.condition?.kind) || null
			)
		},

		/**
		 * The groups a rule names, as one line of text.
		 *
		 * @param {object} rule The rule row.
		 * @return {string} The names, comma separated.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		groupsText(rule) {
			return normaliseGroups(rule.groups).join(', ')
		},

		/**
		 * One line of text as a list of group names.
		 *
		 * @param {string} text What the author typed.
		 * @return {Array<string>} The names.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		splitGroups(text) {
			return normaliseGroups(String(text ?? '').split(','))
		},

		/**
		 * Report one changed rule.
		 *
		 * @param {number} index Which rule.
		 * @param {string} field The key on the rule.
		 * @param {string|Array} value The new value.
		 * @return {void}
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		updateRule(index, field, value) {
			this.update(
				'fieldRules',
				this.fieldRules.map((rule, i) =>
					i === index ? { ...rule, [field]: value } : rule,
				),
			)
		},

		/**
		 * Give a rule a condition, or take it away.
		 *
		 * Taking it away sets null rather than an empty object, because an empty
		 * object is a condition that reads no field, and a rule carrying one
		 * would be published as unconditional anyway. Null is the state the
		 * saved row and the switch agree on.
		 *
		 * @param {number} index Which rule.
		 * @param {boolean} conditional Whether the rule holds only sometimes.
		 * @return {void}
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		toggleCondition(index, conditional) {
			this.updateRule(index, 'condition', conditional ? ruleCondition() : null)
		},

		/**
		 * Report one changed part of a rule's condition.
		 *
		 * @param {number} index Which rule.
		 * @param {string} field The key on the condition.
		 * @param {string} value The new value.
		 * @return {void}
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		updateCondition(index, field, value) {
			const rule = this.fieldRules[index]
			const condition = {
				...(rule?.condition || ruleCondition()),
				[field]: value,
			}
			this.updateRule(index, 'condition', condition)
		},

		/**
		 * Add an empty rule row.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		addRule() {
			this.update('fieldRules', [...this.fieldRules, fieldRule()])
		},

		/**
		 * Remove one rule row.
		 *
		 * @param {number} index Which rule.
		 * @return {void}
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		removeRule(index) {
			this.update(
				'fieldRules',
				this.fieldRules.filter((rule, i) => i !== index),
			)
		},
	},
}
</script>

<style scoped>
.status-type-form__row {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
	align-items: flex-start;
}

.status-type-form__field {
	flex: 1;
	min-width: 0;
}

.status-type-form__field--small {
	max-width: 80px;
	flex: 0 0 80px;
}

.status-type-form__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.status-type-form__swatch-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-type-form__swatch {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 3px;
	border: 1px solid var(--color-border);
}

.status-type-form__public {
	border-top: 1px solid var(--color-border);
	margin-top: 12px;
	padding-top: 12px;
}

.status-type-form__public-heading {
	margin: 0;
}

.status-type-form__checklist {
	border-top: 1px solid var(--color-border);
	margin-top: 12px;
	padding-top: 12px;
}

.status-type-form__checklist-heading {
	margin: 0;
}

.status-type-form__checklist-row {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 8px;
}

.status-type-form__rules {
	border-top: 1px solid var(--color-border);
	margin-top: 12px;
	padding-top: 12px;
}

.status-type-form__rule {
	border-inline-start: 3px solid var(--color-border);
	padding-inline-start: 12px;
	margin-bottom: 12px;
}

.status-type-form__field--rule {
	max-width: 200px;
	flex: 0 0 200px;
}

.status-type-form__error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 8px;
}
</style>

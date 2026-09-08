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

		<span v-if="error" class="status-type-form__error" role="alert">{{
			error
		}}</span>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { STATUS_COLOURS, statusColourStyle } from '../../../utils/statusColour.js'
import { checklistItem, STATUS_ROLES } from '../../../utils/statusTypeForm.js'

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

.status-type-form__error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 8px;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal :name="title" size="normal" @close="$emit('close')">
		<div class="archief-rule-editor">
			<h2>{{ title }}</h2>

			<div class="form-group">
				<label class="required" for="ar-zaaktype">{{ t('procest', 'Zaaktype key') }}</label>
				<NcTextField
					id="ar-zaaktype"
					:value="form.zaaktypeKey"
					:error="!!errors.zaaktypeKey"
					:helper-text="errors.zaaktypeKey"
					@update:value="v => form.zaaktypeKey = v" />
			</div>

			<div class="form-group">
				<label class="required" for="ar-mode">{{ t('procest', 'Bewaarmodus') }}</label>
				<NcSelect
					id="ar-mode"
					:value="selectedMode"
					:options="modeOptions"
					:input-label="t('procest', 'Bewaarmodus')"
					@input="onModeChange" />
			</div>

			<div v-if="form.mode === 'years'" class="form-group">
				<label class="required" for="ar-years">{{ t('procest', 'Bewaartermijn (jaren)') }}</label>
				<NcTextField
					id="ar-years"
					type="number"
					:value="String(form.bewaartermijnJaren)"
					:error="!!errors.bewaartermijnJaren"
					:helper-text="errors.bewaartermijnJaren"
					@update:value="v => form.bewaartermijnJaren = Number(v) || 0" />
			</div>

			<div class="form-group">
				<label for="ar-trigger">{{ t('procest', 'Triggergebeurtenis') }}</label>
				<NcSelect
					id="ar-trigger"
					:value="selectedTrigger"
					:options="triggerOptions"
					:input-label="t('procest', 'Triggergebeurtenis')"
					@input="v => form.triggerGebeurtenis = v ? v.id : ''" />
			</div>

			<div class="form-group form-group--inline">
				<NcCheckboxRadioSwitch
					:checked="form.vernietiging"
					@update:checked="v => form.vernietiging = v">
					{{ t('procest', 'Vernietiging na bewaartermijn (else: permanent archive)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="archief-rule-editor__actions">
				<NcButton @click="$emit('close')">{{ t('procest', 'Cancel') }}</NcButton>
				<NcButton type="primary" @click="save">{{ t('procest', 'Save rule') }}</NcButton>
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
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ArchiefRuleEditor',
	components: { NcModal, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch },
	props: {
		rule: { type: Object, default: null },
	},
	emits: ['save', 'close'],
	data() {
		const yrs = this.rule?.bewaartermijnJaren || 7
		return {
			errors: {},
			form: {
				zaaktypeKey: this.rule?.zaaktypeKey || '',
				mode: yrs >= 9999 ? 'permanent' : 'years',
				bewaartermijnJaren: yrs >= 9999 ? 7 : yrs,
				triggerGebeurtenis: this.rule?.triggerGebeurtenis || 'sluitingsdatum',
				vernietiging: this.rule?.vernietiging !== false,
			},
		}
	},
	computed: {
		title() {
			return this.rule
				? t('procest', 'Edit retention rule')
				: t('procest', 'New retention rule')
		},
		modeOptions() {
			return [
				{ id: 'years', label: t('procest', 'Years') },
				{ id: 'permanent', label: t('procest', 'Permanent (no destruction)') },
			]
		},
		selectedMode() {
			return this.modeOptions.find(o => o.id === this.form.mode) || this.modeOptions[0]
		},
		triggerOptions() {
			return [
				{ id: 'sluitingsdatum', label: t('procest', 'Sluitingsdatum') },
				{ id: 'beschikkingsdatum', label: t('procest', 'Beschikkingsdatum') },
				{ id: 'eindbesluit', label: t('procest', 'Eindbesluit') },
			]
		},
		selectedTrigger() {
			return this.triggerOptions.find(o => o.id === this.form.triggerGebeurtenis) || this.triggerOptions[0]
		},
	},
	methods: {
		t,
		onModeChange(opt) {
			this.form.mode = opt ? opt.id : 'years'
			if (this.form.mode === 'permanent') {
				this.form.vernietiging = false
			}
		},
		validate() {
			const errs = {}
			if (!this.form.zaaktypeKey) errs.zaaktypeKey = t('procest', 'Zaaktype key is required')
			if (this.form.mode === 'years' && (!this.form.bewaartermijnJaren || this.form.bewaartermijnJaren < 1)) {
				errs.bewaartermijnJaren = t('procest', 'Bewaartermijn must be at least 1 year')
			}
			this.errors = errs
			return Object.keys(errs).length === 0
		},
		save() {
			if (!this.validate()) return
			const payload = {
				zaaktypeKey: this.form.zaaktypeKey,
				bewaartermijnJaren: this.form.mode === 'permanent' ? 9999 : this.form.bewaartermijnJaren,
				triggerGebeurtenis: this.form.triggerGebeurtenis,
				vernietiging: this.form.mode !== 'permanent' && this.form.vernietiging,
			}
			this.$emit('save', payload)
		},
	},
}
</script>

<style scoped>
.archief-rule-editor {
	padding: 24px;
	max-width: 520px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group--inline {
	margin-bottom: 8px;
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

.archief-rule-editor__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>

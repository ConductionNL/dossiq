<template>
	<div class="woo-intake-form">
		<h4 class="woo-intake-form__title">
			{{ t('procest', 'WOO Request Intake') }}
		</h4>

		<div class="form-row">
			<div class="form-group">
				<label>{{ t('procest', 'Requester name') }} *</label>
				<NcTextField
					:model-value="form.verzoekerNaam"
					:disabled="isReadOnly"
					:error="!!errors.verzoekerNaam"
					@update:model-value="v => update('verzoekerNaam', v)" />
				<p v-if="errors.verzoekerNaam" class="form-error">
					{{ errors.verzoekerNaam }}
				</p>
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Requester email') }} *</label>
				<NcTextField
					:model-value="form.verzoekerEmail"
					:disabled="isReadOnly"
					:error="!!errors.verzoekerEmail"
					type="email"
					@update:model-value="v => update('verzoekerEmail', v)" />
				<p v-if="errors.verzoekerEmail" class="form-error">
					{{ errors.verzoekerEmail }}
				</p>
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label>{{ t('procest', 'Requester type') }}</label>
				<NcSelect
					:model-value="form.verzoekerType"
					:options="requesterTypes"
					:aria-label-combobox="t('procest', 'Requester type')"
					:disabled="isReadOnly"
					@update:model-value="v => update('verzoekerType', v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Desired format') }}</label>
				<NcSelect
					:model-value="form.gewensteVorm"
					:options="formatOptions"
					:aria-label-combobox="t('procest', 'Desired format')"
					:disabled="isReadOnly"
					@update:model-value="v => update('gewensteVorm', v)" />
			</div>
		</div>

		<div class="form-group">
			<label>{{ t('procest', 'Subject') }} *</label>
			<NcTextField
				:model-value="form.onderwerp"
				:disabled="isReadOnly"
				:error="!!errors.onderwerp"
				:placeholder="t('procest', 'Topic of the information request')"
				@update:model-value="v => update('onderwerp', v)" />
			<p v-if="errors.onderwerp" class="form-error">
				{{ errors.onderwerp }}
			</p>
		</div>

		<div class="form-group">
			<label>{{ t('procest', 'Administrative matter') }}</label>
			<NcTextField
				:model-value="form.bestuurlijkeAangelegenheid"
				:disabled="isReadOnly"
				:placeholder="t('procest', 'Related administrative matter')"
				@update:model-value="v => update('bestuurlijkeAangelegenheid', v)" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<label>{{ t('procest', 'Period from') }}</label>
				<NcTextField
					:model-value="form.periodeVan"
					:disabled="isReadOnly"
					type="date"
					@update:model-value="v => update('periodeVan', v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Period to') }}</label>
				<NcTextField
					:model-value="form.periodeTot"
					:disabled="isReadOnly"
					type="date"
					@update:model-value="v => update('periodeTot', v)" />
			</div>
		</div>

		<div class="form-group">
			<label>{{ t('procest', 'Receipt date') }} *</label>
			<NcTextField
				:model-value="form.ontvangstdatum"
				:disabled="isReadOnly"
				:error="!!errors.ontvangstdatum"
				type="date"
				@update:model-value="v => update('ontvangstdatum', v)" />
			<p v-if="errors.ontvangstdatum" class="form-error">
				{{ errors.ontvangstdatum }}
			</p>
		</div>

		<!-- Deadline info -->
		<div v-if="form.ontvangstdatum" class="woo-intake-form__deadline-info">
			<span class="woo-intake-form__deadline-label">{{ t('procest', 'Calculated deadline:') }}</span>
			<span class="woo-intake-form__deadline-value">{{ calculatedDeadline }}</span>
			<span class="woo-intake-form__deadline-note">
				{{ t('procest', '(4 weeks from receipt, extendable by 2 weeks)') }}
			</span>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcSelect } from '@nextcloud/vue'

export default {
	name: 'WooIntakeForm',
	components: {
		NcTextField,
		NcSelect,
	},
	props: {
		form: {
			type: Object,
			default: () => ({
				verzoekerNaam: '',
				verzoekerEmail: '',
				verzoekerType: 'burger',
				onderwerp: '',
				periodeVan: '',
				periodeTot: '',
				bestuurlijkeAangelegenheid: '',
				ontvangstdatum: '',
				gewensteVorm: 'digitaal',
			}),
		},
		errors: {
			type: Object,
			default: () => ({}),
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			requesterTypes: ['burger', 'journalist', 'organisatie'],
			formatOptions: ['digitaal', 'papier', 'inzage'],
		}
	},
	computed: {
		/** @spec openspec/specs/woo-case-type/spec.md */
		calculatedDeadline() {
			if (!this.form.ontvangstdatum) {
				return '---'
			}
			const receipt = new Date(this.form.ontvangstdatum)
			if (isNaN(receipt.getTime())) {
				return '---'
			}
			const deadline = new Date(receipt)
			deadline.setDate(deadline.getDate() + 28)
			return deadline.toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
	methods: {
		/**
		 * @param field
		 * @param value
		 * @spec openspec/specs/woo-case-type/spec.md
		 */
		update(field, value) {
			this.$emit('update', { field, value })
		},
	},
}
</script>

<style scoped>
.woo-intake-form__title {
	margin-bottom: 16px;
}

.woo-intake-form__deadline-info {
	margin-top: 16px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.woo-intake-form__deadline-label {
	font-weight: 600;
}

.woo-intake-form__deadline-value {
	color: var(--color-primary);
	font-weight: 600;
}

.woo-intake-form__deadline-note {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row > .form-group {
	flex: 1;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
	font-size: 0.875rem;
}

.form-error {
	color: var(--color-error);
	font-size: 0.8125rem;
	margin-top: 2px;
}
</style>

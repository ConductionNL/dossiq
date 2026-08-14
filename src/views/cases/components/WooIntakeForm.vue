<template>
	<div class="woo-intake-form">
		<h4 class="woo-intake-form__title">
			{{ t('procest', 'WOO Request Intake') }}
		</h4>

		<div class="form-row">
			<div class="form-group">
				<label for="woo-intake-requester-name"
					>{{ t('procest', 'Requester name') }} *</label
				>
				<NcTextField
					id="woo-intake-requester-name"
					:modelValue="form.verzoekerNaam"
					:disabled="isReadOnly"
					:error="!!errors.verzoekerNaam"
					@update:modelValue="(v) => update('verzoekerNaam', v)" />
				<p v-if="errors.verzoekerNaam" class="form-error">
					{{ errors.verzoekerNaam }}
				</p>
			</div>
			<div class="form-group">
				<label for="woo-intake-requester-email"
					>{{ t('procest', 'Requester email') }} *</label
				>
				<NcTextField
					id="woo-intake-requester-email"
					:modelValue="form.verzoekerEmail"
					:disabled="isReadOnly"
					:error="!!errors.verzoekerEmail"
					type="email"
					@update:modelValue="(v) => update('verzoekerEmail', v)" />
				<p v-if="errors.verzoekerEmail" class="form-error">
					{{ errors.verzoekerEmail }}
				</p>
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label>{{ t('procest', 'Requester type') }}</label>
				<NcSelect
					:modelValue="form.verzoekerType"
					:options="requesterTypes"
					:aria-label-combobox="t('procest', 'Requester type')"
					:disabled="isReadOnly"
					@update:modelValue="(v) => update('verzoekerType', v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Desired format') }}</label>
				<NcSelect
					:modelValue="form.gewensteVorm"
					:options="formatOptions"
					:aria-label-combobox="t('procest', 'Desired format')"
					:disabled="isReadOnly"
					@update:modelValue="(v) => update('gewensteVorm', v)" />
			</div>
		</div>

		<div class="form-group">
			<label for="woo-intake-subject">{{ t('procest', 'Subject') }} *</label>
			<NcTextField
				id="woo-intake-subject"
				:modelValue="form.onderwerp"
				:disabled="isReadOnly"
				:error="!!errors.onderwerp"
				:placeholder="t('procest', 'Topic of the information request')"
				@update:modelValue="(v) => update('onderwerp', v)" />
			<p v-if="errors.onderwerp" class="form-error">
				{{ errors.onderwerp }}
			</p>
		</div>

		<div class="form-group">
			<label for="woo-intake-administrative-matter">{{
				t('procest', 'Administrative matter')
			}}</label>
			<NcTextField
				id="woo-intake-administrative-matter"
				:modelValue="form.bestuurlijkeAangelegenheid"
				:disabled="isReadOnly"
				:placeholder="t('procest', 'Related administrative matter')"
				@update:modelValue="
					(v) => update('bestuurlijkeAangelegenheid', v)
				" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="woo-intake-period-from">{{
					t('procest', 'Period from')
				}}</label>
				<NcTextField
					id="woo-intake-period-from"
					:modelValue="form.periodeVan"
					:disabled="isReadOnly"
					type="date"
					@update:modelValue="(v) => update('periodeVan', v)" />
			</div>
			<div class="form-group">
				<label for="woo-intake-period-to">{{
					t('procest', 'Period to')
				}}</label>
				<NcTextField
					id="woo-intake-period-to"
					:modelValue="form.periodeTot"
					:disabled="isReadOnly"
					type="date"
					@update:modelValue="(v) => update('periodeTot', v)" />
			</div>
		</div>

		<div class="form-group">
			<label for="woo-intake-receipt-date"
				>{{ t('procest', 'Receipt date') }} *</label
			>
			<NcTextField
				id="woo-intake-receipt-date"
				:modelValue="form.receipt_date"
				:disabled="isReadOnly"
				:error="!!errors.receipt_date"
				type="date"
				@update:modelValue="(v) => update('receipt_date', v)" />
			<p v-if="errors.receipt_date" class="form-error">
				{{ errors.receipt_date }}
			</p>
		</div>

		<!-- Deadline info -->
		<div v-if="form.receipt_date" class="woo-intake-form__deadline-info">
			<span class="woo-intake-form__deadline-label">{{
				t('procest', 'Calculated deadline:')
			}}</span>
			<span class="woo-intake-form__deadline-value">{{
				calculatedDeadline
			}}</span>
			<span class="woo-intake-form__deadline-note">
				{{ t('procest', '(4 weeks from receipt, extendable by 2 weeks)') }}
			</span>
		</div>
	</div>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'

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
				receipt_date: '',
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
			requesterTypes: ['burger', 'journalist', 'organisation'],
			formatOptions: ['digitaal', 'papier', 'inzage'],
		}
	},

	computed: {
		/** @spec openspec/specs/woo-case-type/spec.md */
		calculatedDeadline() {
			if (!this.form.receipt_date) {
				return '---'
			}
			const receipt = new Date(this.form.receipt_date)
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

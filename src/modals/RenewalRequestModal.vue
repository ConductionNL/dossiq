<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Contract Renewal Request modal — leverancier-zaakportaal chain member 10.
  -
  - Modal-isolation file (ADR-004): the entire NcDialog markup + behaviour
  - lives here, so the ContractList/ContractDetail parent stays a pure
  - read-only surface. Emits `confirm` with the renewal-request payload when
  - the supplier submits, `close` on cancel. The actual write call is the
  - caller's responsibility (chain member 09's `requestRenewal()` endpoint —
  - currently deferred; this modal renders the form, validates, and emits).
  -
  - @spec openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
  -->
<template>
	<NcDialog
		v-model:open="open"
		:name="t('procest', 'Extension request')"
		size="small"
		data-testid="leverancier-renewal-modal"
		@update:open="onOpenChange">
		<form class="lz-renewal-form" @submit.prevent="onSubmit">
			<p class="lz-renewal-intro">
				{{
					t(
						'procest',
						'Request an extension of this contract. The municipality will contact you within 14 working days.',
					)
				}}
			</p>

			<div class="lz-form-group">
				<label class="required" for="lz-renewal-duration">
					{{ t('procest', 'Desired extension period (months)') }}
				</label>
				<input
					id="lz-renewal-duration"
					v-model.number="form.durationMonths"
					type="number"
					min="1"
					max="60"
					required
					data-testid="leverancier-renewal-duration"
					class="lz-input" />
				<p v-if="errors.durationMonths" class="lz-error" role="alert">
					{{ errors.durationMonths }}
				</p>
			</div>

			<div class="lz-form-group">
				<label for="lz-renewal-reason">
					{{ t('procest', 'Motivation') }}
				</label>
				<textarea
					id="lz-renewal-reason"
					v-model="form.reason"
					rows="4"
					maxlength="2000"
					data-testid="leverancier-renewal-reason"
					class="lz-input lz-textarea"
					:placeholder="t('procest', 'Optional — note on the request')" />
			</div>

			<div class="lz-form-actions">
				<button
					type="button"
					class="lz-button"
					data-testid="leverancier-renewal-cancel"
					@click="close">
					{{ t('procest', 'Annuleren') }}
				</button>
				<button
					type="submit"
					class="lz-button lz-button--primary"
					data-testid="leverancier-renewal-submit"
					:disabled="submitting">
					{{
						submitting
							? t('procest', 'Sending…')
							: t('procest', 'Submit request')
					}}
				</button>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'

export default {
	name: 'RenewalRequestModal',
	components: { NcDialog },
	props: {
		value: {
			type: Boolean,
			default: false,
		},

		contract: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['input', 'close', 'confirm'],
	data() {
		return {
			form: { durationMonths: 12, reason: '' },
			errors: { durationMonths: '' },
			submitting: false,
		}
	},

	computed: {
		open: {
			get() {
				return this.value
			},

			set(v) {
				this.$emit('input', v)
			},
		},
	},

	methods: {
		validate() {
			this.errors = { durationMonths: '' }
			if (
				!this.form.durationMonths
				|| this.form.durationMonths < 1
				|| this.form.durationMonths > 60
			) {
				this.errors.durationMonths = this.t(
					'procest',
					'Period must be between 1 and 60 months.',
				)
				return false
			}
			return true
		},

		onOpenChange(v) {
			if (!v) {
				this.close()
			}
		},

		close() {
			this.$emit('input', false)
			this.$emit('close')
		},

		async onSubmit() {
			if (!this.validate()) {
				return
			}
			this.submitting = true
			try {
				this.$emit('confirm', {
					contractId: this.contract.id,
					durationMonths: this.form.durationMonths,
					reason: this.form.reason,
				})
				this.close()
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.lz-renewal-form {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.lz-renewal-intro {
	margin: 0 0 8px 0;
	color: var(--color-text-maxcontrast, #555);
	font-size: 14px;
}

.lz-form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.lz-form-group label {
	font-weight: 600;
	font-size: 14px;
}

.lz-form-group label.required::after {
	content: ' *';
	color: var(--color-error, #c00);
}

.lz-input {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: 4px;
	font-family: inherit;
}

.lz-textarea {
	resize: vertical;
	min-height: 80px;
}

.lz-error {
	margin: 4px 0 0 0;
	color: var(--color-error, #c00);
	font-size: 12px;
}

.lz-form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.lz-button {
	padding: 8px 16px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: 4px;
	background: var(--color-main-background, #fff);
	cursor: pointer;
}

.lz-button--primary {
	background: var(--color-primary-element, #0082c9);
	color: #fff;
	border-color: var(--color-primary-element, #0082c9);
}

.lz-button--primary:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>

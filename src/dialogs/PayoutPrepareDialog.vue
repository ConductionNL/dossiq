<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Prepare the payout of a definitive penalty, the row action on the notices
	of default (board DqTermijnen). The handler gives the account the money goes
	to; the server raises the payout with the finance app through the payout
	route. A payout the finance app took reads as a payment signal sent; without
	a finance app it stays prepared here.

	@spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Prepare payout')"
		data-testid="payout-prepare-dialog"
		@closing="$emit('close')">
		<div v-if="raised === null" class="payout-prepare">
			<p v-if="notice.caseLabel" class="payout-prepare__case">
				{{ notice.caseLabel }}
			</p>
			<p v-if="amountText" data-testid="payout-prepare-amount">
				{{ amountText }}
			</p>

			<NcTextField
				v-model="accountHolderName"
				data-testid="payout-prepare-holder"
				:label="t('dossiq', 'Account holder')" />

			<NcTextField
				v-model="iban"
				data-testid="payout-prepare-iban"
				:label="t('dossiq', 'IBAN')" />

			<p
				v-if="error"
				class="payout-prepare__error"
				role="alert"
				data-testid="payout-prepare-error">
				{{ error }}
			</p>
		</div>

		<p
			v-else
			class="payout-prepare__saved"
			role="status"
			data-testid="payout-prepare-saved">
			{{ raisedSentence }}
		</p>

		<template #actions>
			<template v-if="raised === null">
				<NcButton
					data-testid="payout-prepare-cancel"
					@click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="payout-prepare-confirm"
					:disabled="!canSave"
					@click="save">
					{{ t('dossiq', 'Prepare payout') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="primary"
				data-testid="payout-prepare-close"
				@click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { noticeRefusal, preparePayout } from '../services/noticeOfDefaultApi.js'

export default {
	name: 'PayoutPrepareDialog',

	components: {
		CnDialog,
		NcButton,
		NcTextField,
	},

	props: {
		/**
		 * The notice row: `accruingAmountId`, `caseLabel`, `penaltyEuros`.
		 */
		notice: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			accountHolderName: '',
			iban: '',
			busy: false,
			error: '',
			raised: null,
		}
	},

	computed: {
		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02 */
		amountText() {
			const euros = Number(this.notice?.penaltyEuros)
			if (!Number.isFinite(euros) || euros <= 0) {
				return ''
			}
			const amount = euros.toLocaleString(undefined, {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})
			return t('dossiq', 'Definitive penalty: € {amount}', { amount })
		},

		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02 */
		canSave() {
			return (
				this.busy === false
				&& String(this.notice?.accruingAmountId || '') !== ''
				&& this.accountHolderName.trim() !== ''
				&& this.iban.trim() !== ''
			)
		},

		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02 */
		raisedSentence() {
			if (String(this.raised?.payableId || '') !== '') {
				return t(
					'dossiq',
					'Payment signal sent. The financial administration pays it out.',
				)
			}

			return t('dossiq', 'The payout is prepared. No finance app took it yet.')
		},
	},

	methods: {
		t,

		/**
		 * Raise the payout and refresh the notices.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
		 */
		async save() {
			if (!this.canSave) {
				return
			}

			this.busy = true
			this.error = ''
			try {
				this.raised = await preparePayout(
					String(this.notice.accruingAmountId),
					{
						accountHolderName: this.accountHolderName.trim(),
						iban: this.iban.trim(),
						receiptDate: String(this.notice.receiptDate || ''),
					},
				)
				emit('cn:page:refresh')
			} catch (error) {
				this.error = noticeRefusal(
					error,
					t('dossiq', 'The payout could not be prepared.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.payout-prepare {
	display: flex;
	flex-direction: column;
	gap: 12px;

	p {
		margin: 0;
	}

	&__case {
		font-weight: bold;
	}

	&__error {
		color: var(--color-error-text, var(--color-error));
	}

	&__saved {
		margin: 0;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-success);
		color: var(--color-success-text, var(--color-primary-element-text));
	}
}
</style>

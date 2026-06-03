<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="leges-panel">
		<div class="leges-panel__header">
			<h3 class="leges-panel__title">
				{{ t('procest', 'Leges') }}
			</h3>
			<NcButton type="secondary" :disabled="loading" @click="recalculate">
				{{ t('procest', 'Handmatig herberekenen') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<CnEmptyState
			v-else-if="!berekening"
			:name="t('procest', 'Geen legesberekening')"
			:description="t('procest', 'Voor deze zaak is nog geen leges berekend.')" />

		<div v-else class="leges-panel__body">
			<div class="leges-panel__amounts">
				<div class="leges-panel__amount-total">
					<span class="leges-panel__label">{{ t('procest', 'Totaal incl. BTW') }}</span>
					<strong>{{ formatEuro(berekening.bedragInclBtw) }}</strong>
				</div>
				<div class="leges-panel__amount-sub">
					<span>{{ t('procest', 'Excl. BTW') }}: {{ formatEuro(berekening.bedragExclBtw) }}</span>
					<span>{{ t('procest', 'BTW') }}: {{ formatEuro(berekening.btwBedrag) }}</span>
				</div>
				<CnStatusBadge :status="statusLabel(berekening.status)" :type="statusType(berekening.status)" />
			</div>

			<ul v-if="kortingen.length > 0" class="leges-panel__kortingen">
				<li v-for="(korting, idx) in kortingen" :key="idx">
					{{ korting.naam }}: {{ formatEuro(korting.bedrag) }}
					<span v-if="korting.grondslag" class="leges-panel__grondslag">({{ korting.grondslag }})</span>
				</li>
			</ul>

			<NcButton type="tertiary" @click="showAudit = !showAudit">
				{{ showAudit ? t('procest', 'Verberg toelichting') : t('procest', 'Toon toelichting') }}
			</NcButton>
			<p v-if="showAudit" class="leges-panel__audit">
				{{ berekening.berekeningsToelichting }}
			</p>

			<p v-if="berekening.factuurId" class="leges-panel__factuur">
				{{ t('procest', 'Factuur') }}: {{ berekening.factuurId }}
			</p>

			<div class="leges-panel__actions">
				<NcButton
					v-if="canRefund"
					type="warning"
					@click="showRefund = true">
					{{ t('procest', 'Restitutie aanvragen') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<LegesRefundDialog
			:open="showRefund"
			:case-id="caseId"
			:original-amount="berekening ? berekening.bedragInclBtw : 0"
			@close="showRefund = false"
			@refunded="onRefunded" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { CnEmptyState, CnStatusBadge } from '@conduction/nextcloud-vue'
import { calculateLeges, getLegesForCase } from '../../../services/legesApi.js'
import LegesRefundDialog from '../../../dialogs/LegesRefundDialog.vue'

const STATUS_LABELS = {
	berekend: 'Berekend',
	pending_minima_check: 'Wacht op inkomenstoets',
	gefactureerd: 'Gefactureerd',
	betaald: 'Betaald',
	gerestitueerd: 'Gerestitueerd',
	kwijtgescholden: 'Kwijtgescholden',
}

export default {
	name: 'LegesBerekeningPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CnEmptyState,
		CnStatusBadge,
		LegesRefundDialog,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			berekening: null,
			loading: false,
			error: '',
			showAudit: false,
			showRefund: false,
		}
	},
	computed: {
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-008 */
		kortingen() {
			return (this.berekening && Array.isArray(this.berekening.appliedKortingen))
				? this.berekening.appliedKortingen
				: []
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		canRefund() {
			return this.berekening
				&& ['gefactureerd', 'betaald'].includes(this.berekening.status)
		},
	},
	watch: {
		caseId: {
			immediate: true,
			/**
			 * @param value
			 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
			 */
			handler(value) {
				if (value) {
					this.fetch()
				}
			},
		},
	},
	methods: {
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-002 */
		async fetch() {
			this.loading = true
			this.error = ''
			try {
				this.berekening = await getLegesForCase(this.caseId)
			} catch (err) {
				this.error = this.t('procest', 'Kon legesberekening niet laden')
				console.error('Procest leges fetch failed', err)
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-002 */
		async recalculate() {
			this.loading = true
			this.error = ''
			try {
				this.berekening = await calculateLeges(this.caseId)
			} catch (err) {
				this.error = err?.response?.data?.error || this.t('procest', 'Herberekenen mislukt')
				console.error('Procest leges recalculate failed', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param result
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
		 */
		onRefunded(result) {
			this.showRefund = false
			this.fetch()
		},
		/**
		 * @param cents
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
		 */
		formatEuro(cents) {
			return '€' + ((cents || 0) / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		},
		/**
		 * @param status
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
		 */
		statusLabel(status) {
			return this.t('procest', STATUS_LABELS[status] || status)
		},
		/**
		 * @param status
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
		 */
		statusType(status) {
			if (status === 'betaald') return 'success'
			if (status === 'gerestitueerd' || status === 'kwijtgescholden') return 'warning'
			if (status === 'pending_minima_check') return 'warning'
			return 'info'
		},
	},
}
</script>

<style scoped>
.leges-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.leges-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.leges-panel__title {
	margin: 0;
}

.leges-panel__amounts {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.leges-panel__amount-total strong {
	font-size: 1.4em;
	margin-left: 8px;
}

.leges-panel__amount-sub {
	display: flex;
	gap: 16px;
	color: var(--color-text-maxcontrast);
}

.leges-panel__kortingen {
	margin: 0;
	padding-left: 18px;
}

.leges-panel__grondslag {
	color: var(--color-text-maxcontrast);
}

.leges-panel__audit {
	background: var(--color-background-hover);
	padding: 8px 12px;
	border-radius: var(--border-radius);
	white-space: pre-wrap;
}
</style>

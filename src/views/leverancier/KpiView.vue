<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Supplier KPI summary view.
  -
  - Binds to GET /apps/procest/api/leverancier-portaal/kpi.
  - The aggregated KPI map comes from `SupplierKpiAggregationService::aggregateKpis()`
  - (chain member 13).
  -
  - @spec openspec/changes/leverancier-zaakportaal-14-kpi-frontend/tasks.md
  -->
<template>
	<div class="lz-kpi" data-testid="leverancier-kpi-view">
		<header class="lz-toolbar">
			<h1>{{ t('procest', 'KPI overzicht') }}</h1>
		</header>

		<div v-if="loading" data-testid="lz-loading" class="lz-state">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="error"
			data-testid="lz-error"
			class="lz-state lz-state--error"
			role="alert">
			{{ error }}
		</div>

		<div v-else-if="kpi" class="lz-kpi-grid">
			<article class="lz-kpi-tile" data-testid="leverancier-kpi-payment-days">
				<h2>{{ t('procest', 'Gem. betaaldagen') }}</h2>
				<p class="lz-kpi-value">
					{{ formatDays(kpi.paymentDays) }}
				</p>
			</article>
			<article class="lz-kpi-tile" data-testid="leverancier-kpi-ontime">
				<h2>{{ t('procest', 'Op tijd betaald') }}</h2>
				<p class="lz-kpi-value">
					{{ formatPct(kpi.onTimePercentage) }}
				</p>
			</article>
			<article class="lz-kpi-tile" data-testid="leverancier-kpi-dispute">
				<h2>{{ t('procest', 'Betwistratio') }}</h2>
				<p class="lz-kpi-value">
					{{ formatPct(kpi.disputeRate) }}
				</p>
			</article>
			<article class="lz-kpi-tile" data-testid="leverancier-kpi-compliance">
				<h2>{{ t('procest', 'Compliance score') }}</h2>
				<p class="lz-kpi-value">
					{{ formatScore(kpi.complianceScore) }}
				</p>
			</article>
		</div>

		<p v-else class="lz-empty" data-testid="lz-empty">
			{{ t('procest', 'Onvoldoende data voor KPI.') }}
		</p>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { getKpi } from '../../services/leverancierApi.js'

export default {
	name: 'KpiView',
	components: { NcLoadingIcon },
	data() {
		return { kpi: null, loading: false, error: null }
	},
	computed: {
		supplierRef() {
			return (this.$route.query && this.$route.query.supplierRef) || ''
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			if (!this.supplierRef) { this.kpi = null; return }
			this.loading = true
			this.error = null
			try {
				this.kpi = await getKpi(this.supplierRef)
			} catch (e) {
				this.error = this.t('procest', 'Kon KPI niet laden.')
			} finally {
				this.loading = false
			}
		},
		formatDays(v) {
			return v === null || v === undefined ? '—' : `${Number(v).toFixed(1)} ${this.t('procest', 'dagen')}`
		},
		formatPct(v) {
			if (v === null || v === undefined) { return '—' }
			return `${Number(v).toFixed(1)}%`
		},
		formatScore(v) {
			if (v === null || v === undefined) { return '—' }
			return Number(v).toFixed(1)
		},
	},
}
</script>

<style scoped>
.lz-kpi { padding: 20px; max-width: 1000px; margin: 0 auto; }
.lz-toolbar { margin-bottom: 16px; }
.lz-state { padding: 40px 20px; text-align: center; }
.lz-state--error { color: var(--color-error, #c00); }
.lz-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.lz-kpi-tile { padding: 20px; background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #ddd); border-radius: 8px; text-align: center; }
.lz-kpi-tile h2 { margin: 0 0 8px 0; font-size: 14px; color: var(--color-text-maxcontrast, #555); font-weight: 500; }
.lz-kpi-value { font-size: 32px; font-weight: bold; margin: 0; }
.lz-empty { padding: 40px 20px; text-align: center; color: var(--color-text-maxcontrast, #555); }
</style>

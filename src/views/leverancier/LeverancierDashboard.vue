<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Leverancier-zaakportaal dashboard shell.
  -
  - Four-card landing page (tenders, invoices, contracts, KPI) for the
  - leverancier-zaakportaal Vue surface. Reads from
  - GET /apps/procest/api/leverancier-portaal/dashboard?supplierRef={uuid}.
  -
  - The supplier scope is selected by a SupplierScopePicker (operator side)
  - or injected by the eHerkenning broker (supplier side, chain member 02).
  -
  - @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
  -->
<template>
	<div class="lz-shell" data-testid="leverancier-shell">
		<header class="lz-header" role="banner">
			<h1>{{ t('procest', 'Leveranciersportaal') }}</h1>
			<div class="lz-scope">
				<label for="lz-scope-input" class="lz-scope-label">
					{{ t('procest', 'Supplier scope') }}
				</label>
				<input id="lz-scope-input"
					v-model="scopeInput"
					type="text"
					data-testid="leverancier-scope-input"
					:placeholder="t('procest', 'supplier UUID')"
					class="lz-scope-input"
					@change="reload">
			</div>
		</header>

		<main id="lz-main" class="lz-main">
			<div v-if="loading" data-testid="lz-loading" class="lz-state">
				<NcLoadingIcon :size="32" />
				<p>{{ t('procest', 'Loading dashboard…') }}</p>
			</div>

			<div v-else-if="error" data-testid="lz-error" class="lz-state lz-state--error" role="alert">
				<p>{{ error }}</p>
			</div>

			<div v-else-if="!summary" class="lz-state">
				<p>{{ t('procest', 'Enter a supplier UUID to load the dashboard.') }}</p>
			</div>

			<div v-else class="lz-cards">
				<router-link to="/leverancier/tenders" class="lz-card lz-card--tenders" data-testid="lz-card-tenders">
					<h2>{{ t('procest', 'Aanbestedingen') }}</h2>
					<p class="lz-count">{{ summary.tenders.count }}</p>
					<ul class="lz-card-detail">
						<li>{{ t('procest', 'Gegund') }}: {{ summary.tenders.awarded }}</li>
						<li>{{ t('procest', 'Evaluatie') }}: {{ summary.tenders.evaluating }}</li>
						<li>{{ t('procest', 'Afgewezen') }}: {{ summary.tenders.rejected }}</li>
					</ul>
				</router-link>

				<router-link to="/leverancier/facturen" class="lz-card lz-card--invoices" data-testid="lz-card-invoices">
					<h2>{{ t('procest', 'Facturen') }}</h2>
					<p class="lz-count">{{ summary.invoices.count }}</p>
					<ul class="lz-card-detail">
						<li>{{ t('procest', 'Open > 90 dagen') }}: {{ summary.invoices.overdue90Plus }}</li>
						<li>{{ t('procest', 'Betwist') }}: {{ summary.invoices.disputed }}</li>
					</ul>
				</router-link>

				<router-link to="/leverancier/contracten" class="lz-card lz-card--contracts" data-testid="lz-card-contracts">
					<h2>{{ t('procest', 'Contracten') }}</h2>
					<p class="lz-count">{{ summary.contracts.count }}</p>
					<ul class="lz-card-detail">
						<li>{{ t('procest', 'Bijna afloop') }}: {{ summary.contracts.expiringSoon }}</li>
						<li>{{ t('procest', 'Auto-verleng') }}: {{ summary.contracts.autoRenewing }}</li>
					</ul>
				</router-link>

				<router-link to="/leverancier/kpi" class="lz-card lz-card--kpi" data-testid="lz-card-kpi">
					<h2>{{ t('procest', 'KPI') }}</h2>
					<p class="lz-count">{{ summary.kpi.ready ? t('procest', 'Beschikbaar') : t('procest', 'Onvoldoende data') }}</p>
					<ul class="lz-card-detail">
						<li>{{ t('procest', 'Periode') }}: {{ summary.kpi.period }}</li>
					</ul>
				</router-link>
			</div>
		</main>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { getDashboardSummary } from '../../services/leverancierApi.js'

export default {
	name: 'LeverancierDashboard',
	components: { NcLoadingIcon },
	data() {
		return {
			scopeInput: '',
			summary: null,
			loading: false,
			error: null,
		}
	},
	mounted() {
		// Allow the operator to pre-fill the scope via query string for e2e.
		const queryScope = this.$route && this.$route.query && this.$route.query.supplierRef
		if (queryScope) {
			this.scopeInput = String(queryScope)
			this.reload()
		}
	},
	methods: {
		async reload() {
			if (!this.scopeInput || !this.scopeInput.trim()) {
				this.summary = null
				return
			}
			this.loading = true
			this.error = null
			try {
				this.summary = await getDashboardSummary(this.scopeInput.trim())
			} catch (e) {
				this.error = e && e.response && e.response.data && e.response.data.error
					? String(e.response.data.error)
					: this.t('procest', 'Failed to load dashboard.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.lz-shell {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}
.lz-header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
	border-bottom: 1px solid var(--color-border, #ddd);
	padding-bottom: 12px;
}
.lz-scope {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.lz-scope-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #555);
}
.lz-scope-input {
	min-width: 320px;
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: 4px;
}
.lz-main {
	min-height: 200px;
}
.lz-state {
	padding: 40px 20px;
	text-align: center;
}
.lz-state--error {
	color: var(--color-error, #c00);
}
.lz-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 16px;
}
.lz-card {
	display: block;
	padding: 20px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ddd);
	border-radius: 8px;
	color: inherit;
	text-decoration: none;
	transition: box-shadow 0.15s;
}
.lz-card:hover,
.lz-card:focus {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	outline: 2px solid var(--color-primary-element, #0082c9);
	outline-offset: 2px;
}
.lz-card h2 {
	margin: 0 0 8px 0;
	font-size: 16px;
}
.lz-count {
	font-size: 32px;
	font-weight: bold;
	margin: 0 0 8px 0;
}
.lz-card-detail {
	list-style: none;
	padding: 0;
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #555);
}
.lz-card-detail li {
	padding: 2px 0;
}
@media (max-width: 600px) {
	.lz-header {
		flex-direction: column;
		align-items: stretch;
	}
	.lz-scope-input {
		min-width: 0;
		width: 100%;
	}
}
</style>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Invoice list — sortable/filterable with status badges + overdue 90+ flag.
  -
  - Binds to GET /apps/procest/api/leverancier-portaal/invoices.
  - Badge colours from `LeverancierViewModelService::invoiceBadgeColor()`
  - (chain member 08); overdue90Plus flag from `isOverdue90Plus()`.
  -
  - @spec openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
  -->
<template>
	<div class="lz-invoices" data-testid="leverancier-invoice-list">
		<header class="lz-toolbar">
			<h1>{{ t('procest', 'Facturen') }}</h1>
			<div class="lz-filters">
				<label for="lz-inv-status" class="lz-filter-label">{{ t('procest', 'Status') }}</label>
				<select id="lz-inv-status"
					v-model="statusFilter"
					data-testid="leverancier-invoice-status-filter">
					<option value="">
						{{ t('procest', 'Alle') }}
					</option>
					<option value="received">
						{{ t('procest', 'Ontvangen') }}
					</option>
					<option value="under_review">
						{{ t('procest', 'In behandeling') }}
					</option>
					<option value="approved">
						{{ t('procest', 'Goedgekeurd') }}
					</option>
					<option value="disputed">
						{{ t('procest', 'Betwist') }}
					</option>
					<option value="rejected">
						{{ t('procest', 'Afgewezen') }}
					</option>
					<option value="paid">
						{{ t('procest', 'Betaald') }}
					</option>
				</select>
				<label class="lz-checkbox">
					<input v-model="onlyOverdue"
						type="checkbox"
						data-testid="leverancier-invoice-overdue-only">
					{{ t('procest', 'Alleen > 90 dagen open') }}
				</label>
			</div>
		</header>

		<div v-if="loading" data-testid="lz-loading" class="lz-state">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-else-if="error"
			data-testid="lz-error"
			class="lz-state lz-state--error"
			role="alert">
			{{ error }}
		</div>

		<table v-else-if="visibleRows.length"
			class="lz-table"
			data-testid="leverancier-invoice-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('procest', 'Factuurnummer') }}
					</th>
					<th scope="col">
						{{ t('procest', 'Bedrag') }}
					</th>
					<th scope="col">
						{{ t('procest', 'Verloopdatum') }}
					</th>
					<th scope="col">
						{{ t('procest', 'Status') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="inv in visibleRows" :key="inv.id || inv.invoiceNumber">
					<td>{{ inv.invoiceNumber || (inv.id || '').slice(0, 8) }}</td>
					<td>{{ formatAmount(inv.amount, inv.currency) }}</td>
					<td>
						{{ inv.dueDate || '—' }}
						<span v-if="inv.overdue90Plus"
							class="lz-overdue-flag"
							data-testid="leverancier-invoice-overdue-flag">
							{{ t('procest', '> 90 dagen') }}
						</span>
					</td>
					<td>
						<span class="lz-badge"
							:class="'lz-badge--' + (inv.badgeColor || 'gray')">
							{{ inv.status }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="lz-empty" data-testid="lz-empty">
			{{ t('procest', 'Geen facturen gevonden.') }}
		</p>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { listInvoices } from '../../services/leverancierApi.js'

export default {
	name: 'InvoiceList',
	components: { NcLoadingIcon },
	data() {
		return {
			rows: [],
			loading: false,
			error: null,
			statusFilter: '',
			onlyOverdue: false,
		}
	},
	computed: {
		supplierRef() {
			return (this.$route.query && this.$route.query.supplierRef) || ''
		},
		visibleRows() {
			let r = this.rows
			if (this.statusFilter) {
				r = r.filter(inv => inv.status === this.statusFilter)
			}
			if (this.onlyOverdue) {
				r = r.filter(inv => inv.overdue90Plus === true)
			}
			return r
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			if (!this.supplierRef) { this.rows = []; return }
			this.loading = true
			this.error = null
			try {
				const r = await listInvoices(this.supplierRef)
				this.rows = (r && r.items) || []
			} catch (e) {
				this.error = this.t('procest', 'Kon facturen niet laden.')
			} finally {
				this.loading = false
			}
		},
		formatAmount(amount, currency) {
			if (amount === undefined || amount === null) { return '—' }
			const cur = currency || 'EUR'
			try {
				return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: cur }).format(amount)
			} catch (e) {
				return `${cur} ${amount}`
			}
		},
	},
}
</script>

<style scoped>
.lz-invoices { padding: 20px; max-width: 1200px; margin: 0 auto; }
.lz-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: 12px; margin-bottom: 16px; }
.lz-filters { display: flex; gap: 12px; align-items: center; }
.lz-filter-label { font-size: 12px; color: var(--color-text-maxcontrast, #555); margin-right: 4px; }
.lz-checkbox { font-size: 13px; display: flex; align-items: center; gap: 4px; }
.lz-state { padding: 40px 20px; text-align: center; }
.lz-state--error { color: var(--color-error, #c00); }
.lz-table { width: 100%; border-collapse: collapse; }
.lz-table th, .lz-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border, #ddd); }
.lz-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; color: #fff; }
.lz-badge--gray   { background: #888; }
.lz-badge--blue   { background: #0082c9; }
.lz-badge--green  { background: #46ba61; }
.lz-badge--orange { background: #ed8d04; }
.lz-badge--red    { background: #c4474b; }
.lz-overdue-flag { display: inline-block; margin-left: 8px; padding: 1px 6px; border-radius: 8px; background: #c4474b; color: #fff; font-size: 11px; }
.lz-empty { padding: 40px 20px; text-align: center; color: var(--color-text-maxcontrast, #555); }
</style>

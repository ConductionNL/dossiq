<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Contract list with expiring-soon highlighting.
  -
  - Binds to GET /apps/procest/api/leverancier-portaal/contracts.
  - Expiring-soon predicate is from `ContractRenewalService::isWithinRenewalWindow()`
  - (chain member 09, surfaced via the dashboard summary card).
  -
  - @spec openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
  -->
<template>
	<div class="lz-contracts" data-testid="leverancier-contract-list">
		<header class="lz-toolbar">
			<h1>{{ t('procest', 'Contracten') }}</h1>
		</header>

		<div v-if="loading" data-testid="lz-loading" class="lz-state">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-else-if="error" data-testid="lz-error" class="lz-state lz-state--error" role="alert">
			{{ error }}
		</div>

		<table v-else-if="rows.length"
			class="lz-table"
			data-testid="leverancier-contract-table">
			<thead>
				<tr>
					<th scope="col">{{ t('procest', 'Contract') }}</th>
					<th scope="col">{{ t('procest', 'Periode') }}</th>
					<th scope="col">{{ t('procest', 'Einddatum') }}</th>
					<th scope="col">{{ t('procest', 'Verlenging') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="c in rows" :key="c.id">
					<td>{{ c.naam || c.title || (c.id || '').slice(0, 8) }}</td>
					<td>{{ c.startDate || '—' }} → {{ c.endDate || '—' }}</td>
					<td>
						{{ c.endDate || '—' }}
						<span v-if="c.renewalWindowSoon || c.expiringSoon"
							class="lz-expiring"
							data-testid="leverancier-contract-expiring-flag">
							{{ t('procest', 'Bijna afloop') }}
						</span>
					</td>
					<td>
						<span class="lz-badge"
							:class="'lz-badge--' + renewalBadge(c.renewalOption)">
							{{ renewalLabel(c.renewalOption) }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="lz-empty" data-testid="lz-empty">
			{{ t('procest', 'Geen contracten gevonden.') }}
		</p>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { listContracts } from '../../services/leverancierApi.js'

export default {
	name: 'ContractList',
	components: { NcLoadingIcon },
	data() {
		return { rows: [], loading: false, error: null }
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
			if (!this.supplierRef) { this.rows = []; return }
			this.loading = true
			this.error = null
			try {
				const r = await listContracts(this.supplierRef)
				this.rows = (r && r.items) || []
			} catch (e) {
				this.error = this.t('procest', 'Kon contracten niet laden.')
			} finally {
				this.loading = false
			}
		},
		renewalLabel(opt) {
			if (opt === 'auto') { return this.t('procest', 'Automatisch') }
			if (opt === 'manual') { return this.t('procest', 'Handmatig') }
			if (opt === 'none') { return this.t('procest', 'Geen') }
			return opt || '—'
		},
		renewalBadge(opt) {
			if (opt === 'auto') { return 'green' }
			if (opt === 'manual') { return 'blue' }
			return 'gray'
		},
	},
}
</script>

<style scoped>
.lz-contracts { padding: 20px; max-width: 1200px; margin: 0 auto; }
.lz-toolbar { margin-bottom: 16px; }
.lz-state { padding: 40px 20px; text-align: center; }
.lz-state--error { color: var(--color-error, #c00); }
.lz-table { width: 100%; border-collapse: collapse; }
.lz-table th, .lz-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border, #ddd); }
.lz-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; color: #fff; }
.lz-badge--gray  { background: #888; }
.lz-badge--blue  { background: #0082c9; }
.lz-badge--green { background: #46ba61; }
.lz-expiring { display: inline-block; margin-left: 8px; padding: 1px 6px; border-radius: 8px; background: #ed8d04; color: #fff; font-size: 11px; }
.lz-empty { padding: 40px 20px; text-align: center; color: var(--color-text-maxcontrast, #555); }
</style>

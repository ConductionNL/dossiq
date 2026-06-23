<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufEndpoints is the admin view used to inspect StUF-ZKN/BG outbound
  - endpoints per gemeente (zaaksysteem connection profile) and their
  - circuit-breaker health. Rendered as a tab inside AdminRoot's
  - CnSettingsSection, so it carries no NcSettingsSection wrapper of its own.
  -
  - @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
-->
<template>
	<div class="stuf-endpoints">
		<table class="stuf-endpoints__table" data-testid="stuf-endpoints-table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Name') }}</th>
					<th>{{ t('procest', 'Gemeente code') }}</th>
					<th>{{ t('procest', 'Application') }}</th>
					<th>{{ t('procest', 'SOAP version') }}</th>
					<th>{{ t('procest', 'Strategy') }}</th>
					<th>{{ t('procest', 'Health') }}</th>
					<th>{{ t('procest', 'Active') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in endpoints" :key="row.id">
					<td>{{ row.naam }}</td>
					<td>{{ row.gemeenteCode }}</td>
					<td>{{ row.ontvangerApplicatie }}</td>
					<td>{{ row.soapVersion }}</td>
					<td>{{ row.zaakIdentificatieStrategie || '—' }}</td>
					<td>
						<span class="stuf-endpoints__health" :class="healthClass(row)">
							{{ healthLabel(row) }}
						</span>
					</td>
					<td>{{ row.actief ? t('procest', 'Active') : t('procest', 'Inactive') }}</td>
				</tr>
				<tr v-if="!endpoints.length">
					<td colspan="7" class="stuf-endpoints__empty">
						{{ t('procest', 'No StUF endpoints configured yet.') }}
					</td>
				</tr>
			</tbody>
		</table>
		<p class="stuf-endpoints__note">
			{{ t('procest', 'Endpoints, credentials (WSSE), and mTLS certificates are managed by the platform operator. Reach out to your administrator to add or rotate them.') }}
		</p>
		<p v-if="loadError" class="stuf-endpoints__error">
			{{ loadError }}
		</p>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { listEndpoints } from '../../services/stufApi.js'

export default {
	name: 'StufEndpoints',
	data() {
		return {
			endpoints: [],
			loadError: '',
		}
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			try {
				const data = await listEndpoints()
				this.endpoints = Array.isArray(data.items) ? data.items : []
				this.loadError = ''
			} catch (e) {
				this.loadError = t('procest', 'Failed to load StUF endpoints')
				showError(this.loadError)
			}
		},
		healthClass(row) {
			const state = row && row.health && row.health.state ? row.health.state : 'ok'
			return 'stuf-endpoints__health--' + state
		},
		healthLabel(row) {
			const state = row && row.health && row.health.state ? row.health.state : 'ok'
			if (state === 'circuit_open') {
				return t('procest', 'Circuit open')
			}
			if (state === 'degraded') {
				return t('procest', 'Degraded')
			}
			return t('procest', 'OK')
		},
	},
}
</script>

<style scoped>
.stuf-endpoints__table {
	width: 100%;
	border-collapse: collapse;
}
.stuf-endpoints__table th,
.stuf-endpoints__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
.stuf-endpoints__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
.stuf-endpoints__health {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 11px;
	font-weight: bold;
}
.stuf-endpoints__health--ok {
	background: var(--color-success);
	color: white;
}
.stuf-endpoints__health--degraded {
	background: var(--color-warning);
	color: white;
}
.stuf-endpoints__health--circuit_open {
	background: var(--color-error);
	color: white;
}
.stuf-endpoints__note {
	color: var(--color-text-maxcontrast);
	margin-top: 12px;
	font-size: 13px;
}
.stuf-endpoints__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

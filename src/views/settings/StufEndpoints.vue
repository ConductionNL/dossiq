<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufEndpoints is the admin view used to inspect StUF-ZKN/BG outbound
  - endpoints per gemeente (zaaksysteem connection profile) and their
  - circuit-breaker health. Rendered as a tab inside AdminRoot's
  - CnSettingsSection, so it carries no NcSettingsSection wrapper of its own.
  -
  - @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
  -
  - @visual exclude Admin-only read-only panel rendered inside AdminRoot's settings section; its table only shows StUF endpoints + circuit-breaker health fetched from the backend, which requires a seeded zaaksysteem endpoint and the OpenRegister register installed. Without a live endpoint the view is its empty state, so a screenshot baseline would capture nothing meaningful. Covered by the env-gated live-e2e job; the render logic (healthClass/healthLabel) is unit-testable JS, not a stable pixel surface.
-->
<template>
	<div class="stuf-endpoints">
		<table class="stuf-endpoints__table" data-testid="stuf-endpoints-table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', 'Name') }}</th>
					<th scope="col">{{ t('dossiq', 'Municipality code') }}</th>
					<th scope="col">{{ t('dossiq', 'Application') }}</th>
					<th scope="col">{{ t('dossiq', 'SOAP version') }}</th>
					<th scope="col">{{ t('dossiq', 'Strategy') }}</th>
					<th scope="col">{{ t('dossiq', 'Health') }}</th>
					<th scope="col">{{ t('dossiq', 'Active') }}</th>
					<th scope="col">{{ t('dossiq', 'Connection test') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in endpoints" :key="row.id">
					<td>{{ row.name }}</td>
					<td>{{ row.municipalityCode }}</td>
					<td>{{ row.recipientApplication }}</td>
					<td>{{ row.soapVersion }}</td>
					<td>{{ row.caseIdentificationStrategy || '—' }}</td>
					<td>
						<span
							class="stuf-endpoints__health"
							:class="healthClass(row)">
							{{ healthLabel(row) }}
						</span>
					</td>
					<td>
						{{
							row.actief
								? t('dossiq', 'Active')
								: t('dossiq', 'Inactive')
						}}
					</td>
					<td class="stuf-endpoints__test">
						<NcButton
							:disabled="testing === row.id"
							:data-testid="'stuf-test-' + row.id"
							@click="test(row)">
							{{ t('dossiq', 'Test connection') }}
						</NcButton>
						<span
							class="stuf-endpoints__result"
							:class="'stuf-endpoints__result--' + resultFor(row).type">
							{{ resultFor(row).label }}
						</span>
						<span v-if="resultFor(row).measuredAt" class="stuf-endpoints__measured">
							{{ t('dossiq', 'Measured {moment}', { moment: resultFor(row).measuredAt }) }}
						</span>
					</td>
				</tr>
				<tr v-if="!endpoints.length">
					<td colspan="8" class="stuf-endpoints__empty">
						{{ t('dossiq', 'No StUF endpoints configured yet.') }}
					</td>
				</tr>
			</tbody>
		</table>
		<p class="stuf-endpoints__note">
			{{
				t(
					'dossiq',
					'Endpoints, credentials (WSSE), and mTLS certificates are managed by the platform operator. Reach out to your administrator to add or rotate them.',
				)
			}}
		</p>
		<p v-if="loadError" class="stuf-endpoints__error">
			{{ loadError }}
		</p>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { testStufEndpoint } from '../../services/connectionTestApi.js'
import { listEndpoints } from '../../services/stufApi.js'
import { connectionLabel } from '../../utils/starterStates.js'

export default {
	name: 'StufEndpoints',
	components: { NcButton },
	data() {
		return {
			endpoints: [],
			loadError: '',
			testing: '',
			// Keyed by endpoint id. An endpoint missing from here has not been
			// probed, which reads as "Not tested" and deliberately not as a
			// failure: a red cross on a connection nobody pressed the button
			// for sends somebody debugging a working integration.
			results: {},
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
		/**
		 * Reload the StUF endpoint list + circuit-breaker health from the backend.
		 *
		 * @spec exclude presentational reload helper — no business logic
		 */
		async reload() {
			try {
				const data = await listEndpoints()
				this.endpoints = Array.isArray(data.items) ? data.items : []
				this.loadError = ''
			} catch (e) {
				this.loadError = t('dossiq', 'Failed to load StUF endpoints')
				showError(this.loadError)
			}
		},

		/**
		 * Probe one endpoint and keep what came back.
		 *
		 * @param {object} row The endpoint row.
		 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
		 */
		async test(row) {
			this.testing = row.id
			try {
				this.results = { ...this.results, [row.id]: await testStufEndpoint(row.id) }
			} catch (e) {
				// A call that never completed is a failed test, not an absent
				// one: the button was pressed and the endpoint did not answer.
				this.results = {
					...this.results,
					[row.id]: {
						state: 'failed',
						reason: e.response?.data?.error || t('dossiq', 'The endpoint did not answer'),
						measuredAt: new Date().toISOString(),
					},
				}
			} finally {
				this.testing = ''
			}
		},

		/**
		 * What one endpoint's connection test reads as.
		 *
		 * @param {object} row The endpoint row.
		 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
		 */
		resultFor(row) {
			return connectionLabel(this.results[row.id] || null)
		},

		/**
		 * Map an endpoint's breaker health state to its CSS modifier class.
		 *
		 * @param {object} row The endpoint row.
		 * @spec exclude presentational CSS-class mapping — no business logic
		 */
		healthClass(row) {
			const state =
				row && row.health && row.health.state ? row.health.state : 'ok'
			return 'stuf-endpoints__health--' + state
		},

		/**
		 * Map an endpoint's breaker health state to a human label.
		 *
		 * @param {object} row The endpoint row.
		 * @spec exclude presentational label mapping — no business logic
		 */
		healthLabel(row) {
			const state =
				row && row.health && row.health.state ? row.health.state : 'ok'
			if (state === 'circuit_open') {
				return t('dossiq', 'Circuit open')
			}
			if (state === 'degraded') {
				return t('dossiq', 'Degraded')
			}
			return t('dossiq', 'OK')
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

.stuf-endpoints__test {
	white-space: nowrap;
}

.stuf-endpoints__result {
	margin-inline-start: 8px;
	font-size: 12px;
}

.stuf-endpoints__result--success {
	color: var(--color-success-text);
}

.stuf-endpoints__result--warning {
	color: var(--color-text-maxcontrast);
}

.stuf-endpoints__result--error {
	color: var(--color-error-text);
}

.stuf-endpoints__measured {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}
</style>

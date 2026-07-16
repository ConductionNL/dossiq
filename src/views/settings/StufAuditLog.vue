<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufAuditLog is the admin audit-log inspector for the StUF-ZKN/BG outbound
  - gateway. Reads /api/stuf/messages and surfaces the per-call envelope log.
  - Click "Inspect" to see the full envelope XML; the retries[] history and
  - fout payload appear in the dialog when present. Rendered as a tab inside
  - AdminRoot's CnSettingsSection, so it carries no NcSettingsSection wrapper.
  -
  - @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
  -
  - @visual exclude Admin-only read-only audit inspector rendered inside AdminRoot's settings section; it lists StUF SOAP envelope rows fetched from /api/stuf/messages, which only exist after real outbound/inbound traffic against a seeded zaaksysteem. Without that traffic the view is its empty state, so a screenshot baseline would capture nothing meaningful. Covered by the env-gated live-e2e job; the cell formatting (statusClass/pretty/berichtSoortOptions) is unit-testable JS, not a stable pixel surface.
-->
<template>
	<div class="stuf-audit-log">
		<div class="stuf-audit-log__filters">
			<NcTextField
				v-model="filters.endpointId"
				:label="t('procest', 'Endpoint ID')"
				:placeholder="t('procest', 'e.g. stuf-ep-amersfoort-key2zaken')" />
			<NcSelect
				v-model="filters.berichtSoort"
				:options="berichtSoortOptions"
				:input-label="t('procest', 'Message type')"
				clearable />
			<NcSelect
				v-model="filters.status"
				:options="statusOptions"
				:input-label="t('procest', 'Status')"
				clearable />
			<NcButton type="primary" :disabled="loading" @click="reload">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ loading ? t('procest', 'Reloading…') : t('procest', 'Reload') }}
			</NcButton>
			<NcButton type="tertiary" @click="exportCsv">
				{{ t('procest', 'Export CSV') }}
			</NcButton>
		</div>
		<table class="stuf-audit-log__table" data-testid="stuf-audit-log-table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Sent at') }}</th>
					<th>{{ t('procest', 'Direction') }}</th>
					<th>{{ t('procest', 'Message') }}</th>
					<th>{{ t('procest', 'Functie') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'HTTP') }}</th>
					<th>{{ t('procest', 'Duration (ms)') }}</th>
					<th class="stuf-audit-log__actions" />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in messages" :key="row.id || row.referentienummer">
					<td>{{ row.verzondenOp }}</td>
					<td>{{ row.richting }}</td>
					<td>{{ row.berichtSoort }}</td>
					<td>{{ row.functie }}</td>
					<td>
						<span class="stuf-audit-log__status" :class="statusClass(row.status)">{{ row.status }}</span>
					</td>
					<td>{{ row.httpStatus || '—' }}</td>
					<td>{{ row.duurMs || '—' }}</td>
					<td class="stuf-audit-log__actions">
						<NcButton type="tertiary" @click="inspect(row)">
							{{ t('procest', 'Inspect') }}
						</NcButton>
					</td>
				</tr>
				<tr v-if="!messages.length">
					<td colspan="8" class="stuf-audit-log__empty">
						{{ t('procest', 'No StUF messages match the filters.') }}
					</td>
				</tr>
			</tbody>
		</table>
		<NcDialog
			v-if="inspectRow"
			:name="t('procest', 'StUF envelope')"
			:open="!!inspectRow"
			size="large"
			@closing="inspectRow = null">
			<div class="stuf-audit-log__details">
				<h4>{{ t('procest', 'Request envelope') }}</h4>
				<pre class="stuf-audit-log__pre">{{ inspectRow.envelopeXml || t('procest', '(no envelope)') }}</pre>
				<h4 v-if="inspectRow.responseEnvelopeXml">
					{{ t('procest', 'Response envelope') }}
				</h4>
				<pre v-if="inspectRow.responseEnvelopeXml" class="stuf-audit-log__pre">{{ inspectRow.responseEnvelopeXml }}</pre>
				<h4 v-if="hasRetries(inspectRow)">
					{{ t('procest', 'Retries') }}
				</h4>
				<table v-if="hasRetries(inspectRow)" class="stuf-audit-log__retries">
					<thead>
						<tr>
							<th>{{ t('procest', 'Attempt') }}</th>
							<th>{{ t('procest', 'Timestamp') }}</th>
							<th>{{ t('procest', 'HTTP') }}</th>
							<th>{{ t('procest', 'Duration (ms)') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(retry, index) in inspectRow.retries" :key="index">
							<td>{{ retry.poging }}</td>
							<td>{{ retry.timestamp }}</td>
							<td>{{ retry.httpStatus || '—' }}</td>
							<td>{{ retry.duurMs || '—' }}</td>
						</tr>
					</tbody>
				</table>
				<h4 v-if="inspectRow.fout">
					{{ t('procest', 'Error') }}
				</h4>
				<pre v-if="inspectRow.fout" class="stuf-audit-log__pre">{{ pretty(inspectRow.fout) }}</pre>
			</div>
		</NcDialog>
		<p v-if="loadError" class="stuf-audit-log__error">
			{{ loadError }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { listMessages } from '../../services/stufApi.js'

export default {
	name: 'StufAuditLog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField },
	data() {
		return {
			messages: [],
			loadError: '',
			loading: false,
			inspectRow: null,
			filters: {
				endpointId: '',
				berichtSoort: '',
				status: '',
			},
		}
	},
	computed: {
		/**
		 * The StUF berichtsoort filter options.
		 *
		 * @spec exclude presentational filter-option list — static enum, no business logic
		 */
		berichtSoortOptions() {
			return ['Lk01', 'Lk02', 'Lk03', 'Bv01', 'Lv01', 'La01', 'Du01', 'Fo02']
		},
		/**
		 * The StUF message-status filter options.
		 *
		 * @spec exclude presentational filter-option list — static enum, no business logic
		 */
		statusOptions() {
			return ['verzonden', 'bevestigd', 'fout', 'wacht_op_retry']
		},
	},
	watch: {
		'filters.berichtSoort': 'reload',
		'filters.status': 'reload',
		'filters.endpointId': 'debouncedReload',
	},
	mounted() {
		this.reload()
	},
	beforeDestroy() {
		clearTimeout(this.endpointIdTimer)
	},
	methods: {
		/**
		 * Debounced wrapper around reload() for the free-text endpoint filter.
		 *
		 * @spec exclude presentational debounce helper — no business logic
		 */
		debouncedReload() {
			clearTimeout(this.endpointIdTimer)
			this.endpointIdTimer = setTimeout(() => this.reload(), 400)
		},
		/**
		 * Reload the StUF audit log from the backing store.
		 *
		 * @spec exclude presentational reload helper — no business logic
		 */
		async reload() {
			this.loading = true
			try {
				const data = await listMessages({
					endpointId: this.filters.endpointId,
					berichtSoort: this.filters.berichtSoort,
					status: this.filters.status,
					limit: 100,
				})
				this.messages = Array.isArray(data.items) ? data.items : []
				this.loadError = ''
			} catch (e) {
				this.loadError = t('procest', 'Failed to load StUF audit log')
				showError(this.loadError)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Open the envelope-inspector dialog for a row.
		 *
		 * @param {object} row The audit-log row.
		 * @spec exclude presentational dialog open — no business logic
		 */
		inspect(row) {
			this.inspectRow = row
		},
		hasRetries(row) {
			return Array.isArray(row.retries) && row.retries.length > 0
		},
		/**
		 * Map a message status to its CSS modifier class.
		 *
		 * @param {string} status The message status.
		 * @spec exclude presentational CSS-class mapping — no business logic
		 */
		statusClass(status) {
			return 'stuf-audit-log__status--' + (status || 'unknown')
		},
		/**
		 * Pretty-print a value as indented JSON for display.
		 *
		 * @param {*} value The value to render.
		 * @spec exclude presentational JSON formatter — no business logic
		 */
		pretty(value) {
			try {
				return JSON.stringify(value, null, 2)
			} catch (e) {
				return String(value)
			}
		},
		/**
		 * Export the current audit-log rows as a CSV download.
		 *
		 * @spec exclude presentational client-side CSV export — no business logic
		 */
		exportCsv() {
			const headers = ['verzondenOp', 'richting', 'berichtSoort', 'functie', 'status', 'httpStatus', 'duurMs', 'referentienummer', 'zaakIdentificatie']
			const lines = [headers.join(',')]
			for (const row of this.messages) {
				lines.push(headers.map((h) => JSON.stringify(row[h] == null ? '' : row[h])).join(','))
			}
			const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = 'stuf-audit-log.csv'
			a.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.stuf-audit-log__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 12px;
	align-items: end;
	flex-wrap: wrap;
}
.stuf-audit-log__table {
	width: 100%;
	border-collapse: collapse;
}
.stuf-audit-log__table th,
.stuf-audit-log__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
.stuf-audit-log__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
.stuf-audit-log__actions {
	text-align: right;
}
.stuf-audit-log__status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 11px;
	font-weight: bold;
}
.stuf-audit-log__status--verzonden {
	background: var(--color-primary);
	color: white;
}
.stuf-audit-log__status--bevestigd {
	background: var(--color-success);
	color: white;
}
.stuf-audit-log__status--fout {
	background: var(--color-error);
	color: white;
}
.stuf-audit-log__status--wacht_op_retry {
	background: var(--color-warning);
	color: white;
}
.stuf-audit-log__details h4 {
	margin: 16px 0 4px;
}
.stuf-audit-log__pre {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: var(--border-radius);
	overflow: auto;
	font-size: 11px;
	max-height: 320px;
	white-space: pre-wrap;
	word-break: break-all;
}
.stuf-audit-log__retries {
	width: 100%;
	border-collapse: collapse;
}
.stuf-audit-log__retries th,
.stuf-audit-log__retries td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
.stuf-audit-log__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

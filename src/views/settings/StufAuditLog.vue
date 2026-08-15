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
				v-model="filters.messageKind"
				:options="berichtSoortOptions"
				:inputLabel="t('procest', 'Message type')"
				clearable />
			<NcSelect
				v-model="filters.status"
				:options="statusOptions"
				:inputLabel="t('procest', 'Status')"
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
					<th scope="col">{{ t('procest', 'Sent at') }}</th>
					<th scope="col">{{ t('procest', 'Direction') }}</th>
					<th scope="col">{{ t('procest', 'Message') }}</th>
					<th scope="col">{{ t('procest', 'Function') }}</th>
					<th scope="col">{{ t('procest', 'Status') }}</th>
					<th scope="col">{{ t('procest', 'HTTP') }}</th>
					<th scope="col">{{ t('procest', 'Duration (ms)') }}</th>
					<th class="stuf-audit-log__actions" />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in messages" :key="row.id || row.referenceNumber">
					<td>{{ row.sentOn }}</td>
					<td>{{ row.direction }}</td>
					<td>{{ row.messageKind }}</td>
					<td>{{ row.role }}</td>
					<td>
						<span
							class="stuf-audit-log__status"
							:class="statusClass(row.status)"
							>{{ row.status }}</span
						>
					</td>
					<td>{{ row.httpStatus || '—' }}</td>
					<td>{{ row.durationMs || '—' }}</td>
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
		<StufEnvelopeDialog
			v-if="inspectRow"
			:row="inspectRow"
			@close="inspectRow = null" />
		<p v-if="loadError" class="stuf-audit-log__error">
			{{ loadError }}
		</p>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import StufEnvelopeDialog from '../../dialogs/StufEnvelopeDialog.vue'
import { listMessages } from '../../services/stufApi.js'

export default {
	name: 'StufAuditLog',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		StufEnvelopeDialog,
	},

	data() {
		return {
			messages: [],
			loadError: '',
			loading: false,
			inspectRow: null,
			filters: {
				endpointId: '',
				messageKind: '',
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
		'filters.messageKind': 'reload',
		'filters.status': 'reload',
		'filters.endpointId': 'debouncedReload',
	},

	mounted() {
		this.reload()
	},

	beforeUnmount() {
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
					messageKind: this.filters.messageKind,
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
		 * Export the current audit-log rows as a CSV download.
		 *
		 * @spec exclude presentational client-side CSV export — no business logic
		 */
		exportCsv() {
			const headers = [
				'sentOn',
				'direction',
				'messageKind',
				'role',
				'status',
				'httpStatus',
				'durationMs',
				'referenceNumber',
				'caseIdentification',
			]
			const lines = [headers.join(',')]
			for (const row of this.messages) {
				lines.push(
					headers
						.map((h) => JSON.stringify(row[h] == null ? '' : row[h]))
						.join(','),
				)
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

.stuf-audit-log__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcAppContent>
		<div class="woo-reports">
			<h2 class="woo-reports__title">
				{{ t('dossiq', 'Woo reports') }}
			</h2>

			<NcNoteCard type="info">
				{{
					t(
						'dossiq',
						'How many documents each reviewer assessed per day, by verdict. Every time you open this report, it is recorded on the Woo cases it counts.',
					)
				}}
			</NcNoteCard>

			<div class="woo-reports__filters">
				<NcTextField
					v-model="from"
					type="date"
					:label="t('dossiq', 'From')"
					data-testid="woo-throughput-from" />
				<NcTextField
					v-model="to"
					type="date"
					:label="t('dossiq', 'Until')"
					data-testid="woo-throughput-to" />
				<NcButton
					:disabled="!canRead"
					data-testid="woo-throughput-show"
					@click="load">
					{{ t('dossiq', 'Show') }}
				</NcButton>
				<NcButton
					v-if="rows.length > 0"
					:href="csvUrl"
					data-testid="woo-throughput-csv">
					{{ t('dossiq', 'Download CSV') }}
				</NcButton>
			</div>

			<NcNoteCard
				v-if="refusal"
				type="error"
				data-testid="woo-throughput-refusal">
				{{ refusal }}
			</NcNoteCard>

			<NcNoteCard v-if="truncated" type="warning">
				{{
					t(
						'dossiq',
						'This period holds more assessments than one report reads. Choose a shorter period.',
					)
				}}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" />

			<NcEmptyContent
				v-else-if="loaded && rows.length === 0 && !refusal"
				:name="t('dossiq', 'No assessments in this period')" />

			<table
				v-else-if="rows.length > 0"
				class="woo-reports__table"
				data-testid="woo-throughput-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('dossiq', 'Reviewer') }}
						</th>
						<th scope="col">
							{{ t('dossiq', 'Day') }}
						</th>
						<th scope="col">
							{{ t('dossiq', 'Public') }}
						</th>
						<th scope="col">
							{{ t('dossiq', 'Partly public') }}
						</th>
						<th scope="col">
							{{ t('dossiq', 'Not public') }}
						</th>
						<th scope="col">
							{{ t('dossiq', 'Total') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.reviewer + row.day">
						<td>{{ row.displayName }}</td>
						<td>{{ row.day }}</td>
						<td>{{ row.openbaar }}</td>
						<td>{{ row.deels_openbaar }}</td>
						<td>{{ row.niet_openbaar }}</td>
						<td>{{ row.total }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The query string of a throughput read over a period.
 *
 * @param {string} from The first day.
 * @param {string} to The last day.
 * @param {boolean} csv Whether the CSV is asked for.
 * @return {string} The URL.
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
export function throughputUrl(from, to, csv = false) {
	const params = new URLSearchParams({ from, to })
	if (csv) {
		params.set('format', 'csv')
	}
	return generateUrl(
		'/apps/dossiq/api/woo/reports/throughput?' + params.toString(),
	)
}

/**
 * The Woo reports screen: throughput per reviewer per day, read by the named group only.
 *
 * The menu offers this screen only while the report is switched on and the
 * user is in its reader group. The server checks both again on every read,
 * so the screen shows the server's refusal sentence when one comes back.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
export default {
	name: 'WooReportsView',
	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		const today = new Date()
		const monthAgo = new Date(today.getTime() - 30 * 24 * 3600 * 1000)
		return {
			from: monthAgo.toISOString().slice(0, 10),
			to: today.toISOString().slice(0, 10),
			rows: [],
			truncated: false,
			loading: false,
			loaded: false,
			refusal: '',
		}
	},

	computed: {
		/**
		 * Whether both days are filled in.
		 *
		 * @return {boolean} True when a period can be read.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
		 */
		canRead() {
			return this.from !== '' && this.to !== ''
		},

		/**
		 * The CSV download of the shown period.
		 *
		 * @return {string} The URL.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
		 */
		csvUrl() {
			return throughputUrl(this.from, this.to, true)
		},
	},

	methods: {
		t,

		/**
		 * Read the throughput of the chosen period.
		 *
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
		 */
		async load() {
			this.loading = true
			this.refusal = ''
			try {
				const { data } = await axios.get(throughputUrl(this.from, this.to))
				this.rows = data?.rows || []
				this.truncated = data?.truncated === true
			} catch (error) {
				// A refused read shows the reason and no rows, never the last answer.
				this.rows = []
				this.truncated = false
				this.refusal =
					error?.response?.data?.message
					|| t('dossiq', 'The report could not be read.')
			} finally {
				this.loading = false
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped>
.woo-reports {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 24px;
}

.woo-reports__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.woo-reports__table {
	border-collapse: collapse;
	width: 100%;
}

.woo-reports__table th,
.woo-reports__table td {
	border-bottom: 1px solid var(--color-border);
	padding: 8px;
	text-align: start;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section class="case-mail-parties" data-testid="case-mail-parties">
		<h3 class="case-mail-parties__title">
			{{ t('dossiq', 'Collected mail by sender, recipient and domain') }}
		</h3>

		<div class="case-mail-parties__filters">
			<NcTextField
				v-model="caseRef"
				:label="t('dossiq', 'Case')"
				data-testid="case-mail-parties-case" />
			<NcButton
				:disabled="!canRead"
				data-testid="case-mail-parties-show"
				@click="load">
				{{ t('dossiq', 'Show') }}
			</NcButton>
			<NcButton
				v-if="report && report.mails > 0"
				:href="csvUrl"
				data-testid="case-mail-parties-csv">
				{{ t('dossiq', 'Download CSV') }}
			</NcButton>
		</div>

		<NcNoteCard
			v-if="refusal"
			type="error"
			data-testid="case-mail-parties-refusal">
			{{ refusal }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="report && report.mails === 0"
			:name="t('dossiq', 'No mail collected on this case')" />

		<template v-else-if="report">
			<p
				class="case-mail-parties__totals"
				data-testid="case-mail-parties-totals">
				{{
					t(
						'dossiq',
						'Mails collected: {mails}. Headers that could not be read: {unreadable}.',
						{ mails: report.mails, unreadable: report.unreadable },
					)
				}}
			</p>

			<div class="case-mail-parties__lists">
				<table
					v-for="list in lists"
					:key="list.key"
					class="case-mail-parties__table"
					:data-testid="'case-mail-parties-' + list.key">
					<thead>
						<tr>
							<th scope="col">
								{{ list.label }}
							</th>
							<th scope="col">
								{{ t('dossiq', 'Count') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in list.rows" :key="row.name">
							<td>{{ row.name }}</td>
							<td>{{ row.count }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="case-mail-parties__note">
				{{
					t(
						'dossiq',
						'A mail counts once for an address and once for a domain, also when it names them more than once.',
					)
				}}
			</p>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The address of a case's mail parties report.
 *
 * @param {string} caseId The case.
 * @param {boolean} csv Whether the CSV is asked for.
 * @return {string} The URL.
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */
export function mailPartiesUrl(caseId, csv = false) {
	const url = generateUrl(
		'/apps/dossiq/api/cases/'
			+ encodeURIComponent(caseId)
			+ '/reports/mail-parties',
	)
	return csv ? url + '?format=csv' : url
}

/**
 * The parties of the mail a case collected: counts per sender, recipient and domain.
 *
 * It reads a case of any type. The server asks for read access to the case
 * and for the report to be switched on, on every read, so this shows the
 * server's refusal sentence when one comes back and never the last answer.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */
export default {
	name: 'CaseMailPartiesReport',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The case to start with; the reader may name another. */
		caseId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			caseRef: this.caseId,
			report: null,
			loading: false,
			refusal: '',
		}
	},

	computed: {
		/**
		 * Whether a case is named.
		 *
		 * @return {boolean} True when the report can be read.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
		 */
		canRead() {
			return this.caseRef.trim() !== ''
		},

		/**
		 * The CSV download of the shown case.
		 *
		 * @return {string} The URL.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
		 */
		csvUrl() {
			return mailPartiesUrl(this.caseRef.trim(), true)
		},

		/**
		 * The three lists of the report, each as name and count.
		 *
		 * @return {Array<{key: string, label: string, rows: Array<{name: string, count: number}>}>} The lists.
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
		 */
		lists() {
			const named = (rows, key) =>
				(rows || []).map((row) => ({ name: row[key], count: row.count }))
			return [
				{
					key: 'senders',
					label: t('dossiq', 'Sender'),
					rows: named(this.report?.senders, 'address'),
				},
				{
					key: 'recipients',
					label: t('dossiq', 'Recipient'),
					rows: named(this.report?.recipients, 'address'),
				},
				{
					key: 'domains',
					label: t('dossiq', 'Domain'),
					rows: named(this.report?.domains, 'domain'),
				},
			]
		},
	},

	methods: {
		t,

		/**
		 * Read the report of the named case.
		 *
		 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
		 */
		async load() {
			this.loading = true
			this.refusal = ''
			try {
				const { data } = await axios.get(mailPartiesUrl(this.caseRef.trim()))
				this.report = {
					mails: Number(data?.mails || 0),
					unreadable: Number(data?.unreadable || 0),
					senders: data?.senders || [],
					recipients: data?.recipients || [],
					domains: data?.domains || [],
				}
			} catch (error) {
				// A refused read shows the reason and no counts, never the last answer.
				this.report = null
				this.refusal =
					error?.response?.data?.message
					|| t('dossiq', 'The report could not be read.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.case-mail-parties {
	display: flex;
	flex-direction: column;
	gap: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	padding: 20px 22px;
}

.case-mail-parties__title {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
}

.case-mail-parties__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.case-mail-parties__lists {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 24px;
	width: 100%;
}

.case-mail-parties__table {
	border-collapse: collapse;
	width: 100%;
	align-self: start;
}

.case-mail-parties__table th,
.case-mail-parties__table td {
	border-bottom: 1px solid var(--color-border);
	padding: 8px;
	text-align: start;
	overflow-wrap: anywhere;
}

.case-mail-parties__totals,
.case-mail-parties__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>

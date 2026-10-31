/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail parties report of one case, as CnCountTablesReport config: the
 * case's collected mail counted by sender, recipient and domain. The endpoint
 * refuses a reader who may not read the case, with its own sentence.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */
export const mailPartiesReport = Object.freeze({
	url: '/apps/dossiq/api/cases/{id}/reports/mail-parties',
	title: 'Collected mail by sender, recipient and domain',
	objectLabel: 'Case',
	lists: [
		{ key: 'senders', label: 'Sender', nameField: 'address' },
		{ key: 'recipients', label: 'Recipient', nameField: 'address' },
		{ key: 'domains', label: 'Domain', nameField: 'domain' },
	],
	totals: 'Mails collected: {mails}. Headers that could not be read: {unreadable}.',
	emptyField: 'mails',
	emptyText: 'No mail collected on this case',
	csvParam: 'format=csv',
	note: 'A mail counts once for an address and once for a domain, also when it names them more than once.',
})

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Register a notice of default on a case's statutory decision term.
 *
 * @param {string} caseId The case.
 * @param {{receiptDate: string, notificationChannel: string, sender: string}} notice What came in.
 * @return {Promise<object>} The stored notice, with the accruing amount it started when it counts.
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
 */
export async function registerNotice(caseId, notice) {
	const { data } = await axios.post(
		generateUrl('/apps/dossiq/api/notices-of-default'),
		{
			caseId,
			...notice,
		},
	)
	return data
}

/**
 * Prepare the payout of a definitive accruing amount through the finance app.
 *
 * @param {string} accruingAmountId The accruing amount.
 * @param {{accountHolderName: string, iban: string}} account Where the money goes.
 * @return {Promise<object>} The payout as raised.
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
 */
export async function preparePayout(accruingAmountId, account) {
	const { data } = await axios.post(
		generateUrl(
			`/apps/dossiq/api/accruing-amounts/${encodeURIComponent(accruingAmountId)}/payout`,
		),
		account,
	)
	return data
}

/**
 * The sentence for a refused request, in the reader's language where the
 * server named the reason, else the server's own words, else the fallback.
 *
 * @param {object} error The axios error.
 * @param {string} fallback The sentence when the server said nothing usable.
 * @return {string} The sentence.
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
 */
export function noticeRefusal(error, fallback) {
	const data = error?.response?.data || {}
	if (data.refusal === 'term-running') {
		return data.termEnds
			? t(
					'dossiq',
					'The decision term runs until {date}. A notice of default only counts once the term has passed.',
					{ date: data.termEnds },
				)
			: t(
					'dossiq',
					'The decision term has not passed yet. A notice of default only counts once it has.',
				)
	}
	const said = data.message || data.error
	return typeof said === 'string' && said !== '' ? said : fallback
}

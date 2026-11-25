/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the grant dialogs of a case share: the read of the case's grant chain
 * (`GET /api/cases/{caseId}/grant-chain`), the label of an execution and an
 * amount in euro as the reader writes it.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the executions of the case's grants.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<Array<object>>} The executions, each with its reports and settlements.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export async function loadGrantChain(caseId) {
	const { data } = await axios.get(generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/grant-chain`))
	return Array.isArray(data?.executions) ? data.executions : []
}

/**
 * The words for one execution in a picker: its application number, else its id.
 *
 * @param {object} execution An execution of the grant chain.
 * @return {string}
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export function executionLabel(execution) {
	const number = String(execution?.applicationNumber ?? '')
	return number === '' ? t('dossiq', 'Grant {id}', { id: String(execution?.id ?? '') }) : t('dossiq', 'Grant {number}', { number })
}

/**
 * An amount in euro, with at most two decimals.
 *
 * @param {number|null|undefined} amount The amount.
 * @return {string} The amount, or an empty string when there is none.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export function formatAmount(amount) {
	if (amount === null || amount === undefined || Number.isFinite(Number(amount)) === false) {
		return ''
	}
	return Number(amount).toLocaleString(undefined, { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 })
}

/**
 * The message a refused write carries, else the fallback.
 *
 * @param {Error} e The axios error.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string}
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
export function refusalOf(e, fallback) {
	return e?.response?.data?.error || e?.response?.data?.message || fallback
}

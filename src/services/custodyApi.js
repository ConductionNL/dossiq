/**
 * The chain of custody of a case, and the request to take one over.
 *
 * 🔴 THE CHAIN IS READ AND NEVER WRITTEN FROM HERE. Every holding is opened by
 * the move that caused it, server-side, so this file has no create verb and no
 * close verb. A browser that could write a holding would be a second way for
 * the chain to disagree with the case, which is the failure the record exists
 * to prevent.
 *
 * 🔴 A REFUSAL IS NOT AN EMPTY CHAIN. Reading the custody needs read access to
 * the case, so the endpoint answers 403 to somebody who may not open it.
 * Drawing that as "this case has never changed hands" would be a claim about
 * the case's history that this reader was never told. Every caller here hands
 * the refusal back so the panel can say which of the two it is.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The base path of one act on one case.
 *
 * @param {string} caseId The case uuid.
 * @param {string} verb The path segment after `/api/case/{id}/`.
 * @return {string} The absolute url.
 */
function caseUrl(caseId, verb) {
	return generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(caseId)}/${verb}`)
}

/**
 * Every holding of a case, oldest first.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object>} The chain, its open holding and the total.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
 */
export async function readChain(caseId) {
	const { data } = await axios.get(caseUrl(caseId, 'custody'))

	return data ?? { holdings: [], open: null, total: 0 }
}

/**
 * Who held the case on one moment.
 *
 * @param {string} caseId The case uuid.
 * @param {string} on The moment, in anything the server's date reader takes.
 * @return {Promise<object|null>} The holding, or null when the case did not exist yet.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
 */
export async function readHolderOn(caseId, on) {
	const { data } = await axios.get(caseUrl(caseId, 'custody/holder'), { params: { on } })

	return data?.holding ?? null
}

/**
 * Every takeover request made on a case, newest first.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<Array<object>>} The requests.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */
export async function readTakeovers(caseId) {
	const { data } = await axios.get(caseUrl(caseId, 'takeovers'))

	return data?.requests ?? []
}

/**
 * Ask the holder for the case.
 *
 * @param {string} caseId The case uuid.
 * @param {string} reason Why the asker should have it.
 * @return {Promise<object>} The request as stored.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */
export async function askForCase(caseId, reason) {
	const { data } = await axios.post(caseUrl(caseId, 'takeover'), { reason })

	return data ?? {}
}

/**
 * Accept a takeover request: the case goes to whoever asked.
 *
 * @param {string} caseId The case uuid.
 * @param {string} takeoverId The request uuid.
 * @return {Promise<object>} The answered request.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */
export async function acceptTakeover(caseId, takeoverId) {
	const { data } = await axios.post(
		caseUrl(caseId, `takeover/${encodeURIComponent(takeoverId)}/accept`),
		{},
	)

	return data ?? {}
}

/**
 * Refuse a takeover request, with the reason the holder gives.
 *
 * The reason is required by the server as well as by this signature: a refusal
 * without one is not an answer, and sending an empty string is refused with a
 * 400 rather than recorded as a silent no.
 *
 * @param {string} caseId The case uuid.
 * @param {string} takeoverId The request uuid.
 * @param {string} reason Why the holder is keeping the case.
 * @return {Promise<object>} The answered request.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */
export async function refuseTakeover(caseId, takeoverId, reason) {
	const { data } = await axios.post(
		caseUrl(caseId, `takeover/${encodeURIComponent(takeoverId)}/refuse`),
		{ reason },
	)

	return data ?? {}
}

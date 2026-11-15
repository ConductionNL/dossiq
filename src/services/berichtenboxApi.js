/**
 * The Berichtenbox endpoints dossiq's frontend actually calls.
 *
 * 🔴 TWO HELPERS WERE REMOVED FROM THIS FILE ON 2026-09-19, AND THEIR ABSENCE
 * IS THE POINT. `pollReadStatus()` posted to `/api/berichtenbox/poll/{id}` and
 * `getTypeCodes()` read `/api/berichtenbox/types`. Neither path is in
 * `appinfo/routes.php`. They were written from REQ-001 of
 * `openspec/specs/berichtenbox-integration/spec.md`, which named the poll
 * endpoint `poll/{messageId}`; when #627 finally routed the controller it
 * chose `GET /api/berichtenbox/messages/{messageId}` instead, and the client
 * was never moved. `types` was never routed at any point.
 *
 * Nothing imported either one, so no handler ever saw the 404. That is the
 * honest size of it: a dead call cannot break a page. What it can do is be
 * picked up by the next person wiring a read-status indicator, who would find
 * a helper that looks supported and get a 404 with no hint why.
 *
 * `pollReadStatus` is not corrected to the real route, it is removed, because
 * there is nothing to poll. Logius Berichtenbox has no read status (integriq
 * spec `berichtenbox-client`), so the server-side poll route, the
 * `getReadStatus` seam and the daily `BerichtenboxReadStatusJob` were removed
 * as well. Delivery arrives by event: integriq reports it and
 * `DigitalPostDeliveredListener` writes it onto the message.
 *
 * `tests/vitest/berichtenboxApiRoutes.spec.js` reads this file against
 * `appinfo/routes.php` so the next drift is caught by a test rather than by a
 * reader.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/dossiq/api/berichtenbox')

/**
 * @param {object} data The data.
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
export async function sendMessage(data) {
	const response = await axios.post(`${baseUrl}/send`, data)
	return response.data
}

/**
 * @param {string} caseId Identifier of the case id.
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
export async function listMessages(caseId) {
	const response = await axios.get(`${baseUrl}/messages`, { params: { caseId } })
	return response.data
}

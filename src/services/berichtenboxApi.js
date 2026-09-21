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
 * dossiq should not be polling from the browser at all. Read status arrives
 * the other way now: integriq's seam reports delivery by event and
 * `DigitalPostDeliveredListener` writes it onto the message. `IntegriqAdapter`
 * answers `getReadStatus` with `unknown`, and `BerichtenboxService` is
 * explicit about what that means, stamping `readPolledAt` and refusing to move
 * the record. A button wired to the poll route would therefore tell a handler
 * nothing, at the cost of looking like it asked. The route and the guarded
 * controller stay, for the server-side job that a real transport will need.
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

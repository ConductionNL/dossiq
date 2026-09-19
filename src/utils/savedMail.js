// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The file names this act is for.
 *
 * The SERVER decides what a file really is: integriq's reader looks at the
 * bytes rather than the name, so a `.msg` renamed `.eml` still parses as what
 * it is. This list is only about which rows are worth offering the gesture
 * on, and it is deliberately generous: an extension that is not here is
 * refused here with a sentence rather than sent to be refused there.
 */
const MAIL_EXTENSIONS = ['.eml', '.msg', '.mbox']

/**
 * Whether a clicked node looks like a saved mail file.
 *
 * @param {object} node The clicked node.
 * @return {boolean} True when the name ends in a mail extension.
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */
export function looksLikeMail(node) {
	const name = String(node?.basename || node?.fileName || node?.name || '')
	return MAIL_EXTENSIONS.some((ext) => name.toLowerCase().endsWith(ext))
}

/**
 * The case the Files tab is open on.
 *
 * Read from the route rather than from the action's props, for the reason
 * every other dialog on this page reads it there: `open-modal` and `handler`
 * actions forward props verbatim with no token resolution, so an `@objectId`
 * would arrive as that literal string.
 *
 * @return {string} The case uuid, or ''.
 */
function caseIdFromLocation() {
	const match = String(window?.location?.pathname || '').match(
		/\/apps\/dossiq\/cases\/([^/?#]+)/,
	)
	return match === null ? '' : decodeURIComponent(match[1])
}

/**
 * Row-action handler for the case Files tab: read a saved `.eml` or `.msg` as
 * the message it is.
 *
 * WHY A FUNCTION AND NOT A DECLARATIVE ACTION. CnFilesBrowser's row-action
 * vocabulary is `open-modal` and `handler`; there is no `api-call` among
 * them, so a declared POST would render a menu item that does nothing when
 * clicked. It also has NO per-row condition: every row action is offered on
 * every file, so the check that this row is a mail file at all has to live
 * here, and it answers with a sentence rather than by being absent.
 *
 * dossiq parses nothing. The bytes go to integriq's reader through
 * `POST /api/cases/{caseId}/files/{fileId}/read-as-message`, the parsed
 * message is filed on the case, and the original file is never moved or
 * deleted: it is the record, and an archive that kept only the reading cannot
 * answer a question about the bytes later on.
 *
 * @param {...object} args The dispatch arguments; CnFilesBrowser appends the node last.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */
export async function readFileAsMessage(...args) {
	let node = null
	for (const arg of args) {
		if (arg !== null && typeof arg === 'object') {
			node = arg.item && typeof arg.item === 'object' ? arg.item : arg
		}
	}

	const fileId = Number(node?.fileid ?? node?.fileId ?? 0)
	if (!Number.isFinite(fileId) || fileId <= 0) {
		return
	}

	if (!looksLikeMail(node)) {
		showError(
			t(
				'dossiq',
				'This is not a saved mail file, so there is no message in it to read.',
			),
		)
		return
	}

	const caseId = caseIdFromLocation()
	if (caseId === '') {
		showError(t('dossiq', 'This file is not on a case.'))
		return
	}

	try {
		const { data } = await axios.post(
			generateUrl(
				`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/files/${fileId}/read-as-message`,
			),
		)

		// 🔴 A 200 IS NOT A MESSAGE. The endpoint answers 200 with
		// `outcome: kept` when integriq is absent or the file could not be
		// read, because both are real answers to what the handler asked. A
		// success toast over one of those would tell them the case now holds a
		// message it does not.
		if (data?.outcome !== 'imported') {
			showError(
				data?.reason
					|| t('dossiq', 'This file could not be read as a message.'),
			)
			return
		}

		showSuccess(
			t('dossiq', 'Filed as a message: {subject}', {
				subject: data.subject || '',
			}),
		)
		emit('cn:page:refresh')
	} catch (e) {
		showError(
			e?.response?.data?.error
				|| t('dossiq', 'This file could not be read as a message.'),
		)
	}
}

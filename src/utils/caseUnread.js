/**
 * Marking a case read or unread from a list row.
 *
 * Kept out of `customComponents.js` for the reason `caseClaim.js` gives: that
 * file imports every surviving custom page and tab, so importing it in a unit
 * test pulls the whole component tree in behind one function. The registry
 * entries are still declared there, which is what the manifest resolves
 * against.
 *
 * An index row action can only be `navigate`, `open-page` or a handler NAME
 * out of this registry. `api-call` is not in the row dispatcher's vocabulary
 * at all, so a declarative entry would render a menu item that does nothing
 * when clicked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { markRead, markUnread } from '../services/readStateApi.js'

/**
 * The case uuid of a list row.
 *
 * @param {object} item The row.
 * @return {string} The uuid, or the empty string when the row carries none.
 */
function caseIdOf(item) {
	return String(item?.id ?? item?.['@self']?.id ?? '')
}

/**
 * The signal the case lists listen for, so a row that changed repaints.
 *
 * The read state is not a field of the case, so no object-changed event
 * carries it and the row would keep its old badge until the next navigation.
 */
const CASES_CHANGED = 'dossiq:cases-changed'

/**
 * Row-action handler: put this case back to unread.
 *
 * @param {{actionId: string, item: object}} scope The row the action was used on.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function markCaseUnread({ item }) {
	const caseId = caseIdOf(item)
	if (caseId === '') {
		return
	}

	try {
		await markUnread(caseId)
		showSuccess(t('dossiq', 'You will see this as unread again.'))
		window.dispatchEvent(new CustomEvent(CASES_CHANGED))
	} catch (err) {
		const refusal = String(err?.response?.data?.message ?? '')
		showError(refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'))
	}
}

/**
 * Row-action handler: mark this case read without opening it.
 *
 * The same write the case page makes on open, offered from the list, because a
 * handler reading a queue of four hundred knows which rows they do not need to
 * open. It clears the notifications about the case as well: there is one act,
 * and a dossiq-side dismissal beside it would be a second answer to whether
 * somebody has dealt with this.
 *
 * @param {{actionId: string, item: object}} scope The row the action was used on.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function markCaseRead({ item }) {
	const caseId = caseIdOf(item)
	if (caseId === '') {
		return
	}

	try {
		await markRead(caseId)
		showSuccess(t('dossiq', 'You have marked this case read.'))
		window.dispatchEvent(new CustomEvent(CASES_CHANGED))
	} catch (err) {
		const refusal = String(err?.response?.data?.message ?? '')
		showError(refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'))
	}
}

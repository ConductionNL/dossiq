/**
 * Marking one message read or unread from a list row.
 *
 * A dossiq message is a `contactmoment` object with its own uuid, so the
 * gesture is the case's own one (caseUnread.js) on a different schema: the
 * same OpenRegister read-state endpoint, PUT to mark read and DELETE to mark
 * unread. dossiq stores no read marker of its own (D-1).
 *
 * The rows come from an `object-list` widget, whose dispatcher calls a
 * handler with the row either bare or inside `{ actionId, item }`, so the row
 * is taken from whichever object argument carries it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { CASE_REGISTER, markRead, markUnread } from '../services/readStateApi.js'

/** The schema a dossiq message is an object of. */
export const MESSAGE_SCHEMA = 'contactmoment'

/**
 * The message uuid of the row a handler was called with.
 *
 * @param {Array} args The handler's arguments.
 * @return {string} The uuid, or the empty string when no row carries one.
 */
function messageIdOf(args) {
	let row = null
	for (const arg of args) {
		if (arg === null || typeof arg !== 'object') {
			continue
		}
		row = arg.item && typeof arg.item === 'object' ? arg.item : arg
	}

	return String(row?.id ?? row?.['@self']?.id ?? '')
}

/**
 * Show the refusal OpenRegister gave, or a plain retry line when it gave none.
 *
 * @param {object} err The error the write threw.
 * @return {void}
 */
function showRefusal(err) {
	const refusal = String(err?.response?.data?.message ?? '')
	showError(refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'))
}

/**
 * Row-action handler: put this message back to unread, for this reader only.
 *
 * @param {...(object|null)} args The row, bare or as `{ actionId, item }`.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function markMessageUnread(...args) {
	const messageId = messageIdOf(args)
	if (messageId === '') {
		return
	}

	try {
		await markUnread(messageId, CASE_REGISTER, MESSAGE_SCHEMA)
		showSuccess(t('dossiq', 'You will see this message as unread again.'))
	} catch (err) {
		showRefusal(err)
	}
}

/**
 * Row-action handler: mark this message read without opening it.
 *
 * The PUT also clears the notifications about the message: one act, and no
 * dossiq-side dismissal beside it.
 *
 * @param {...(object|null)} args The row, bare or as `{ actionId, item }`.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function markMessageRead(...args) {
	const messageId = messageIdOf(args)
	if (messageId === '') {
		return
	}

	try {
		await markRead(messageId, null, CASE_REGISTER, MESSAGE_SCHEMA)
		showSuccess(t('dossiq', 'You have marked this message read.'))
	} catch (err) {
		showRefusal(err)
	}
}

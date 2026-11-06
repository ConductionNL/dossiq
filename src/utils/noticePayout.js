// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import { createApp } from 'vue'
import PayoutPrepareDialog from '../dialogs/PayoutPrepareDialog.vue'

/**
 * The notice row a row-action dispatch carries.
 *
 * CnIndexPage calls a handler with `{ actionId, item }`; the object-table
 * widget appends the row as the last argument. Both shapes are read.
 *
 * @param {Array<object>} args The dispatch arguments.
 * @return {object|null} The row, or null.
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
 */
export function noticeRowOf(args) {
	let row = null
	for (const arg of args) {
		if (arg === null || typeof arg !== 'object') {
			continue
		}
		row = arg.item && typeof arg.item === 'object' ? arg.item : arg
	}
	return row
}

/**
 * Row-action handler on the notices of default: open the payout dialog for
 * the row's accruing amount.
 *
 * A function rather than a declarative `open-modal`, because the object-table
 * widget opens a modal without the row, and the dialog needs the row's
 * accruing amount. A row without a fixed amount opens nothing.
 *
 * @param {...object} args The dispatch arguments.
 * @return {void}
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
 */
export function prepareNoticePayout(...args) {
	const row = noticeRowOf(args)
	if (
		row === null
		|| String(row.accruingAmountId || '') === ''
		|| row.penaltyState !== 'definitive'
	) {
		return
	}

	const host = document.createElement('div')
	document.body.appendChild(host)

	const app = createApp(PayoutPrepareDialog, {
		notice: row,
		onClose: () => {
			app.unmount()
			host.remove()
		},
	})
	app.mount(host)
}

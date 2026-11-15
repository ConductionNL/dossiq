/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One refusal or result banner in a dialog: a bold title and the sentence
 * under it, as the boards draw it above the footer buttons (DqBerichtVersturen,
 * DqZaakLocatie).
 *
 * The library's CnDialog draws it through its `notices` prop once that ships;
 * until then the dialog draws the same notice itself. `dialogNoticeProps`
 * decides which by asking CnDialog whether it takes the prop, so the banner
 * never shows twice and never shows nowhere.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */

import { CnDialog } from '@conduction/nextcloud-vue'

/**
 * Whether the installed CnDialog draws `notices` itself.
 *
 * @param {object} [dialog] The dialog component, CnDialog by default.
 * @return {boolean} True once the library takes the prop.
 * @spec openspec/specs/burger-notifications/spec.md
 */
export function dialogDrawsNotices(dialog = CnDialog) {
	return Boolean(dialog?.props && Object.hasOwn(dialog.props, 'notices'))
}

/**
 * A notice in the library's shape.
 *
 * @param {string} variant error, warning, success or info.
 * @param {string} title The bold first line.
 * @param {string} [text] The sentence under it.
 * @return {{variant: string, role: string, layout: string, title: string, text: string}} The notice.
 * @spec openspec/specs/burger-notifications/spec.md
 */
export function dialogNotice(variant, title, text = '') {
	return {
		variant,
		role: variant === 'error' || variant === 'warning' ? 'alert' : 'status',
		layout: 'stacked',
		title,
		text,
	}
}

/**
 * The props to bind on CnDialog for one notice: empty while the library does
 * not draw notices, so nothing unknown falls through to NcDialog.
 *
 * @param {object|null} notice The notice, or null for none.
 * @param {object} [dialog] The dialog component, CnDialog by default.
 * @return {object} The props.
 * @spec openspec/specs/burger-notifications/spec.md
 */
export function dialogNoticeProps(notice, dialog = CnDialog) {
	if (!dialogDrawsNotices(dialog)) {
		return {}
	}

	return { notices: notice ? [notice] : [], noticesPlacement: 'bottom' }
}

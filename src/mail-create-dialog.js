// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * On Mail's page: offer dossiq's own New case dialog to OpenRegister's Mail
 * panel, so "+ New case from this mail" opens the same dialog as New case in
 * dossiq (the case type questions, the requester picker, the duplicate check)
 * instead of OpenRegister's generic form.
 *
 * The panel reads `window.OCA.OpenRegister.mailCreateDialogs` when the button
 * is pressed, so it does not matter which script loads first. This entry only
 * registers; the dialog's code loads on the press.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import './publicPath.js'

window.OCA = window.OCA || {}
window.OCA.OpenRegister = window.OCA.OpenRegister || {}
window.OCA.OpenRegister.mailCreateDialogs = window.OCA.OpenRegister.mailCreateDialogs || []
window.OCA.OpenRegister.mailCreateDialogs.push({
	register: 'dossiq',
	schema: 'case',
	open: async (context) => {
		const { openNewCaseDialog } = await import(/* webpackChunkName: "mail-create-dialog-app" */ './mailCreateDialog/openNewCaseDialog.js')
		return openNewCaseDialog(context)
	},
})

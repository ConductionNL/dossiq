/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The `caseId` prop every case dialog declares.
 *
 * A dialog opened by a manifest `open-modal` action gets its props verbatim,
 * so `caseId` can still hold the unresolved `@objectId` token; each dialog
 * decides how it falls back to the route.
 *
 * @spec exclude shared prop declaration of the case dialogs, no behaviour of its own
 */
export const caseDialog = {
	props: {
		/** The case the dialog works on, or an unresolved `@` token. */
		caseId: {
			type: String,
			default: '',
		},
	},
}

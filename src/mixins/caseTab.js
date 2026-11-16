/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A case sidebar tab or detail panel: the host (CnObjectSidebar or
 * CnDetailWidgetHost) binds the case uuid as `objectId`.
 *
 * @spec exclude shared prop declaration of the case tabs, no behaviour of its own
 */
export const caseTab = {
	props: {
		/** The case uuid, bound by the sidebar or detail host. */
		objectId: {
			type: String,
			default: '',
		},
	},
}

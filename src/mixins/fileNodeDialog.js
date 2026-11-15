/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A dialog opened from a file action in the case's Files tab: the clicked
 * node's id and name.
 *
 * @spec exclude shared prop declaration of the file dialogs, no behaviour of its own
 */
export const fileNodeDialog = {
	props: {
		/** The Nextcloud file id of the clicked node. */
		fileId: {
			type: [String, Number],
			default: '',
		},

		/** The clicked node's name, for the dialog's sentences. */
		fileName: {
			type: String,
			default: '',
		},
	},
}

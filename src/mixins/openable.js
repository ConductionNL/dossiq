/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A dialog or panel its parent shows and hides through an `open` prop.
 *
 * @spec exclude shared prop declaration, no behaviour of its own
 */
export const openable = {
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
}

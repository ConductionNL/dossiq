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

/**
 * An openable dialog that reads `isOpen`, so a watcher can load what it
 * shows the moment it opens.
 *
 * @spec exclude shared prop declaration, no behaviour of its own
 */
export const openableDialog = {
	mixins: [openable],

	computed: {
		/**
		 * @return {boolean} Whether the dialog is showing.
		 * @spec exclude shared prop declaration
		 */
		isOpen() {
			return this.open === true
		},
	},
}

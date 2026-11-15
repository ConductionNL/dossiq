/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import { objectStoreAccess } from './objectStore.js'

/**
 * A case modal built on NcDialog that edits a form against the object store
 * and reports its closing as `close`.
 *
 * @spec exclude shared modal plumbing, no behaviour of its own
 */
export const modalForm = {
	mixins: [objectStoreAccess],

	methods: {
		/**
		 * NcDialog's `update:open`: a closed dialog tells its parent.
		 *
		 * @param {boolean} open Whether the dialog is open.
		 * @spec exclude shared modal plumbing
		 */
		onDialogClose(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}

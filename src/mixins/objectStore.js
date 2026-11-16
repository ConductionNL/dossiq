/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import { useObjectStore } from '../store/modules/object.js'

/**
 * Gives a component the OpenRegister object store as `this.objectStore`.
 *
 * @spec exclude shared store accessor, no behaviour of its own
 */
export const objectStoreAccess = {
	computed: {
		/**
		 * @return {object} The object store.
		 * @spec exclude shared store accessor
		 */
		objectStore() {
			return useObjectStore()
		},
	},
}

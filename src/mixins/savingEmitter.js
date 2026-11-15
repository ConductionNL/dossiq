/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Hands a payload to the parent while flagging `saving`.
 *
 * @spec exclude shared emit helper, no behaviour of its own
 */
export const savingEmitter = {
	data() {
		return {
			saving: false,
		}
	},

	methods: {
		/**
		 * Emit `event` with `payload`, flagging `saving` while the parent handles it.
		 *
		 * @param {string} event The event name.
		 * @param {object} payload What was filled in.
		 * @spec exclude shared emit helper
		 */
		emitWhileSaving(event, payload) {
			this.saving = true
			try {
				this.$emit(event, payload)
			} finally {
				this.saving = false
			}
		},
	},
}

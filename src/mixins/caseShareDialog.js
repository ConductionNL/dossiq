/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A `v-model:open` dialog on one case that hands what was filled in to its
 * parent as an event: the transfer, share and access-link dialogs.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
export const caseShareDialog = {
	props: {
		open: {
			type: Boolean,
			default: false,
		},

		caseId: {
			type: String,
			required: true,
		},
	},

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
		 * @spec openspec/specs/federated-case-collaboration/spec.md
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

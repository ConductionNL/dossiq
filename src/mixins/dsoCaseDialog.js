/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import { dsoErrorOf } from '../services/dsoCaseApi.js'
import { caseDialogTokenAware } from './caseDialog.js'

/**
 * A DSO case dialog that sends the case to another competent authority with
 * a note: the forward and the collaboration request.
 *
 * @param {string} noteField The data field holding the note.
 * @param {function(string, string, string): Promise<unknown>} sendToDso Posts `(caseId, authority, note)` to the DSO route.
 * @return {object} The mixin.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function dsoCaseDialog(noteField, sendToDso) {
	return {
		mixins: [caseDialogTokenAware],

		data() {
			return {
				authority: '',
				[noteField]: '',
				busy: false,
				error: '',
				sent: null,
			}
		},

		computed: {
			/** @spec openspec/changes/vth-cases-run-end-to-end */
			canSend() {
				return this.busy === false
					&& this.targetCaseId !== ''
					&& this.authority.trim() !== ''
					&& this[noteField].trim() !== ''
			},
		},

		methods: {
			/**
			 * Send the case through the DSO route and show that it went.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/changes/vth-cases-run-end-to-end
			 */
			async send() {
				if (!this.canSend) {
					return
				}
				this.busy = true
				this.error = ''
				try {
					const authority = this.authority.trim()
					await sendToDso(this.targetCaseId, authority, this[noteField].trim())
					this.sent = authority
					this.$emit('done', { authority })
				} catch (error) {
					this.error = dsoErrorOf(error)
				} finally {
					this.busy = false
				}
			},
		},
	}
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import { openable } from './openable.js'
import { savingEmitter } from './savingEmitter.js'

/**
 * A `v-model:open` dialog on one case that hands what was filled in to its
 * parent as an event: the transfer, share and access-link dialogs.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
export const caseShareDialog = {
	mixins: [openable, savingEmitter],

	props: {
		caseId: {
			type: String,
			required: true,
		},
	},
}

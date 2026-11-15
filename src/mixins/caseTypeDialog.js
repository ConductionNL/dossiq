/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import { caseTypeRefusalMessages } from '../utils/caseTypeDialogs.js'

/**
 * A case type header dialog: the case type it acts on and the refusal
 * sentences the server can answer with.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
export const caseTypeDialog = {
	props: {
		/**
		 * The case type to act on. Falls back to the route, because an
		 * `open-modal` header action forwards its props verbatim: a
		 * `@objectId` token in the manifest would arrive as that literal
		 * string.
		 */
		caseTypeId: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The sentences a refusal can carry, already translated.
		 *
		 * @return {object} The messages.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		refusalMessages() {
			return caseTypeRefusalMessages()
		},

		/**
		 * The case type this dialog acts on.
		 *
		 * @return {string} The id.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		id() {
			return this.caseTypeId || String(this.$route?.params?.id ?? '')
		},
	},
}

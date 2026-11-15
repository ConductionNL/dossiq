/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

import { resolveCaseId } from '../utils/resolveCaseId.js'

/**
 * The `caseId` prop every case dialog declares.
 *
 * A dialog opened by a manifest `open-modal` action gets its props verbatim,
 * so `caseId` can still hold the unresolved `@objectId` token; each dialog
 * decides how it falls back to the route.
 *
 * @spec exclude shared prop declaration of the case dialogs, no behaviour of its own
 */
export const caseDialog = {
	props: {
		/** The case the dialog works on, or an unresolved `@` token. */
		caseId: {
			type: String,
			default: '',
		},
	},
}

/**
 * A case dialog whose case is its `caseId` prop, or the route's when the prop
 * is empty.
 *
 * @spec exclude shared declaration of the case dialogs, no behaviour of its own
 */
export const caseDialogFromRoute = {
	mixins: [caseDialog],

	computed: {
		/**
		 * @return {string} The case id, from the prop or the route.
		 * @spec exclude shared declaration of the case dialogs
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},
	},
}

/**
 * A case dialog whose case is its `caseId` prop, or the route's when the prop
 * is empty or still an unresolved `@` token.
 *
 * @spec exclude shared declaration of the case dialogs, no behaviour of its own
 */
export const caseDialogTokenAware = {
	mixins: [caseDialog],

	computed: {
		/**
		 * @return {string} The case id, from the prop or the route.
		 * @spec exclude shared declaration of the case dialogs
		 */
		targetCaseId() {
			return resolveCaseId(this.caseId, this.$route)
		},
	},
}

/**
 * A case dialog that exposes its case as `resolvedCaseId`: the `caseId` prop,
 * or the route's when the prop is empty or still an unresolved `@` token.
 *
 * @spec exclude shared declaration of the case dialogs, no behaviour of its own
 */
export const caseDialogResolved = {
	mixins: [caseDialog],

	computed: {
		/**
		 * @return {string} The case id, from the prop or the route.
		 * @spec exclude shared declaration of the case dialogs
		 */
		resolvedCaseId() {
			return resolveCaseId(this.caseId, this.$route)
		},
	},
}

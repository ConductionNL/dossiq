/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A widget on a case type's detail page: the case type is the route's id.
 *
 * @spec exclude shared declaration of the case type page widgets, no behaviour of its own
 */
export const caseTypeWidget = {
	computed: {
		/**
		 * @return {string} The case type id from the route.
		 * @spec exclude shared declaration of the case type page widgets
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},
	},
}

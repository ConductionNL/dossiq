/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Reads what the component shows as soon as it is mounted: the component
 * supplies `load()`.
 *
 * @spec exclude shared lifecycle hook, no behaviour of its own
 */
export const loadOnMount = {
	/**
	 * @return {Promise<void>} When the first read has finished.
	 */
	async mounted() {
		await this.load()
	},
}

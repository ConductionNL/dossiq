/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A page header action of the manifest, found by its id.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

/**
 * The first header action with this id on any page of the manifest.
 *
 * @param {object} manifest The manifest.
 * @param {string} id The action id.
 * @return {object|null} The action, or null when no page declares it.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function findHeaderAction(manifest, id) {
	if (!id) {
		return null
	}
	for (const page of Array.isArray(manifest?.pages) ? manifest.pages : []) {
		const actions = Array.isArray(page?.config?.headerActions) ? page.config.headerActions : []
		const found = actions.find((action) => action?.id === id)
		if (found) {
			return found
		}
	}
	return null
}

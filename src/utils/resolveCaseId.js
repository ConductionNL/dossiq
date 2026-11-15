/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The case a dialog works on: its `caseId` prop, or the route's when the prop
 * still holds an unresolved `@` token (an `open-modal` action forwards its
 * props verbatim) or is empty.
 *
 * @param {string} caseId The dialog's `caseId` prop.
 * @param {object} route The current route, if any.
 * @param {Array<string>} params The route params to try, in order.
 * @return {string} The case id, or an empty string.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function resolveCaseId(caseId, route, params = ['id']) {
	const fromProp = caseId || ''
	if (fromProp !== '' && !fromProp.startsWith('@')) {
		return fromProp
	}
	for (const name of params) {
		const value = route?.params?.[name]
		if (value) {
			return value
		}
	}
	return ''
}

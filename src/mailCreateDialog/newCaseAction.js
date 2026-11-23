// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The New case action as dossiq's own pages declare it, for the dialog opened
 * from a mail: the same form, with the mail's values seeded over the action's
 * own seeds.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

/**
 * The first `new-case` open-form action among the manifest's page header actions.
 *
 * @param {object} manifest The app manifest.
 * @return {object|null}
 */
export function findNewCaseAction(manifest) {
	for (const page of manifest?.pages || []) {
		const action = (page?.config?.headerActions || []).find((candidate) => candidate?.id === 'new-case' && candidate?.type === 'open-form')
		if (action) {
			return action
		}
	}
	return null
}

/**
 * The action for a dialog opened from a mail: the mail's values seed the form
 * over the action's own seeds, and it stays on the page it was opened from.
 *
 * @param {object} action The New case action.
 * @param {object} initialData The values filled from the mail.
 * @return {object}
 */
export function actionFromMail(action, initialData) {
	const fromMail = {
		...action,
		props: { ...(action.props || {}), ...(initialData || {}) },
	}
	delete fromMail.onSuccessRoute
	return fromMail
}

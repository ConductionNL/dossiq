// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The New case action as dossiq's own pages declare it, for the dialog opened
 * from a mail: the same form, with the mail's values seeded over the action's
 * own seeds.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import { DYNAMIC_KEY_PREFIX } from '@conduction/nextcloud-vue'

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

/**
 * A template filled with the mail's values: each `{{placeholder}}` the mail
 * has a value for is replaced, the same way OpenRegister fills a schema's
 * `mailObjectTemplate`.
 *
 * @param {string} template The template, e.g. `{{senderName}}`.
 * @param {object} values The mail's placeholder values.
 * @return {string}
 */
export function fillFromMail(template, values) {
	return template.replace(/\{\{(\w+)\}\}/g, (match, key) => (values && key in values ? String(values[key]) : match))
}

/**
 * The answers a mail gives the case type's questions: every question with a
 * `mailValue` template gets that template filled from the mail, keyed the way
 * the form keys a case type's question. A template that stays empty or keeps
 * a placeholder the mail has no value for answers nothing.
 *
 * @param {Array<object>} questions The case type's question definitions.
 * @param {object} values The mail's placeholder values.
 * @return {object} Form keys mapped to their answers.
 */
export function questionAnswersFromMail(questions, values) {
	const answers = {}
	for (const question of questions || []) {
		if (typeof question?.mailValue !== 'string' || question.mailValue === '' || !question.id) {
			continue
		}
		const answer = fillFromMail(question.mailValue, values).trim()
		if (answer !== '' && !/\{\{\w+\}\}/.test(answer)) {
			answers[DYNAMIC_KEY_PREFIX + question.id] = answer
		}
	}
	return answers
}

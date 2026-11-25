// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Read the case type's questions and answer the ones a mail can answer.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { questionAnswersFromMail } from './newCaseAction.js'

/**
 * The case type's questions and the answers the mail gives them. Without a
 * case type, or when the questions cannot be read, the mail answers nothing,
 * the person fills them in, and `definitions` is null so the dialog reads the
 * questions itself.
 *
 * @param {string|undefined} caseType The case type the mail's template chose.
 * @param {object|undefined} values The mail's placeholder values.
 * @return {Promise<{answers: object, definitions: Array<object>|null}>} Form keys mapped to their answers, and the question records read.
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
export async function readMailQuestions(caseType, values) {
	if (!caseType) {
		return { answers: {}, definitions: null }
	}
	try {
		// The same query the form's case type field asks
		// (`x-openregister-extends-form`: caseType = the value, 100 at most),
		// so the dialog can show these records instead of reading them again.
		const response = await axios.get(generateUrl('/apps/openregister/api/objects/dossiq/propertyDefinition'), {
			params: { caseType, _limit: 100 },
		})
		const records = response.data?.results
		const definitions = Array.isArray(records) ? records : null
		return { answers: values ? questionAnswersFromMail(definitions || [], values) : {}, definitions }
	} catch {
		return { answers: {}, definitions: null }
	}
}

/**
 * The answers the mail gives the questions of the case type the dialog opens
 * on. Without a case type, or when the questions cannot be read, the mail
 * answers nothing and the person fills them in.
 *
 * @param {string|undefined} caseType The case type the mail's template chose.
 * @param {object|undefined} values The mail's placeholder values.
 * @return {Promise<object>} Form keys mapped to their answers.
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
export async function mailAnswersFor(caseType, values) {
	if (!values) {
		return {}
	}
	return (await readMailQuestions(caseType, values)).answers
}

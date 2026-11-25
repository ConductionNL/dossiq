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
 * The answers the mail gives the questions of the case type the dialog opens
 * on. Without a case type, or when the questions cannot be read, the mail
 * answers nothing and the person fills them in.
 *
 * @param {string|undefined} caseType The case type the mail's template chose.
 * @param {object|undefined} values The mail's placeholder values.
 * @return {Promise<object>} Form keys mapped to their answers.
 */
export async function mailAnswersFor(caseType, values) {
	if (!caseType || !values) {
		return {}
	}
	try {
		const response = await axios.get(generateUrl('/apps/openregister/api/objects/dossiq/propertyDefinition'), {
			params: { caseType, _limit: 100 },
		})
		return questionAnswersFromMail(response.data?.results || [], values)
	} catch {
		return {}
	}
}

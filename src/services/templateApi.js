// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Starting from something somebody prepared earlier: the case templates and
// the template library. Routed in appinfo/routes.php, served by
// lib/Controller/TemplateStartController.php.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/dossiq' + path)

/**
 * The case templates a handler may start from.
 *
 * @param {string} caseType Only templates of this case type, or '' for all.
 * @return {Promise<object>} The templates.
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
export async function listCaseTemplates(caseType = '') {
	const { data } = await axios.get(base('/api/case-templates'), { params: { caseType } })
	return data
}

/**
 * Start a case from a template.
 *
 * @param {string} templateId The template's id.
 * @param {object} overrides The fields the handler set themselves.
 * @return {Promise<object>} The new case.
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
export async function startFromTemplate(templateId, overrides = {}) {
	const { data } = await axios.post(base('/api/case-templates/' + templateId + '/start'), { overrides })
	return data
}

/**
 * The templates of one kind, offered on one case type.
 *
 * @param {string} kind One of document, mail, task, note, approval, result.
 * @param {object} params The case type, the case and a search term.
 * @return {Promise<object>} The templates.
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
export async function listContentTemplates(kind, params = {}) {
	const { data } = await axios.get(base('/api/content-templates/' + kind), { params })
	return data
}

export default { listCaseTemplates, startFromTemplate, listContentTemplates }

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { caseIdFromLocation } from './savedMail.js'

/**
 * The clicked node: the last object argument, or the `item` it wraps.
 *
 * @param {Array<unknown>} args The dispatch arguments.
 * @return {object|null} The node.
 */
function clickedNode(args) {
	let node = null
	for (const arg of args) {
		if (arg !== null && typeof arg === 'object') {
			node = (arg.item !== null && typeof arg.item === 'object') ? arg.item : arg
		}
	}
	return node
}

/**
 * Row-action handler for the case Files tab: open the file in the editor.
 *
 * The server checks the case and the file, shares that one file with the
 * caller for editing, and answers with the Files app link that opens it in
 * the editor the instance has for its type. A refusal is shown as the
 * server's sentence.
 *
 * @param {...object} args The dispatch arguments; CnFilesBrowser appends the node last.
 * @return {Promise<void>}
 * @spec openspec/specs/case-management/spec.md
 */
export async function openCaseFileInEditor(...args) {
	const node = clickedNode(args)
	const fileId = Number(node?.fileid ?? node?.fileId ?? 0)
	const caseId = caseIdFromLocation()
	if (!Number.isFinite(fileId) || fileId <= 0 || caseId === '') {
		showError(t('dossiq', 'This file is not on a case.'))
		return
	}

	try {
		const { data } = await axios.post(
			generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/files/${fileId}/editor`),
		)
		if (typeof data?.url !== 'string' || data.url === '') {
			throw new Error('no editor link')
		}
		window.location.assign(data.url)
	} catch (e) {
		showError(e?.response?.data?.error || t('dossiq', 'This file could not be opened for editing.'))
	}
}

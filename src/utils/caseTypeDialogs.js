// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the case-type dialogs have in common: the sentences a refusal can
// carry, and the post-and-land gesture of Duplicate and Start a new version.
//
// The sentences are literal t() calls in this file on purpose:
// `tests/l10n/check-l10n.js` extracts a translatable string by finding a
// literal inside a `t()` call for this app, and a string passed through a
// translate callback would be invisible to it.
//
// @spec openspec/specs/workflow-import-export/spec.md
// @spec openspec/specs/zaaktype-versioning/spec.md

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { copiedCaseTypeId, publishRefusalMessage } from './caseTypePublish.js'

/**
 * The sentences a refusal can carry, already translated.
 *
 * @return {object} The messages `publishRefusalMessage` reads.
 *
 * @spec openspec/specs/workflow-import-export/spec.md
 */
export function caseTypeRefusalMessages() {
	return {
		signIn: t('dossiq', 'Sign in again and retry.'),
		forbidden: t('dossiq', 'Your account may not do this.'),
		missing: t('dossiq', 'This case type no longer exists.'),
		generic: t(
			'dossiq',
			'That did not work. Try again, or ask an administrator.',
		),
	}
}

/**
 * Post a case-definition action and land on the case type it made.
 *
 * The dialog closes once the server answers. A case type that was made but
 * whose id cannot be read is still made: the dialog says nothing false, shows
 * `landing.lost`, and leaves the person where they were. A refusal keeps the
 * dialog open with the sentence for it.
 *
 * @param {object} dialog  The dialog: its `id`, `refusalMessages`, `busy` and `error`.
 * @param {{action: string, lost: string}} landing The action path segment and the sentence for a lost result.
 * @return {Promise<void>}
 *
 * @spec openspec/specs/workflow-import-export/spec.md
 */
export async function postAndLand(dialog, landing) {
	dialog.busy = true
	try {
		const { data } = await axios.post(
			generateUrl(
				`/apps/dossiq/api/case-definitions/${encodeURIComponent(dialog.id)}/${landing.action}`,
			),
		)
		landOn(dialog, { id: copiedCaseTypeId(data), lost: landing.lost })
	} catch (e) {
		dialog.error = publishRefusalMessage(e, dialog.refusalMessages)
	} finally {
		dialog.busy = false
	}
}

/**
 * Close the dialog and open the made case type, or say it could not be opened.
 *
 * @param {object} dialog The dialog component instance.
 * @param {{id: string, lost: string}} made The id read from the answer and the sentence for a lost one.
 * @return {void}
 */
function landOn(dialog, made) {
	dialog.$emit('close')
	if (made.id) {
		dialog.$router.push({
			name: 'CaseTypeDetail',
			params: { id: made.id },
		})
		return
	}
	dialog.error = made.lost
}

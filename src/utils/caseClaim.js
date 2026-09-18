/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Taking a case from a list row, kept out of `registry.js` so it can be tested
 * without mounting the app: that file imports every surviving custom page and
 * tab, so importing it in a unit test pulls the whole component tree (and every
 * `@nextcloud/vue` module-eval side effect) in behind one function. The
 * `kind: 'handler'` entry is still declared there, which is what the manifest
 * resolves against.
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Row-action handler for the Queue and Cases indexes: take this case.
 *
 * A FUNCTION handler rather than a declarative action, because an index row
 * action can only be `navigate`, `open-page` or a handler name. `api-call` is
 * not in the row dispatcher's vocabulary at all, and `object-op` merges
 * `action.values` into the row VERBATIM, with no token resolution, so a
 * declared assignee of `@me` would write that literal string onto the case.
 *
 * It posts the same endpoint the case page's Claim button does. The rule that
 * refuses a claim on a case somebody else already took lives there and only
 * there: a row the reader is looking at may have been picked up a second ago,
 * and no amount of client-side gating closes that window.
 *
 * The refusal SENTENCE comes from the server rather than from a code mapped
 * here, because the case page's Claim is a declarative `api-call` whose toast
 * can only show what the response carries. One gesture telling two different
 * stories depending on which surface it was made from would be worse than the
 * indirection, and both sentences come out of the same l10n catalogue anyway.
 *
 * @param {{actionId: string, item: object}} scope The row the action was used on.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */
export async function claimCase({ item }) {
	const caseId = String(item?.id ?? item?.['@self']?.id ?? '')
	if (caseId === '') {
		return
	}

	try {
		await axios.post(
			generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(caseId)}/claim`),
		)
		showSuccess(t('dossiq', 'You are now handling this case.'))
		// The same signal the reassign and bulk-transition handlers send: the
		// row the user just claimed no longer belongs on the queue, and leaving
		// it on screen invites a second claim on a case that already moved. The
		// list's own refresh comes from its live-collection subscription; this
		// event is the app-level notice beside it.
		window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
	} catch (err) {
		const refusal = String(err?.response?.data?.error ?? '')
		showError(
			refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'),
		)
	}
}

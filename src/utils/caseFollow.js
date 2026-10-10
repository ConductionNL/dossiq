/**
 * Following a case from a list row.
 *
 * Kept out of `registry.js` for the reason `caseUnread.js` gives: that file
 * imports every surviving custom page and tab, so importing it in a unit test
 * pulls the whole component tree in behind one function. The `kind: 'handler'`
 * entry is still declared there, which is what the manifest resolves
 * against.
 *
 * It replaces the star row action (`caseFavourite.js`): a favourite is a
 * follow with notifications off since openregister
 * `merge-follow-and-favourites`, so the row offers following, and the case
 * page's bell turns the notifications of that follow on or off.
 *
 * An index row action can only be `navigate`, `open-page` or a handler NAME
 * out of this registry, and the gesture is two verbs on one path, PUT to
 * follow and DELETE to stop, which no single declarative write can express.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-follow-control/specs/case-management/spec.md
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { isFollowing, objectIdOf, setFollowing } from '../services/watcherApi.js'

/**
 * The signal the case lists listen for, so a row that changed repaints.
 *
 * Following is not a field of the case, so no object-changed event carries it
 * and the row would keep its old state until the next navigation.
 */
const CASES_CHANGED = 'dossiq:cases-changed'

/**
 * Follow the row's case, or stop following it, whichever the row says.
 *
 * `@self.watching` rides every list row, so the click knows which way to go
 * without a read of its own.
 *
 * @param {object} context      The row action context.
 * @param {object} context.item The row the action was chosen on.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/one-follow-control/specs/case-management/spec.md
 */
export async function toggleCaseFollow({ item }) {
	const caseId = objectIdOf(item)
	if (caseId === '') {
		return
	}

	const wanted = isFollowing(item) === false

	try {
		await setFollowing(caseId, wanted)
		showSuccess(
			wanted
				? t('dossiq', 'You follow this case now.')
				: t('dossiq', 'You no longer follow this case.'),
		)
		window.dispatchEvent(new CustomEvent(CASES_CHANGED))
	} catch (err) {
		const refusal = String(err?.response?.data?.message ?? '')
		showError(
			refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'),
		)
	}
}

/**
 * Starring a case from a list row.
 *
 * Kept out of `registry.js` for the reason `caseUnread.js` gives: that file
 * imports every surviving custom page and tab, so importing it in a unit test
 * pulls the whole component tree in behind one function. The `kind: 'handler'`
 * entry is still declared there, which is what the manifest resolves
 * against.
 *
 * An index row action can only be `navigate`, `open-page` or a handler NAME
 * out of this registry. `api-call` is not in the row dispatcher's vocabulary
 * at all, so a declarative entry would render a menu item that does nothing
 * when clicked. And the gesture is two verbs on one path, PUT to star and
 * DELETE to unstar, which no single declarative write can express either way.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { isFavourite, objectIdOf, setFavourite } from '../services/favouriteApi.js'

/**
 * The signal the case lists listen for, so a row that changed repaints.
 *
 * The star is not a field of the case, so no object-changed event carries it
 * and the row would keep its old state until the next navigation. The same
 * event `caseUnread.js` raises, because the lists already listen for it.
 */
const CASES_CHANGED = 'dossiq:cases-changed'

/**
 * Row-action handler: star this case, or take the star off again.
 *
 * One entry rather than two, because the row already knows which way it goes:
 * `@self.favourite` rides every list row, so the menu item can say what the
 * click will do instead of offering both and doing nothing on one of them.
 *
 * @param {{actionId: string, item: object}} scope The row the action was used on.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */
export async function toggleCaseFavourite({ item }) {
	const caseId = objectIdOf(item)
	if (caseId === '') {
		return
	}

	const wanted = (isFavourite(item) === false)

	try {
		await setFavourite(caseId, wanted)
		showSuccess(
			wanted
				? t('dossiq', 'Added to your favourites.')
				: t('dossiq', 'Removed from your favourites.'),
		)
		window.dispatchEvent(new CustomEvent(CASES_CHANGED))
	} catch (err) {
		const refusal = String(err?.response?.data?.message ?? '')
		showError(refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'))
	}
}

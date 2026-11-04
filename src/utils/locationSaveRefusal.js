/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Why saving a location was refused, as the banner board DqZaakLocatie draws
 * above the buttons: an address the BAG does not know, a BAG check that could
 * not run, or the server's own sentence for anything else.
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md
 */

import { translate as t } from '@nextcloud/l10n'
import { dialogNotice } from './dialogNotice.js'

/**
 * The notice for one refused save.
 *
 * @param {object|undefined} body The error response body.
 * @return {object} The notice.
 */
export function locationSaveRefusal(body) {
	const code = String(body?.code ?? '')
	if (code === 'bag-address-unknown') {
		const id = String(body?.addressId ?? '')
		return dialogNotice(
			'error',
			t('dossiq', 'Save refused: this address is not in the BAG'),
			id === ''
				? t('dossiq', 'The address was not found. Choose an address from the list or check the house number.')
				: t('dossiq', 'Address designation {id} was not found. Choose an address from the list or check the house number.', { id }),
		)
	}
	if (code === 'bag-check-unavailable') {
		return dialogNotice(
			'warning',
			t('dossiq', 'The check itself could not run'),
			t('dossiq', 'The address could not be checked in the BAG: integriq has to be updated or installed for this check. Saving waits until that is done.'),
		)
	}

	return dialogNotice('error', t('dossiq', 'The location could not be saved.'), String(body?.message ?? body?.error ?? ''))
}

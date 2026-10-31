/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the partner organisations a case may be shared with or handed to.
 *
 * OpenRegister's organisations index lists only the organisations the user is
 * a member of, so a partner (somebody else's organisation) never shows there.
 * The search endpoint lists every organisation; of those, only the ones typed
 * `partner` are offered, never this instance's own tenant.
 *
 * @spec openspec/specs/share-dialog-lists-every-partner/spec.md#requirement-the-share-dialog-offers-every-partner-organisation
 * @return {Promise<Array<object>>} The partner organisations.
 */
export async function loadPartnerOrganisations() {
	const { data } = await axios.get(generateUrl('/apps/openregister/api/organisations/search'), { params: { _limit: 100 } })
	return (data?.organisations || []).filter((organisation) => organisation && organisation.type === 'partner')
}

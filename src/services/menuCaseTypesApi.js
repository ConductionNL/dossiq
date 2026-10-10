/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case types the current user chose for My case types in the sidebar.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The endpoint. */
const URL = '/apps/dossiq/api/menu-case-types'

/**
 * Read the chosen case types and every case type the user may add.
 *
 * @return {Promise<{chosen: Array<{id: string, title: string}>, available: Array<{id: string, title: string}>}>} The lists.
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */
export async function fetchMenuCaseTypes() {
	const { data } = await axios.get(generateUrl(URL))

	return { chosen: data?.chosen ?? [], available: data?.available ?? [] }
}

/**
 * Store the chosen case types in this order.
 *
 * @param {Array<string>} ids The case type uuids in menu order.
 * @return {Promise<Array<{id: string, title: string}>>} The list as the server kept it.
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */
export async function saveMenuCaseTypes(ids) {
	const { data } = await axios.put(generateUrl(URL), { ids })

	return data?.chosen ?? []
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a package's admin tab shows: the lists a package can declare
 * (`package.json` `adminTab.lists`), the heading of each, and the editor a
 * listed item opens. Nothing here is named after a procedure; the package
 * only declares which lists its tab has, and the server says what it installed.
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Every list a package tab may show, in the order the server's
 * PackageAdminTab::LISTS declares them.
 *
 * @type {Array<string>}
 */
export const PACKAGE_LISTS = ['caseTypes', 'flows', 'feeRules', 'templates', 'connections', 'checklists']

/**
 * The heading of a list.
 *
 * @param {string} list The list name.
 * @return {string} The heading, or the name for a list this page does not know.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function packageListLabel(list) {
	const labels = {
		caseTypes: t('dossiq', 'Case types'),
		flows: t('dossiq', 'Flows'),
		feeRules: t('dossiq', 'Fee rules'),
		templates: t('dossiq', 'Templates'),
		connections: t('dossiq', 'Connections'),
		checklists: t('dossiq', 'Inspection checklists'),
	}

	return labels[list] ?? list
}

/**
 * The page that edits one item of a list, or '' when the list has no page of its own.
 *
 * Case types and flows open their own editor; fee rules live in shillinq and
 * connections on the integrations page, so those open the list that holds them.
 *
 * @param {string} list The list name.
 * @param {string} id The item id.
 * @return {string} The URL, or ''.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function packageItemUrl(list, id) {
	const item = encodeURIComponent(String(id ?? ''))
	switch (list) {
	case 'caseTypes':
		return generateUrl(`/apps/dossiq/settings/case-types/${item}`)
	case 'flows':
		return generateUrl(`/apps/dossiq/flows/${item}`)
	case 'feeRules':
		return generateUrl('/apps/shillinq/overheid/fee-schedules')
	case 'connections':
		return generateUrl('/apps/dossiq/settings/integrations')
	default:
		return ''
	}
}

/**
 * How an item's status reads: a flow is a draft or published, anything else shows what it holds.
 *
 * @param {string} status The status the row holds.
 * @return {string} The status in words, or '' for none.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function packageItemStatus(status) {
	const statuses = {
		draft: t('dossiq', 'Draft'),
		published: t('dossiq', 'Published'),
		active: t('dossiq', 'Active'),
	}

	return statuses[status] ?? String(status ?? '')
}

/**
 * The tabs of the installed packages, as the admin settings page lists them.
 *
 * @param {Array<object>} packages The installed packages with a tab, from the server.
 * @return {Array<{id: string, label: string}>} One tab per package.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function packageTabsOf(packages) {
	return (Array.isArray(packages) ? packages : [])
		.filter((entry) => entry && typeof entry.id === 'string' && entry.id !== '')
		.map((entry) => ({ id: entry.id, label: String(entry.title || entry.id) }))
}

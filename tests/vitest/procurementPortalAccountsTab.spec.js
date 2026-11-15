/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The procurement pages share one set of tabs with the supplier portal
 * accounts, as board DqContracten draws "Contracten | Leveranciers |
 * Portaalaccounts". The accounts tab shows to an admin only.
 *
 * @spec openspec/specs/procurement-pages/spec.md#requirement-the-procurement-pages-link-the-supplier-portal-accounts
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {string} file Path under the repository root.
 * @return {object} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
}

const pages = [
	...readJson('src/manifest.d/70-privacy-and-procurement.json').pages,
	...readJson('src/manifest.json').pages,
]
const nl = readJson('l10n/nl.json').translations

describe.each(['Contracts', 'Suppliers', 'SupplierUsers'])('page tabs on %s', (id) => {
	const tabs = pages.find((page) => page.id === id).config.pageTabs

	it('are Contracts, Suppliers and Portal accounts, in board order', () => {
		expect(tabs.map((tab) => tab.route)).toEqual(['Contracts', 'Suppliers', 'SupplierUsers'])
		expect(tabs.map((tab) => nl[tab.label])).toEqual(['Contracten', 'Leveranciers', 'Portaalaccounts'])
	})

	it('show the accounts tab to an admin only', () => {
		expect(tabs.find((tab) => tab.route === 'SupplierUsers').adminOnly).toBe(true)
	})
})

describe('the accounts tab on the procurement pages', () => {
	it.each(['Contracts', 'Suppliers'])('asks no count on %s, where a non-admin may be reading', (id) => {
		const tab = pages.find((page) => page.id === id).config.pageTabs.find((entry) => entry.route === 'SupplierUsers')
		expect(tab.countFrom).toBeUndefined()
	})
})

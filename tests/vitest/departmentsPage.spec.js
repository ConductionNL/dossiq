/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The departments page draws what DqAfdelingen draws: the members as one
 * table, without a view switch, and the groups pane without a heading of
 * its own.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(
	path.resolve(__dirname, '../../src/manifest.d/68-departments.json'),
	'utf8',
))
const page = fragment.pages.find((entry) => entry.id === 'Departments')

describe('the departments page', () => {
	it('lists the members of the picked group from Nextcloud', () => {
		expect(page.config.entitySource).toBe('users')
		expect(page.config.folderSidebar).toMatchObject({ source: 'groups', filterField: 'group' })
	})

	it('offers no view switch, because the members are only ever a table', () => {
		expect(page.config.showViewToggle).toBe(false)
	})

	it('heads the members with the picked group and its count of staff', () => {
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(page.config.folderSidebar).toMatchObject({ heading: true, countText: '{count} staff' })
		expect(nl['{count} staff']).toBe('{count} medewerkers')
	})

	it('offers Downloaden in the header, the members as CSV', () => {
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(page.config.headerButtons).toEqual([{ action: 'export', label: 'Download', icon: 'TrayArrowDown' }])
		expect(nl.Download).toBe('Downloaden')
	})

	it('draws no title over the groups pane', () => {
		expect(page.config.folderSidebar.title).toBeUndefined()
	})
})

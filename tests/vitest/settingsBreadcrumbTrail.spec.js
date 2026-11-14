/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Record pages under Settings read their whole trail, as the boards do:
 * DqZaaktype "Instellingen / Zaaktypen / <case type>" and DqTenant
 * "Instellingen / Organisaties / <organisation>". The first crumb is the
 * crumb the list pages above them already draw.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json')
const organisations = read('src/manifest.d/67-environment-organisations.json')
const nl = read('l10n/nl.json').translations
const SETTINGS = { label: 'Settings', href: '/index.php/settings/admin/dossiq' }

/**
 * The Dutch trail of a breadcrumb list.
 *
 * @param {Array<object>} crumbs The declared crumbs.
 * @return {string[]} Their Dutch labels.
 */
function dutch(crumbs) {
	return crumbs.map((crumb) => nl[crumb.label])
}

describe('settings record pages: breadcrumb trail', () => {
	it('case type page reads Instellingen / Zaaktypen', () => {
		const crumbs = simple.pages.find((entry) => entry.id === 'CaseTypeDetail').config.breadcrumb
		expect(crumbs).toEqual([SETTINGS, { label: 'Case types', route: 'CaseTypes' }])
		expect(simple.pages.find((entry) => entry.id === 'CaseTypes').config.breadcrumb).toEqual(SETTINGS)
		expect(dutch(crumbs)).toEqual(['Instellingen', 'Zaaktypen'])
	})

	it('organisation page reads Instellingen / Organisaties', () => {
		const crumbs = organisations.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config.breadcrumb
		expect(crumbs).toEqual([SETTINGS, { label: 'Organisations', route: 'EnvironmentOrganisations' }])
		expect(dutch(crumbs)).toEqual(['Instellingen', 'Organisaties'])
	})
})

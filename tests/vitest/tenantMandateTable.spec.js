/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tenant page's mandate card reads as DqTenant draws it: per role, the
 * ceiling it decides up to ("Unlimited" without one), whether it decides
 * objections, and its enforcement level, from the mandate's terms.
 *
 * @spec openspec/specs/tenant-mandate-table/spec.md#requirement-the-tenant-page-shows-its-mandates-per-role
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/67-environment-organisations.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../lib/Settings/dossiq_register.json'), 'utf8'))

describe('the tenant mandate card', () => {
	it('shows each role with its ceiling, objections and enforcement', () => {
		const page = fragment.pages.find((p) => p.route === '/settings/organisations/:id')
		const card = page.config.widgets.find((w) => w.id === 'organisation-mandates')

		expect(card.content.columns).toEqual([
			{ key: 'mandateMatrixRef', label: 'Role' },
			{ key: 'terms.plafond', label: 'Decides up to', format: { style: 'currency', currency: 'EUR', decimals: 0 }, emptyLabel: 'Unlimited' },
			{ key: 'terms.decisionTypes', label: 'Decides objections', formatter: 'containsLabel', formatterOptions: { token: 'bezwaar', yes: 'Yes', no: 'No' } },
			{ key: 'terms.enforcement', label: 'Enforcement', widget: 'label', enum: ['none', 'warning', 'full'], enumLabels: { none: 'No', warning: 'Warning', full: 'Yes' } },
		])
	})

	it('reads them off the mandate\'s terms', () => {
		const terms = register.components.schemas.tenantMandate.properties.terms
		expect(Object.keys(terms.properties)).toEqual(['plafond', 'decisionTypes', 'enforcement'])
		expect(terms.properties.enforcement.enum).toEqual(['none', 'warning', 'full'])
	})
})

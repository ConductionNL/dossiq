/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeOverzicht "Rechten": one row per role, Role / See cases / Handle /
 * Decide / Documents, read from the rights matrix by the rights-by-role
 * endpoint. The full department x confidentiality matrix stays on the Rights
 * tab, which Edit opens.
 *
 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const widget = (id) => page.configAppend.widgets.find((entry) => entry.id === id)
const tabs = widget('case-type-panels').content.tabs
const tab = (id) => tabs.find((entry) => entry.id === id)
const nl = readJson('l10n', 'nl.json').translations

describe('the Rights card on the case type Overview (DqZaaktypeOverzicht)', () => {
	const summary = widget('case-type-rights-summary')

	it('reads one row per role from the rights-by-role endpoint', () => {
		expect(summary).toMatchObject({ type: 'record-list', title: 'Rights', subtitle: 'Who may do what with cases of this type' })
		expect(summary.content.endpointSource).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/rights-by-role', responsePath: 'rows' })
		expect(summary.content.presentation).toBe('table')
		expect(summary.content.columns.map((col) => [col.label, col.value])).toEqual([
			['Role', '{role}'], ['See cases', '{seeCases}'], ['Handle', '{handle}'], ['Decide', '{decide}'], ['Documents', '{documents}'],
		])
		summary.content.columns.forEach((col) => expect(nl[col.label]).toBeTruthy())
	})

	it('sits on the Overview where the matrix was, and Edit opens the matrix on the Rights tab', () => {
		expect(tab('overview').widgetIds).toContain('case-type-rights-summary')
		expect(tab('overview').widgetIds).not.toContain('case-type-rights')
		expect(tab('overview').layout.map((entry) => entry.widgetId)).toContain('case-type-rights-summary')
		expect(tab('rights').widgetIds).toContain('case-type-rights')
		expect(summary.headerActions[0]).toMatchObject({ label: 'Edit', action: { type: 'navigate', target: '/settings/case-types/@objectId#rights' } })
	})
})

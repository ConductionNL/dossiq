// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSubsidie draws a grant case's settlement (Vaststelling) on the
 * Overview tab. A settlement hangs off the execution, which hangs off the
 * application, two references away from the case, so the card reads
 * GET /api/cases/{caseId}/settlements, where the server walks that chain.
 * The subsidie case type lists the card; it shows on a case of the
 * Subsidies folder, as the other grant cards do.
 *
 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
/**
 * A JSON file of the app.
 *
 * @param {...string} parts The path from the app root.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const page = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((entry) => entry.id === 'CaseDetail').config

const settlement = page.widgets.find((widget) => widget.id === 'case-settlement')
const overview = page.widgets.find((widget) => widget.id === 'case-panels').content
	.tabs[0].widgetIds
const grant = readJson('lib', 'Settings', 'packages', 'subsidie', 'case-type.json').caseType
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

describe('the settlement on a grant case', () => {
	it("reads the case's settlements from a route that exists", () => {
		expect(settlement.type).toBe('record-list')
		expect(settlement.content.endpointSource).toEqual({
			url: '/apps/dossiq/api/cases/@objectId/settlements',
			responsePath: 'settlements',
		})
		expect(routes).toContain(
			"'name' => 'settlement#forCase', 'url' => '/api/cases/{caseId}/settlements', 'verb' => 'GET'",
		)
	})

	it('draws the date, the actual costs, the settled amount and the state', () => {
		expect(settlement.content.columns.map((column) => column.label)).toEqual([
			'Settlement date',
			'Actual costs',
			'Settled amount',
			'Status',
		])
		const [date, costs, amount, status] = settlement.content.columns
		expect(date.value).toEqual(['{determinationDate|date}', 'Not yet'])
		expect(costs.value).toEqual(['{actualCostTotal|currency}', 'Not yet'])
		expect(amount.value).toEqual(['{determinedAmount|currency}', 'Not yet'])
		expect(Object.keys(status.tone.map)).toEqual(['determined', 'draft'])
		expect(Object.keys(settlement.content.valueLabels.status)).toEqual(Object.keys(status.tone.map))
	})

	it('shows on a grant case, where the subsidie case type lists it after the advance schedule', () => {
		expect(settlement.visibleWhen).toEqual({
			field: '@context.caseType.category',
			op: 'in',
			value: ['Subsidies', 'Grants'],
		})
		expect(overview.indexOf('case-settlement')).toBe(overview.indexOf('case-payment-schedule') + 1)
		expect(grant.overviewCards).toContain('case-settlement')
	})

	it('has every string in English and Dutch', () => {
		const strings = [
			settlement.title,
			settlement.content.emptyText,
			settlement.content.errorText,
			'Not yet',
			...settlement.content.columns.map((column) => column.label),
			...Object.values(settlement.content.valueLabels.status),
		]
		for (const text of strings) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
		}
	})
})

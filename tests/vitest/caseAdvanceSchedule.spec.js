// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSubsidie draws a grant case's advance schedule (Voorschotschema) on
 * the Overview tab and its amounts (Bedragen) at the top of the side column.
 * Both read GET /api/cases/{caseId}/payment-schedule, the instalments of the
 * payment schedules on the case's decisions with their states and totals.
 * The subsidie case type lists both cards; the cards show on a case of the
 * Subsidies folder, as the other grant cards do.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
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

const schedule = page.widgets.find((widget) => widget.id === 'case-payment-schedule')
const amounts = page.sideColumn.find(
	(widget) => widget.id === 'side-payment-amounts',
)
const overview = page.widgets.find((widget) => widget.id === 'case-panels').content
	.tabs[0].widgetIds
const grant = readJson(
	'lib',
	'Settings',
	'packages',
	'subsidie',
	'case-type.json',
).caseType
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations
const GRANTS = {
	field: '@context.caseType.category',
	op: 'in',
	value: ['Subsidies', 'Grants'],
}

describe('the advance schedule on a grant case', () => {
	it("reads the case's payment schedule from a route that exists", () => {
		expect(schedule.content.endpointSource).toEqual({
			url: '/apps/dossiq/api/cases/@objectId/payment-schedule',
			responsePath: 'instalments',
		})
		expect(amounts.content.endpointSource).toEqual({
			url: '/apps/dossiq/api/cases/@objectId/payment-schedule',
		})
		expect(routes).toContain(
			"'name' => 'paymentSchedule#forCase', 'url' => '/api/cases/{caseId}/payment-schedule', 'verb' => 'GET'",
		)
	})

	it("draws the board's columns: instalment, planned, amount, condition, status", () => {
		expect(schedule.content.presentation).toBe('table')
		expect(schedule.content.columns.map((column) => column.label)).toEqual([
			'Instalment',
			'Planned',
			'Amount',
			'Condition',
			'Status',
		])
		const [, planned, amount, condition, status] = schedule.content.columns
		expect(planned.value).toEqual(['{dueOn|date}', 'At settlement'])
		expect(amount.value).toBe('{amount|currency}')
		expect(condition.value).toEqual(['{condition}', 'None'])
		expect(status.value).toEqual(['Paid {raisedAt|date}', '{state}'])
		expect(Object.keys(status.tone.map)).toEqual([
			'paid',
			'ready',
			'waiting',
			'planned',
		])
		expect(Object.keys(schedule.content.valueLabels.state)).toEqual(
			Object.keys(status.tone.map),
		)
	})

	it('shows the totals the board names, and says so when there is no schedule', () => {
		expect(
			amounts.content.fields.map((field) => [
				field.label,
				field.path,
				field.format,
			]),
		).toEqual([
			['Granted', 'totals.total', 'currency'],
			['Paid', 'totals.paid', 'currency'],
			['Ready for payment', 'totals.ready', 'currency'],
			['Still to pay', 'totals.toPay', 'currency'],
		])
		expect(amounts.content.emptyWhen).toBe('empty')
	})

	it('shows on a grant case, where the subsidie case type lists both cards', () => {
		expect(schedule.visibleWhen).toEqual(GRANTS)
		expect(amounts.visibleWhen).toEqual(GRANTS)
		expect(overview).toContain('case-payment-schedule')
		expect(grant.overviewCards).toContain('case-payment-schedule')
		expect(grant.sideCards[0]).toBe('side-payment-amounts')
	})

	it('has every string in English and Dutch', () => {
		const strings = [
			schedule.title,
			schedule.content.emptyText,
			schedule.content.errorText,
			...schedule.content.columns.map((column) => column.label),
			'At settlement',
			'None',
			'Paid {raisedAt|date}',
			...Object.values(schedule.content.valueLabels.state),
			amounts.title,
			amounts.content.footnote,
			...amounts.content.fields.map((field) => field.label),
		]
		for (const text of strings) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
		}
	})
})

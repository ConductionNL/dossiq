// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Overview cards per case type. A case type that lists `overviewCards` shows
 * its own cards on the Overview tab; the generic ones (the request, case
 * data, priority, locations, plan, term and payment, flows, hours) show only
 * while that list is empty, so a case type that lists nothing keeps today's
 * Overview. A domain card follows its own condition.
 *
 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config

const widget = (id) => simple.widgets.find((candidate) => candidate.id === id)
const overview = simple.widgets.find((candidate) => candidate.id === 'case-panels').content.tabs[0].widgetIds
const GENERIC = ['case-request', 'case-details', 'case-priority-card', 'case-locations-card', 'case-plan-card', 'case-terms', 'case-flow-runs', 'case-kpis-hours']
const STEPS_ASIDE = { field: '@context.caseType.overviewCards', op: 'empty' }
const optedIn = (id) => ({ field: '@context.caseType.overviewCards', op: 'contains', value: id })

/**
 * The case types a package ships.
 *
 * @param {string} id The package id.
 * @return {Array<object>} The case types.
 */
function packageCaseTypes(id) {
	const data = readJson('lib', 'Settings', 'packages', id, 'case-type.json')
	return Array.isArray(data.caseType) ? data.caseType : [data.caseType]
}

describe('overview cards per case type', () => {
	it('shows every generic Overview card while the case type lists no cards of its own, or where it lists that card', () => {
		for (const id of GENERIC) {
			expect(overview, id).toContain(id)
			expect(widget(id).visibleWhen, id).toEqual({ any: [STEPS_ASIDE, optedIn(id)] })
		}
	})

	it('keeps the domain cards on their own conditions', () => {
		for (const id of overview.filter((candidate) => !GENERIC.includes(candidate))) {
			expect(JSON.stringify(widget(id).visibleWhen), id).toContain('@context.caseType.category')
		}
		expect(overview.slice(0, 3)).toEqual(['case-objection-grounds', 'case-objection-advice-card', 'case-summary'])
	})

	it('lets the packages list cards of the Overview tab', () => {
		expect(packageCaseTypes('jeugdwet')[0].overviewCards).toEqual(['case-summary'])
		const objection = packageCaseTypes('bezwaar-beroep').find((caseType) => caseType.identifier === 'bezwaar')
		expect(objection.overviewCards).toEqual(['case-objection-grounds', 'case-objection-advice-card'])
		for (const caseType of [packageCaseTypes('jeugdwet')[0], objection]) {
			for (const id of caseType.overviewCards) {
				expect(overview).toContain(id)
			}
		}
		const seeded = readJson('lib', 'Settings', 'register.d', '84-woo-bezwaar-case-type.json')
			.components.objects.find((row) => row['@self'].schema === 'caseType' && row.identifier === 'bezwaar')
		expect(seeded.overviewCards).toEqual(objection.overviewCards)
		for (const caseType of packageCaseTypes('vth')) {
			expect(caseType.overviewCards).toBeUndefined()
		}
		const grant = packageCaseTypes('subsidie')[0]
		expect(grant.overviewCards).toEqual(['case-grant-decisions-card', 'case-payment-schedule'])
		for (const id of grant.overviewCards) {
			expect(overview).toContain(id)
		}
	})
})

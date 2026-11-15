/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "What now?" card per stage. The case page keys it on the status role,
 * so a domain case type shows it once its statuses carry roles: the decision
 * stage of an objection and the evaluation of a tender are `review`, and no
 * case type gives one role to two statuses (a flow moves a case by role). The
 * LHS advice page keys it on the intervention the advice settles on.
 *
 * @spec openspec/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

/**
 * The statuses of one case type in a package, by identifier.
 *
 * @param {string} id The package id.
 * @param {string} identifier The case type identifier.
 * @return {Array<object>} The statuses.
 */
function statusesOf(id, identifier) {
	const data = readJson('lib', 'Settings', 'packages', id, 'case-type.json')
	const caseTypes = Array.isArray(data.caseType) ? data.caseType : [data.caseType]
	const caseType = caseTypes.find((candidate) => candidate.identifier === identifier)
	return data.statusTypes.filter((status) => status.caseType === caseType.id)
}

const casePage = readJson('src', 'menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail').config
const lhsPage = readJson('src', 'manifest.json').pages.find((page) => page.id === 'LhsRecommendationDetail').config
const lhsSchema = readJson('lib', 'Settings', 'dossiq_register.json').components.schemas.lhsRecommendation

describe('the What now? card per stage', () => {
	it('gives the decision stage of an objection and of a tender the review role, once', () => {
		for (const [id, identifier, name] of [['bezwaar-beroep', 'bezwaar', 'Advies uitgebracht'], ['aanbesteding', 'aanbesteding', 'Beoordeling']]) {
			const statuses = statusesOf(id, identifier)
			expect(statuses.find((status) => status.name === name).role).toBe('review')
			const roles = statuses.map((status) => status.role).filter(Boolean)
			expect(new Set(roles).size, `${identifier} gives a role to one status only`).toBe(roles.length)
		}
		const seeded = readJson('lib', 'Settings', 'register.d', '84-woo-bezwaar-case-type.json').components.objects
			.find((row) => row['@self'].schema === 'statusType' && row.name === 'Advies uitgebracht')
		expect(seeded.role).toBe('review')
	})

	it('has a card for the review stage on the case page', () => {
		expect(casePage.stageField).toBe('statusRole')
		expect(casePage.nextStep.stages.review.checklist.length).toBeGreaterThan(0)
	})

	it('has a card for every intervention on the LHS advice page', () => {
		expect(lhsPage.nextStep.field).toBe('finalIntervention')
		for (const intervention of lhsSchema.properties.finalIntervention.enum) {
			expect(lhsPage.nextStep.stages[intervention]?.checklist.length, intervention).toBeGreaterThan(0)
		}
	})
})

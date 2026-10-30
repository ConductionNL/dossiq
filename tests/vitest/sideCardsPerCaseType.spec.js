// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Side cards per case type. The case page reads the case type's `sideCards`
 * through `sideColumnFrom`: a case type that lists nothing shows the column
 * as declared (deadline, requester, handling, work instruction), one that
 * lists cards shows those, in its order. The domain packages list the cards
 * their boards draw. What the page renders from the list is the library's
 * (nextcloud-vue detail-side-column-from, CnDetailPageSideColumnFrom.spec.js);
 * this holds dossiq's side of it: the key, the declared default and the lists.
 *
 * @spec openspec/specs/case-page/spec.md
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

const sideId = (entry) => (typeof entry === 'string' ? entry : entry.id)
const sideIds = simple.sideColumn.map(sideId)
const GENERIC = ['side-advice-requested', 'case-tile-deadline', 'side-requester', 'side-handling', 'side-linked', 'side-work-instruction']

function packageCaseTypes (id) {
	const data = readJson('lib', 'Settings', 'packages', id, 'case-type.json')
	return Array.isArray(data.caseType) ? data.caseType : [data.caseType]
}

describe('side cards per case type: the configuration', () => {
	it('reads the list off the case type', () => {
		expect(simple.sideColumnFrom).toBe('@context.caseType.sideCards')
		expect(simple.visibilityContext.caseType.field).toBe('caseType')
	})

	it('keeps the generic cards as the declared column, for a case type that lists none', () => {
		expect(sideIds.slice(0, GENERIC.length)).toEqual(GENERIC)
		for (const caseType of packageCaseTypes('vth')) {
			expect(caseType.sideCards).toBeUndefined()
		}
	})

	it('lets the domain packages list cards of the column, in the order their boards draw them', () => {
		expect(packageCaseTypes('jeugdwet')[0].sideCards).toEqual(['side-special-data-health', 'side-social-consent', 'side-social-people', 'side-social-history', 'case-banner-stack'])
		expect(packageCaseTypes('aanbesteding')[0].sideCards).toEqual(['case-tile-deadline', 'case-banner-stack'])
		const objection = packageCaseTypes('bezwaar-beroep').find((caseType) => caseType.identifier === 'bezwaar')
		expect(objection.sideCards).toEqual(['case-tile-deadline', 'side-objection-appeal', 'case-banner-stack'])
		for (const id of ['jeugdwet', 'aanbesteding', 'bezwaar-beroep']) {
			for (const caseType of packageCaseTypes(id)) {
				for (const card of caseType.sideCards ?? []) {
					expect(sideIds, `${id} lists ${card}`).toContain(card)
				}
			}
		}
	})

	it('gives the register-seeded objection case type the same list as its package', () => {
		const seeded = readJson('lib', 'Settings', 'register.d', '84-woo-bezwaar-case-type.json')
			.components.objects.find((row) => row['@self'].schema === 'caseType' && row.identifier === 'bezwaar')
		const packaged = packageCaseTypes('bezwaar-beroep').find((caseType) => caseType.identifier === 'bezwaar')
		expect(seeded.sideCards).toEqual(packaged.sideCards)
	})
})

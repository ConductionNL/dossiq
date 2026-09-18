/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dividing a case, and the dated reports inside one.
 *
 * 🔴 BOTH HALVES ARE READ AFTER EVERY SPLIT. A spec that checked only the new
 * case would pass on a COPY, which is the defect this change exists to end:
 * two cases each claiming the same document, and a handler tidying up by hand.
 * So every split assertion names what the original still holds as well as what
 * the new case took.
 *
 * 🔴 THE REFUSAL IS PROBED BEFORE THE ALLOWED SPLIT, NOT AFTER. A case type
 * that forbids dividing documents has to refuse first: watching a permitted
 * split succeed proves the endpoint works and says nothing about the rule.
 *
 * 🔴 THE THREE REPORTS ARE RECORDED OUT OF ORDER. Recorded in order, a list
 * sorted on the creation moment reads correctly by accident and the spec goes
 * green over the exact defect it is written for.
 *
 * ⚠️ NOT RUN IN THIS PHASE. The integration branch defers Playwright to the
 * nightly; this spec is written, tagged and left for it. The same scenarios are
 * watched against a real in-memory register by CaseSplitServiceTest,
 * CaseSplitRelationTest, IncidentServiceTest and IncidentHandoverTest.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'

let api: APIRequestContext
let token: string
let caseTypeId = ''

/**
 * The url of one act on one case.
 *
 * @param caseId The case uuid.
 * @param verb The path segment after `/api/case/{id}/`.
 */
function caseApi(caseId: string, verb: string): string {
	return `/index.php/apps/${REGISTER}/api/case/${caseId}/${verb}`
}

test.beforeAll(async ({ playwright }) => {
	api = await playwright.request.newContext()
	token = await getRequestToken(api)
	const machine = await seedStateMachine(api, token)
	caseTypeId = machine.caseTypeId
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

/**
 * One case with four documents and two parties on it.
 *
 * @param title A short title, prefixed with this run's marker by seedCase.
 */
async function caseWithMaterial(title: string) {
	const created = await seedCase(api, token, {
		title: `${RUN_PREFIX} ${title}`,
		caseType: caseTypeId,
	})
	const caseId = objectId(created)

	const documents: string[] = []
	for (const name of ['Foto', 'Brief', 'Rapport', 'Bijlage']) {
		const document = await createObject(api, token, 'caseDocument', {
			case: caseId,
			title: `${RUN_PREFIX} ${name}`,
		})
		documents.push(objectId(document))
	}

	const parties: string[] = []
	for (const name of ['Buurman', 'Aannemer']) {
		const party = await createObject(api, token, 'role', {
			case: caseId,
			name: `${RUN_PREFIX} ${name}`,
		})
		parties.push(objectId(party))
	}

	return { caseId, documents, parties }
}

/**
 * The ids of one schema's rows on one case.
 *
 * @param schema The schema slug.
 * @param caseId The case.
 */
async function idsOn(schema: string, caseId: string): Promise<string[]> {
	const rows = await listObjects(api, schema, { case: caseId, _limit: 50 })

	return rows.map((row: unknown) => objectId(row)).sort()
}

test.describe('@spec REQ-SPL-01 a split moves what was chosen', () => {
	test('a document goes to one half and not the other', async () => {
		const { caseId, documents, parties } = await caseWithMaterial('split source')

		const split = await api.post(caseApi(caseId, 'split'), {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} de tweede klacht`,
				documents: [documents[0], documents[1]],
				parties: [parties[0]],
			},
		})
		expect(split.ok(), `split -> ${split.status()} ${await split.text()}`).toBeTruthy()
		const newId = objectId((await split.json()).case)

		expect(await idsOn('caseDocument', newId)).toEqual(
			[documents[0], documents[1]].sort(),
		)
		expect(
			await idsOn('caseDocument', caseId),
			'The original keeps the rest, which is what makes this a split and not a copy.',
		).toEqual([documents[2], documents[3]].sort())

		expect(await idsOn('role', newId)).toEqual([parties[0]])
		expect(await idsOn('role', caseId)).toEqual([parties[1]])

		const original = await showObject(api, 'case', caseId)
		expect(original.splitInto, 'The original says where the missing material went.').toBe(newId)
		expect(
			(original.splitMovedItems || []).map((row: { id: string }) => row.id).sort(),
			'Once a document has moved it no longer names this case, so nothing could reconstruct what left.',
		).toEqual([documents[0], documents[1]].sort())
	})
})

test.describe('@spec REQ-SPL-02 the split is related in both directions', () => {
	test('both cases name each other', async () => {
		const { caseId, documents } = await caseWithMaterial('split related')

		const split = await api.post(caseApi(caseId, 'split'), {
			headers: { requesttoken: token },
			data: { title: `${RUN_PREFIX} de tweede klacht`, documents: [documents[0]] },
		})
		const newCase = (await split.json()).case

		const related = JSON.parse(String(newCase.relatedCases || '[]'))
		expect(related[0].caseId, 'The new half names the original without a query.').toBe(caseId)
		expect(
			String(related[0].toelichting),
			'And says the link came from a split, not from a copy somebody made by hand.',
		).toContain('Split')

		const original = await showObject(api, 'case', caseId)
		expect(original.splitInto).toBe(objectId(newCase))
	})
})

test.describe('@spec REQ-SPL-03 the case type bounds the division', () => {
	test('a case type that forbids dividing documents refuses, and names the rule', async () => {
		const { caseId, documents } = await caseWithMaterial('split forbidden')
		await updateObject(api, token, 'caseType', caseTypeId, { splitMayDivide: ['parties'] })

		const refused = await api.post(caseApi(caseId, 'split'), {
			headers: { requesttoken: token },
			data: { title: `${RUN_PREFIX} niet toegestaan`, documents: [documents[0]] },
		})

		expect(refused.status(), 'A rule the case type declares is a refusal, not a server error.').toBe(409)
		const body = await refused.json()
		expect(String(body.message ?? body.error ?? '')).toContain('documents')

		expect(
			await idsOn('caseDocument', caseId),
			'And nothing moved: a refusal that half-moved the file is worse than either answer.',
		).toHaveLength(4)

		// Put the case type back, so the cases seeded after this one can split.
		await updateObject(api, token, 'caseType', caseTypeId, { splitMayDivide: [] })
	})
})

test.describe('@spec REQ-INC-01 dated incidents on a case', () => {
	test('three reports on one address read in the order they happened', async () => {
		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Kerkstraat 12`,
			caseType: caseTypeId,
		})
		const caseId = objectId(created)

		// OUT OF ORDER ON PURPOSE. Recorded in order, a list sorted on the
		// creation moment would read correctly by accident.
		for (const report of [
			{ eventDate: '2026-09-02T09:00:00+02:00', description: 'September', reporter: 'buurman' },
			{ eventDate: '2026-06-14T09:00:00+02:00', description: 'Juni', reporter: 'wijkagent' },
			{ eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart', reporter: 'melder' },
		]) {
			const recorded = await api.post(caseApi(caseId, 'incidents'), {
				headers: { requesttoken: token },
				data: report,
			})
			expect(recorded.ok(), `record -> ${recorded.status()} ${await recorded.text()}`).toBeTruthy()
		}

		const listed = await (await api.get(caseApi(caseId, 'incidents'))).json()

		expect(listed.total).toBe(3)
		expect(
			listed.incidents.map((row: { description: string }) => row.description),
			'A report written up late belongs where it happened, not where it was typed.',
		).toEqual(['Maart', 'Juni', 'September'])
		expect(
			listed.incidents.map((row: { reporter: string }) => row.reporter),
		).toEqual(['melder', 'wijkagent', 'buurman'])
	})
})

test.describe('@spec REQ-INC-02 an incident hands off without moving the case', () => {
	test('one report moves to an inspector and the case stays with the area handler', async () => {
		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Kerkstraat 14`,
			caseType: caseTypeId,
			assignee: 'admin',
		})
		const caseId = objectId(created)

		const first = await (
			await api.post(caseApi(caseId, 'incidents'), {
				headers: { requesttoken: token },
				data: { eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart' },
			})
		).json()
		await api.post(caseApi(caseId, 'incidents'), {
			headers: { requesttoken: token },
			data: { eventDate: '2026-06-14T09:00:00+02:00', description: 'Juni' },
		})

		const assigned = await api.post(
			caseApi(caseId, `incidents/${objectId(first)}/assign`),
			{ headers: { requesttoken: token }, data: { assignee: 'inspecteur' } },
		)
		expect(assigned.ok()).toBeTruthy()
		expect((await assigned.json()).assignee).toBe('inspecteur')

		const held = await showObject(api, 'case', caseId)
		expect(
			held.assignee,
			'The area handler keeps the address; an inspector took one report on it, not the case.',
		).toBe('admin')
	})
})

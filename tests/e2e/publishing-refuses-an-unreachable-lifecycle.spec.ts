/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publishing refuses a lifecycle a case could never run through.
 *
 * 🔴 EVERY ASSERTION READS THE FINDING TEXT, NEVER THE COUNT ALONE. The point
 * of this change is that a refusal NAMES the move or the status at fault, so a
 * spec asserting only that publication was refused would pass on a guard that
 * refused in general, which is the guard this change replaces.
 *
 * 🔴 EVERY CASE TYPE HERE CARRIES AN `initialStatus`. `seedStateMachine` does
 * not set one, and the walk deliberately says nothing when the initial status
 * is not one of the type's own: `validate()` already refuses that in its own
 * words. So a spec that skipped this step would see the orphan and closure
 * findings suppressed and would pass with the walk switched off.
 *
 * 🔴 THE TRANSITIONS FIELD IS A JSON STRING ON THE LIVE SCHEMA, not an array.
 * A fixture that wrote an array would be stored as something the reader cannot
 * decode, and every finding here would disappear.
 *
 * @spec openspec/changes/publishing-refuses-an-unreachable-lifecycle/specs/case-type-publish-validation/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	REGISTER,
	RUN_PREFIX,
	seedStateMachine,
	updateObject,
} from './helpers/fixtures.ts'
import { anonymousContext } from './helpers/principals.ts'
import { expectRefused, REFUSED_ANONYMOUS } from './helpers/refusals.ts'

/**
 * The admin-only route the Publish dialog reads its findings from.
 *
 * @param caseTypeId The case type under test.
 */
function validateUrl(caseTypeId: string): string {
	return `/index.php/apps/${REGISTER}/api/case-types/${caseTypeId}/publish/validate`
}

let api: APIRequestContext
let token: string

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

/**
 * The findings the publish dialog would show for one case type.
 *
 * @param caseTypeId The case type under test.
 */
async function findings(caseTypeId: string): Promise<string[]> {
	const res = await api.get(validateUrl(caseTypeId), {
		headers: { requesttoken: token },
	})
	expect(
		res.ok(),
		`validate ${caseTypeId} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()

	return (body.findings ?? []) as string[]
}

/**
 * A seeded machine whose case type starts its cases in `Ontvangen`.
 *
 * Seeding it as a draft too, because publication is what is under test and a
 * type already published has nothing left to refuse.
 */
async function machine() {
	const seeded = await seedStateMachine(api, token)
	await updateObject(api, token, 'caseType', seeded.caseTypeId, {
		isDraft: true,
		initialStatus: seeded.statusReceived,
	})

	return seeded
}

/**
 * Replace a machine's stored moves.
 *
 * @param templateId  The workflow template holding the moves.
 * @param transitions The moves to store.
 */
async function setMoves(
	templateId: string,
	transitions: Array<Record<string, unknown>>,
): Promise<void> {
	await updateObject(api, token, 'workflowTemplate', templateId, {
		transitions: JSON.stringify(transitions),
	})
}

/**
 * The seeded machine's workflow template id.
 *
 * @param created The machine's created list, child-first.
 */
function templateOf(created: Array<[string, string]>): string {
	const entry = created.find(([schema]) => schema === 'workflowTemplate')
	expect(entry, 'the machine seeded no workflowTemplate').toBeTruthy()

	return (entry as [string, string])[1]
}

test.describe('publishing refuses an unreachable lifecycle', () => {
	test('a sound lifecycle publishes with nothing said', async () => {
		const m = await machine()

		const found = await findings(m.caseTypeId)

		// The CONTROL. Without it every refusal below would also pass on a
		// guard that refused every case type on the instance.
		expect(
			found.filter((f) => f.includes('move') || f.includes('status "')),
			`a sound lifecycle raised: ${found.join(' | ')}`,
		).toEqual([])
	})

	test('a wildcard move is refused, and the refusal names it', async () => {
		const m = await machine()
		await setMoves(templateOf(m.created), [
			{
				id: 't1',
				label: `${RUN_PREFIX} Start behandeling`,
				fromStatus: m.statusReceived,
				toStatus: m.statusInProgress,
			},
			{
				id: 't2',
				label: `${RUN_PREFIX} Afhandelen`,
				fromStatus: m.statusInProgress,
				toStatus: m.statusDone,
			},
			{
				id: 't3',
				label: `${RUN_PREFIX} Intrekken`,
				fromStatus: '*',
				toStatus: m.statusDone,
			},
		])

		const found = await findings(m.caseTypeId)

		expect(found.join(' | ')).toContain(`${RUN_PREFIX} Intrekken`)
		expect(found.join(' | ')).toContain('starts from no status')
	})

	test('a move from a foreign status is refused, and the refusal names it', async () => {
		const m = await machine()
		const other = await machine()
		await setMoves(templateOf(m.created), [
			{
				id: 't1',
				label: `${RUN_PREFIX} Start behandeling`,
				fromStatus: m.statusReceived,
				toStatus: m.statusInProgress,
			},
			{
				id: 't2',
				label: `${RUN_PREFIX} Afhandelen`,
				fromStatus: m.statusInProgress,
				toStatus: m.statusDone,
			},
			{
				id: 't3',
				label: `${RUN_PREFIX} Heropenen`,
				fromStatus: other.statusDone,
				toStatus: m.statusInProgress,
			},
		])

		const found = await findings(m.caseTypeId)

		expect(found.join(' | ')).toContain(`${RUN_PREFIX} Heropenen`)
		expect(found.join(' | ')).toContain(
			'starts from a status this case type does not have',
		)
	})

	test('a status nothing leads to is refused by name', async () => {
		const m = await machine()
		await setMoves(templateOf(m.created), [
			{
				id: 't1',
				label: `${RUN_PREFIX} Afhandelen`,
				fromStatus: m.statusReceived,
				toStatus: m.statusDone,
			},
		])

		const found = await findings(m.caseTypeId)
		const orphan = found.filter((f) => f.includes('No move leads to the status'))

		expect(orphan).toHaveLength(1)
		expect(orphan[0]).toContain('In behandeling')
	})

	test('one broken move produces one finding, and it names the move', async () => {
		const m = await machine()
		await setMoves(templateOf(m.created), [
			{
				id: 't1',
				label: `${RUN_PREFIX} Start behandeling`,
				fromStatus: '*',
				toStatus: m.statusInProgress,
			},
			{
				id: 't2',
				label: `${RUN_PREFIX} Afhandelen`,
				fromStatus: m.statusReceived,
				toStatus: m.statusDone,
			},
		])

		const found = await findings(m.caseTypeId)
		const reachability = found.filter(
			(f) => f.includes('move') || f.includes('No move leads to the status'),
		)

		expect(
			reachability,
			`expected one finding, got: ${reachability.join(' | ')}`,
		).toHaveLength(1)
		expect(reachability[0]).toContain(`${RUN_PREFIX} Start behandeling`)
	})

	test('a lifecycle that can never close is refused', async () => {
		const m = await machine()
		await setMoves(templateOf(m.created), [
			{
				id: 't1',
				label: `${RUN_PREFIX} Start behandeling`,
				fromStatus: m.statusReceived,
				toStatus: m.statusInProgress,
			},
			{
				id: 't2',
				label: `${RUN_PREFIX} Terug naar de balie`,
				fromStatus: m.statusInProgress,
				toStatus: m.statusReceived,
			},
		])

		const found = await findings(m.caseTypeId)

		expect(found.join(' | ')).toContain('could never be finished')
		expect(found.join(' | ')).toContain('Ontvangen')
	})

	test('a case type with no moves publishes unchanged', async () => {
		const m = await machine()
		await setMoves(templateOf(m.created), [])

		const found = await findings(m.caseTypeId)

		expect(
			found.filter((f) => f.includes('move') || f.includes('status "')),
			`a case type with no moves raised: ${found.join(' | ')}`,
		).toEqual([])
	})

	test('an unauthenticated caller cannot read what is wrong with a case type', async ({
		playwright,
		baseURL,
	}) => {
		const m = await machine()

		// The least privileged principal that should be refused. The findings
		// enumerate what is misconfigured on every case type in the install, so
		// the route is admin-only and an anonymous caller must not read it.
		// The empty jar was already here and was already right. What was
		// missing is the other half: `res.ok() === false` is every 4xx AND
		// every 5xx, so this passed on the 500 a broken route answers and on
		// the 404 a typo in `validateUrl` answers. The refusal is now asserted
		// by its status and by the reason it names.
		const anonymous = await anonymousContext(playwright, String(baseURL))
		try {
			const res = await anonymous.get(validateUrl(m.caseTypeId))

			await expectRefused(
				res,
				REFUSED_ANONYMOUS,
				'an anonymous caller reading the lifecycle findings',
			)
		} finally {
			await anonymous.dispose()
		}
	})
})

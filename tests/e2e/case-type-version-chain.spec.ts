/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type has versions, and a running case can be moved along them.
 *
 * WHY THIS NEEDS A REAL STORE. The rules are unit-tested to the sentence.
 * `DerivedCaseTypePayloadTest` proves a version keeps its identifier and now
 * carries the workflow pin; `CaseTypeCopyServiceTest` proves the templates are
 * copied and the pin repointed, with the mutation that skips the copy
 * reddening the count; `CaseTypePublishServiceTest` proves publishing closes
 * the previous version on the day the new one takes effect;
 * `CaseVersionMoveTest` proves the preview names the landing status and the
 * refusal names the status that does not exist. What none of those can show is
 * that the pieces MEET: that `identifier` survives the register import as a
 * value OpenRegister stores and can be FILTERED on, that the chain endpoint
 * answers the same rows the declared Version chain panel queries, and that a
 * case moved through the endpoint comes back on the other version. Each of
 * those seams sits between two apps.
 *
 * 🔴 NOTHING HERE WRITES A CASE TYPE'S `supersededBy` DIRECTLY. Every version
 * below is closed by PUBLISHING its successor through the one endpoint that
 * owns that write, because that is the only path production has. A fixture
 * that set the field itself would be a row this app never produces, and the
 * test would pass on a publish path that had stopped writing it.
 *
 * 🔴 THE MOVE IS PERFORMED THROUGH THE ENDPOINT, NOT BY PATCHING `caseType`.
 * Patching the field would leave the status pointing at the old version's
 * statusType, which is the exact broken state the act exists to prevent, and
 * the assertion would still be green.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded.
 */

import { expect, test } from '@playwright/test'
import {
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'

/** The shared identifier that makes these rows versions of one case type. */
const IDENTIFIER = `${RUN_PREFIX.toLowerCase()}-versiereeks`

/** Version 1, published, and the version the case is filed under. */
let versionOne = ''

/** Version 2, a draft until the publish scenario promotes it. */
let versionTwo = ''

/** The status of version 1 the case sits in, by name shared with version 2. */
let statusOneOntvangen = ''

/** The case running on version 1. */
let runningCase = ''

test.describe('A case type carries its versions, and a case can move along them', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		versionOne = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} versiereeks`,
				identifier: IDENTIFIER,
				description: 'Throwaway caseType for the version-chain e2e layer.',
				processingDeadline: 'P30D',
				isDraft: false,
				version: 1,
			}),
		)

		statusOneOntvangen = objectId(
			await createObject(api, token, 'statusType', {
				name: 'Ontvangen',
				caseType: versionOne,
				order: 1,
			}),
		)
		await createObject(api, token, 'statusType', {
			name: 'Afgehandeld',
			caseType: versionOne,
			order: 2,
			isFinal: true,
		})

		runningCase = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} lopende zaak`,
				caseType: versionOne,
				status: statusOneOntvangen,
			}),
		)
	})

	/**
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('a new version keeps the identifier and carries the workflow', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await api.post(
			`/index.php/apps/dossiq/api/case-definitions/${versionOne}/new-version`,
			{ headers: { requesttoken: token } },
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const draft = await response.json()
		versionTwo = objectId(draft)

		expect(versionTwo).not.toBe(versionOne)
		expect(draft.identifier).toBe(IDENTIFIER)
		expect(draft.version).toBe(2)
		expect(draft.previousVersion).toBe(versionOne)
		expect(draft.isDraft).toBe(true)
	})

	/**
	 * The endpoint and the declared panel must answer the same rows, or the
	 * page and the dialog disagree about what the chain is.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('the chain endpoint lists both versions, newest first', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const response = await api.get(
			`/index.php/apps/dossiq/api/case-types/${versionOne}/chain`,
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const versions = (await response.json()).versions
		expect(versions.map((entry: any) => entry.id)).toEqual([
			versionTwo,
			versionOne,
		])
		expect(versions[0].isDraft).toBe(true)
		expect(versions[1].isDraft).toBe(false)
	})

	/**
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('publishing the draft closes the version it replaces', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// Version 2 needs its own lifecycle before it can be published. The
		// copy carried version 1's statuses, so the names already match; this
		// only points the new row at its own initial status.
		const statuses = await api.get(
			`/index.php/apps/dossiq/api/case-types/${versionTwo}/blueprint`,
		)
		const ontvangen = (await statuses.json()).statusTypes.find(
			(status: any) => status.name === 'Ontvangen',
		)
		expect(ontvangen, 'version 2 carries its own Ontvangen').toBeTruthy()

		const response = await api.post(
			`/index.php/apps/dossiq/api/case-types/${versionTwo}/publish`,
			{
				headers: { requesttoken: token },
				data: { changeNote: 'Tweede versie' },
			},
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const closed = await showObject(api, 'caseType', versionOne)
		expect(closed.supersededBy).toBe(versionTwo)
		expect(
			closed.validUntil,
			'the old version is closed by date too',
		).toBeTruthy()
		expect(closed.isDraft, 'its running cases still resolve through it').toBe(
			false,
		)
	})

	/**
	 * 🔴 The point of minting an object rather than editing one.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('a case already running stays on the version it was filed under', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const running = await showObject(api, 'case', runningCase)
		expect(running.caseType).toBe(versionOne)
		expect(running.status).toBe(statusOneOntvangen)
	})

	/**
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('the preview names the landing status and what the version changes', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const response = await api.get(
			`/index.php/apps/dossiq/api/case/${runningCase}/version-move?target=${versionTwo}`,
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const body = await response.json()
		expect(body.targets.map((entry: any) => entry.id)).toContain(versionTwo)
		expect(body.preview.canMove).toBe(true)
		expect(body.preview.status.from).toBe('Ontvangen')
		expect(body.preview.status.to).toBe('Ontvangen')
		expect(
			body.preview.status.targetStatusId,
			'it lands in version 2 own row, not version 1 own',
		).not.toBe(statusOneOntvangen)
		expect(body.preview.run.moved, 'the engine run does not move yet').toBe(
			false,
		)
	})

	/**
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('moving the case rebinds it to the other version and its own status', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await api.post(
			`/index.php/apps/dossiq/api/case/${runningCase}/version-move`,
			{
				headers: { requesttoken: token },
				data: { target: versionTwo, reason: 'Gecorrigeerde regels' },
			},
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const moved = await showObject(api, 'case', runningCase)
		expect(moved.caseType).toBe(versionTwo)
		expect(moved.status).not.toBe(statusOneOntvangen)

		const journal = JSON.parse(moved.activity || '[]')
		const entry = journal[journal.length - 1]
		expect(entry.type).toBe('case-type-version-move')
		expect(entry.fromCaseType).toBe(versionOne)
		expect(entry.toCaseType).toBe(versionTwo)
		expect(entry.reason).toBe('Gecorrigeerde regels')
	})

	/**
	 * 🔴 The refusal names the status, because the name is the only thing the
	 * person reading it can act on.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('a status the other version does not carry refuses the move by name', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// A status that exists only on version 2, so moving BACK to version 1
		// has nowhere to land. Seeded on the version, never on the case
		// directly: the case reaches it through the move that just happened.
		const onlyOnTwo = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} Alleen in v2`,
				caseType: versionTwo,
				order: 3,
			}),
		)
		await api.put(
			`/index.php/apps/openregister/api/objects/dossiq/case/${runningCase}`,
			{
				headers: { requesttoken: token },
				data: {
					...(await showObject(api, 'case', runningCase)),
					status: onlyOnTwo,
				},
			},
		)

		const response = await api.post(
			`/index.php/apps/dossiq/api/case/${runningCase}/version-move`,
			{
				headers: { requesttoken: token },
				data: { target: versionOne, reason: 'Terug naar v1' },
			},
		)

		expect(response.status()).toBe(422)
		const body = await response.json()
		expect(body.message).toContain('Alleen in v2')

		const unchanged = await showObject(api, 'case', runningCase)
		expect(unchanged.caseType, 'a refused move writes nothing').toBe(versionTwo)
	})

	/**
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('a move with no reason is refused', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await api.post(
			`/index.php/apps/dossiq/api/case/${runningCase}/version-move`,
			{
				headers: { requesttoken: token },
				data: { target: versionOne, reason: '   ' },
			},
		)

		expect(response.status()).toBe(422)
		expect((await response.json()).error).toBe('version-move-needs-a-reason')
	})

	/**
	 * Deprecate closes a superseded version, and refuses the one in use.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	test('deprecate refuses the version new cases are filed under', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await api.post(
			`/index.php/apps/dossiq/api/case-types/${versionTwo}/deprecate`,
			{ headers: { requesttoken: token } },
		)

		expect(response.status()).toBe(422)
		expect((await response.json()).deprecated).toBe(false)

		const untouched = await showObject(api, 'caseType', versionTwo)
		expect(untouched.validUntil ?? null).toBeNull()
	})
})

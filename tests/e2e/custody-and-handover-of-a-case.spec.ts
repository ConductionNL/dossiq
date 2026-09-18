/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who held the case, who asked for it, and what it takes to hand it outside.
 *
 * 🔴 THE CHAIN IS ASSERTED AS A JOIN, NEVER AS A ROW. A holding on its own says
 * nothing: the failure this change exists to prevent is a chain with a GAP, and
 * a gap only shows between two holdings. So every custody assertion here reads
 * the moment one holding ended against the moment the next began, and counts
 * the open ones. A spec that asserted "the new holding names Toezicht" would
 * pass on a chain that left the case held by two units at once.
 *
 * 🔴 THE CONSENT REFUSAL IS PROBED WITH A HAND-OFF THAT SHOULD FAIL, FIRST.
 * The order matters: recording the consent and then watching the hand-off
 * succeed proves only that the endpoint works. The refusal before it is what
 * says the gate is wired at all, and the two together are what say the gate is
 * the thing that changed the answer.
 *
 * 🔴 EVERY FIXTURE IS ADDRESSED BY THIS RUN'S PREFIX. The instance is shared
 * and another session's rows land beside these while this one runs, so nothing
 * here counts a list or reads a position.
 *
 * ⚠️ NOT RUN IN THIS PHASE. The integration branch defers Playwright to the
 * nightly (see the build-lane brief); this spec is written, tagged and left for
 * it. The same scenarios are watched against a real in-memory register by
 * CaseCustodyChainTest, CaseTakeoverAnswerTest and PartnerShareScopeTest, which
 * is where a regression would be caught before this file next runs.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from './helpers/fixtures.ts'

/**
 * The url of one act on one case.
 *
 * @param caseId The case uuid.
 * @param verb The path segment after `/api/case/{id}/`.
 */
function caseApi(caseId: string, verb: string): string {
	return `/index.php/apps/${REGISTER}/api/case/${caseId}/${verb}`
}

let api: APIRequestContext
let token: string
let caseTypeId = ''

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
 * Seed one case held by a named unit.
 *
 * @param unit The organisation unit that holds it.
 * @param title A short title, prefixed with this run's marker by seedCase.
 */
async function heldCase(unit: string, title: string): Promise<string> {
	const created = await seedCase(api, token, {
		title: `${RUN_PREFIX} ${title}`,
		caseType: caseTypeId,
		assignedGroup: unit,
	})
	return objectId(created)
}

test.describe('@spec REQ-CUS-01 the chain of custody', () => {
	test('a transfer closes one holding and opens the next', async () => {
		const caseId = await heldCase('vergunningen', 'custody chain')

		const handed = await api.post(caseApi(caseId, 'handover'), {
			headers: { requesttoken: token },
			data: { team: 'toezicht', reason: 'Dit is handhaving, geen vergunning' },
		})
		expect(
			handed.ok(),
			`handover -> ${handed.status()} ${await handed.text()}`,
		).toBeTruthy()

		const read = await api.get(caseApi(caseId, 'custody'))
		expect(read.ok()).toBeTruthy()
		const chain = await read.json()

		expect(
			chain.holdings.length,
			'A move adds a holding; it does not rewrite the one that was open.',
		).toBe(2)

		const [first, second] = chain.holdings
		expect(
			first.until,
			'The moment the first holding ends is the moment the second begins, or the chain has a gap.',
		).toBe(second.from)
		expect(first.open).toBe(false)
		expect(second.open).toBe(true)
		expect(second.organisationUnit).toBe('toezicht')
		expect(
			second.reason,
			'The reason for the move and the person who made it are on the new holding.',
		).toBe('Dit is handhaving, geen vergunning')

		const open = chain.holdings.filter((h: { open: boolean }) => h.open)
		expect(open.length, 'A case is held by exactly one unit, never two and never none.').toBe(1)
	})

	test('who held the case on a date is a single answer', async () => {
		const caseId = await heldCase('vergunningen', 'custody by date')

		await api.post(caseApi(caseId, 'handover'), {
			headers: { requesttoken: token },
			data: { team: 'toezicht', reason: 'Handhaving' },
		})

		const read = await api.get(caseApi(caseId, 'custody'))
		const chain = await read.json()
		const openedAt = chain.holdings[1].from

		const holder = await api.get(`${caseApi(caseId, 'custody/holder')}?on=${encodeURIComponent(openedAt)}`)
		expect(holder.ok()).toBeTruthy()
		const answer = await holder.json()

		expect(
			answer.holding.organisationUnit,
			'On the day of a transfer the case belongs to the unit that TOOK it, or the day reads as two units.',
		).toBe('toezicht')
	})
})

test.describe('@spec REQ-CUS-02 asking the holder for a case', () => {
	test('the holder refuses with a reason and keeps the case', async () => {
		const caseId = await heldCase('vergunningen', 'takeover refused')

		const asked = await api.post(caseApi(caseId, 'takeover'), {
			headers: { requesttoken: token },
			data: { reason: 'Dit dossier hoort bij mijn wijk' },
		})
		expect(asked.ok(), `takeover -> ${asked.status()} ${await asked.text()}`).toBeTruthy()
		const request = await asked.json()
		expect(request.status).toBe('pending')

		const refused = await api.post(caseApi(caseId, `takeover/${objectId(request)}/refuse`), {
			headers: { requesttoken: token },
			data: { reason: 'Ik ben er al mee bezig, de hoorzitting is volgende week' },
		})
		expect(refused.ok()).toBeTruthy()
		const answered = await refused.json()

		expect(answered.status).toBe('refused')
		expect(
			answered.refusalReason,
			'A refusal is only an answer when it says why, and the reason has to be readable on the case.',
		).toBe('Ik ben er al mee bezig, de hoorzitting is volgende week')

		const onCase = await api.get(caseApi(caseId, 'takeovers'))
		const listed = await onCase.json()
		expect(
			listed.requests.map((r: { status: string }) => r.status),
			'The answer is on the case whichever way it went.',
		).toContain('refused')

		const custody = await (await api.get(caseApi(caseId, 'custody'))).json()
		expect(custody.holdings.length, 'A refusal writes no holding, because nothing moved.').toBe(1)
	})

	test('a refusal without a reason is not an answer', async () => {
		const caseId = await heldCase('vergunningen', 'takeover no reason')

		const asked = await api.post(caseApi(caseId, 'takeover'), {
			headers: { requesttoken: token },
			data: { reason: 'Mijn wijk' },
		})
		const request = await asked.json()

		const refused = await api.post(caseApi(caseId, `takeover/${objectId(request)}/refuse`), {
			headers: { requesttoken: token },
			data: { reason: '   ' },
		})

		expect(refused.status(), 'An empty reason is refused, not recorded as a silent no.').toBe(400)
	})
})

test.describe('@spec REQ-CST-01 the consent a case needs to leave', () => {
	test('a case cannot reach a partner organisation without a recorded consent', async () => {
		const caseId = await heldCase('wijkteam', 'consent gate')
		const partner = `${RUN_PREFIX}-zorgpartner`

		const refused = await api.post(`/index.php/apps/${REGISTER}/api/shares`, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'partner',
				partnerId: partner,
				permissionLevel: 'read',
			},
		})

		expect(
			refused.status(),
			'A refusal is this instance deciding, not an upstream failure, so it is a 409 and not a 502.',
		).toBe(409)

		const refusal = await refused.json()
		expect(
			refusal.error ?? '',
			'The refusal says that consent for THIS receiver is missing, not that something went wrong.',
		).toContain(partner)
		expect(refusal.rule).toBe('consent-missing')
	})

	test('the consent lets the case go, and the share carries the scope it names', async () => {
		const caseId = await heldCase('wijkteam', 'consent given')
		const partner = `${RUN_PREFIX}-zorgpartner-ok`

		await createObject(api, token, 'toestemming', {
			caseId,
			grantedByBsn: '999990627',
			grantedByName: `${RUN_PREFIX} Janssen`,
			grantedDate: '2026-01-10',
			validTo: '2099-12-31',
			withdrawn: false,
			recipientParties: [partner],
			tegegevens: ['ondersteuningsplan'],
			tedoel: 'Overdracht naar de zorgaanbieder',
		})

		const shared = await api.post(`/index.php/apps/${REGISTER}/api/shares`, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'partner',
				partnerId: partner,
				permissionLevel: 'read',
			},
		})
		expect(shared.ok(), `partner share -> ${shared.status()} ${await shared.text()}`).toBeTruthy()
		const { share } = await shared.json()

		expect(
			share.consentScope,
			'The partner sees only what the consent names, so the share has to carry it.',
		).toEqual(['ondersteuningsplan'])
		expect(share.consentUntil).toBe('2099-12-31')
		expect(
			share.consentId ?? '',
			'And the share names the consent it was allowed by, so the two cannot disagree later.',
		).not.toBe('')
	})
})

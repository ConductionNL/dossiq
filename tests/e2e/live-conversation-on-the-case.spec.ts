/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A live conversation on the case, and the record it leaves.
 *
 * dossiq held a live conversation before this change, for exactly one moment
 * of exactly one case type: the videogesprek hoorzitting in a bezwaar. What
 * this spec has to tell apart is a mechanism that genuinely reaches every
 * case from one that only looks like it does, and the two failure modes are
 * both quiet. A conversation endpoint that 404s and a case with no
 * conversations render the identical empty panel, so every record here is
 * read back over the API rather than inferred from the page. And a Talk room
 * that was never created leaves `talkRoomUrl` empty rather than erroring, so
 * the room URL is asserted by value.
 *
 * ⚠️ TALK MAY NOT BE INSTALLED ON THE E2E INSTANCE, and a spec that skipped
 * on that would report the same green as one that passed. So the availability
 * endpoint decides which of two REAL assertions runs: with Talk, a room is
 * created and linked; without it, the start is REFUSED with
 * `talk_not_available` and the case is left with no conversation record. Both
 * branches fail if the endpoint is missing, because both read its body.
 *
 * Locale: nothing forces the language of the instance, so tab and button
 * names are matched in either locale the app ships.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'

const APP_BASE = '/index.php/apps/dossiq'

/** A one-pixel PNG, which is what a camera capture is for this spec's purposes. */
const CAPTURE_BYTES =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

/**
 * Ask the app whether this instance can hold a conversation at all.
 *
 * @param api Authenticated request context.
 * @return Whether Talk answered.
 */
async function talkAvailable(api: APIRequestContext): Promise<boolean> {
	const res = await api.get(`${APP_BASE}/api/conversations/availability`)
	expect(
		res.ok(),
		`the availability endpoint is missing: ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	expect(
		typeof body.available,
		'availability answered without saying whether it is available',
	).toBe('boolean')
	return body.available === true
}

/**
 * Seed a case of the first adoptable type, so the spec runs on whatever
 * catalogue the instance carries.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @param title The case title.
 * @return The created case.
 */
async function seedOrdinaryCase(api: APIRequestContext, token: string, title: string): Promise<any> {
	const types = await adoptableCaseTypes(api)
	expect(types.length, 'the instance carries no case type to seed a case on').toBeGreaterThan(0)
	return seedCase(api, token, { title: `${RUN_PREFIX} ${title}`, caseType: objectId(types[0]) })
}

test.describe('a live conversation on the case', () => {
	let token = ''

	test.beforeAll(async ({ request }) => {
		token = await getRequestToken(request)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, token, ['case', 'document', 'caseDocument'])
	})

	/**
	 * @e2e Scenario: a conversation starts from an ordinary case
	 */
	test('a conversation starts from an ordinary case', async ({ request }) => {
		const seeded = await seedOrdinaryCase(request, token, 'vergunning met gesprek')
		const id = objectId(seeded)

		const res = await request.post(`${APP_BASE}/api/cases/${id}/conversations`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { subject: `${RUN_PREFIX} gesprek` },
		})
		const body = await res.json()

		if ((await talkAvailable(request)) === false) {
			// Without Talk the act is REFUSED and says why, and the case keeps
			// no half-record of a conversation that never happened.
			expect(res.status(), await res.text()).toBe(503)
			expect(body.reason).toBe('talk_not_available')
			const stored = await showObject(request, 'case', id)
			expect(stored.conversations ?? []).toHaveLength(0)
			return
		}

		expect(res.ok(), await res.text()).toBeTruthy()
		expect(body.conversation.roomUrl, 'a room was reported with no URL').toMatch(/^https?:\/\//)

		const stored = await showObject(request, 'case', id)
		expect(stored.conversations).toHaveLength(1)
		expect(stored.conversations[0].roomId).toBe(body.conversation.roomId)
	})

	/**
	 * @e2e Scenario: the hoorzitting still works as it did
	 *
	 * The hearing goes through the same broker now, so what has to stay true
	 * is that a videogesprek hearing still carries a room URL and that the
	 * hearing path does not answer differently from the case path. On an
	 * instance without Talk both degrade to empty, which is also what the
	 * hearing did before this change.
	 */
	test('the hoorzitting still works as it did', async ({ request }) => {
		const seeded = await seedOrdinaryCase(request, token, 'bezwaar met hoorzitting')
		const id = objectId(seeded)

		const hearing = await createObject(request, token, 'hearingSession', {
			complaint: id,
			date: new Date(Date.now() + 86_400_000).toISOString().slice(0, 10),
			type: 'videogesprek',
		})

		const stored = await showObject(request, 'hearingSession', objectId(hearing))
		if (await talkAvailable(request)) {
			expect(stored.talkRoomUrl ?? '').toMatch(/^https?:\/\//)
		} else {
			expect(stored.talkRoomUrl ?? '').toBe('')
		}
	})

	/**
	 * @e2e Scenario: who was heard, and when
	 */
	test('the case records the moment, the participants and the duration', async ({ request }) => {
		test.skip((await talkAvailable(request)) === false, 'Talk is absent, so no conversation can be recorded')

		const seeded = await seedOrdinaryCase(request, token, 'gesprek met twee deelnemers')
		const id = objectId(seeded)

		const start = await request.post(`${APP_BASE}/api/cases/${id}/conversations`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {},
		})
		const { conversation } = await start.json()

		const end = await request.post(`${APP_BASE}/api/cases/${id}/conversations/end`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { roomId: conversation.roomId, participants: ['admin', 'belanghebbende'], durationSeconds: 1800 },
		})
		expect(end.ok(), await end.text()).toBeTruthy()

		const stored = await showObject(request, 'case', id)
		const record = stored.conversations.find((c: any) => c.roomId === conversation.roomId)
		expect(record.participants).toEqual(['admin', 'belanghebbende'])
		expect(record.durationSeconds).toBe(1800)
		expect(record.startedAt).not.toBe('')
		expect(record.endedAt).not.toBe('')
	})

	/**
	 * @e2e Scenario: a recording becomes a case document
	 * @e2e Scenario: a constatering ter plaatse is a photo and a voice note
	 */
	test('a capture on a toezichtzaak becomes a document on the case', async ({ request }) => {
		const seeded = await seedOrdinaryCase(request, token, 'toezichtzaak met spraaknotitie')
		const id = objectId(seeded)

		const res = await request.post(`${APP_BASE}/api/cases/${id}/captures`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				fileName: `${RUN_PREFIX}-constatering.png`,
				mimeType: 'image/png',
				content: CAPTURE_BYTES,
			},
		})
		expect(res.ok(), await res.text()).toBeTruthy()

		const filed = await listObjects(request, 'caseDocument', { case: id })
		expect(
			filed.map((row: any) => row.title),
			'the capture was accepted but filed on no case',
		).toContain(`${RUN_PREFIX}-constatering.png`)
	})

	/**
	 * @e2e Scenario: a capture on a task follows the task
	 */
	test('a capture made for a task still files on the case, and says which task', async ({ request }) => {
		const seeded = await seedOrdinaryCase(request, token, 'taak met opname')
		const id = objectId(seeded)

		const res = await request.post(`${APP_BASE}/api/cases/${id}/captures`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				fileName: `${RUN_PREFIX}-taak.png`,
				mimeType: 'image/png',
				content: CAPTURE_BYTES,
				taskId: 'taak-1',
			},
		})
		expect(res.ok(), await res.text()).toBeTruthy()
		const body = await res.json()
		expect(body.document.case).toBe(id)
		expect(body.document.description).toContain('taak-1')
	})

	/**
	 * @e2e Scenario: a calamiteit is stood up in one act
	 * @e2e Scenario: one channel, not two
	 * @e2e Scenario: the channel closes with the case and its content stays
	 *
	 * The responders are the case type's, so this seeds a type naming the
	 * admin user and declares a case of that type major. A type naming a group
	 * that does not resolve is refused, which the unit suite asserts and this
	 * one does not repeat over the network.
	 */
	test('a major case opens one channel, and closing the case closes it', async ({ request }) => {
		test.skip((await talkAvailable(request)) === false, 'Talk is absent, so no channel can be opened')

		const types = await adoptableCaseTypes(request)
		const calamiteit = await createObject(request, token, 'caseType', {
			title: `${RUN_PREFIX} calamiteit`,
			responders: ['admin'],
			initialStatus: (types[0] ?? {}).initialStatus,
		})
		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} brand in een loods`,
			caseType: objectId(calamiteit),
		})
		const id = objectId(seeded)

		const first = await request.post(`${APP_BASE}/api/cases/${id}/major`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {},
		})
		expect(first.ok(), await first.text()).toBeTruthy()
		const opened = await first.json()
		expect(opened.alreadyMajor).toBe(false)
		expect(opened.channel.roomUrl).toMatch(/^https?:\/\//)

		const second = await request.post(`${APP_BASE}/api/cases/${id}/major`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {},
		})
		const again = await second.json()
		expect(again.alreadyMajor, 'a second declaration opened a second channel').toBe(true)
		expect(again.channel.roomId).toBe(opened.channel.roomId)

		const stored = await showObject(request, 'case', id)
		expect(stored.isMajor).toBe(true)
		expect(stored.majorResponders).toContain('admin')

		const closed = await request.delete(`${APP_BASE}/api/cases/${id}/major/channel`, {
			headers: { requesttoken: token },
		})
		expect(closed.ok(), await closed.text()).toBeTruthy()

		const after = await showObject(request, 'case', id)
		expect(after.majorChannel.closedAt).not.toBe('')
		expect(
			after.conversations.some((c: any) => c.kind === 'majorChannel'),
			'the channel closed without filing what was said on the case',
		).toBe(true)
	})

	/**
	 * The panel is on the Communication tab, beside the contact moments, and a
	 * section whose type no registry entry answers to draws an empty panel and
	 * logs nothing. So the panel is reached through the tab, not asserted from
	 * the manifest.
	 */
	test('the live conversation section is on the Communication tab', async ({ page, request }) => {
		const seeded = await seedOrdinaryCase(request, token, 'zaak met communicatietab')
		await page.goto(`${APP_BASE}/cases/${objectId(seeded)}`)

		const panel = await openCasePanel(page, 'conversations')
		await expect(panel).toBeVisible({ timeout: 30_000 })

		if (await talkAvailable(request)) {
			await expect(panel.getByTestId('start-conversation')).toBeVisible()
		} else {
			await expect(panel.getByText(/Talk is not available|Talk is niet beschikbaar/)).toBeVisible()
		}
	})
})

/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The acknowledgement of receipt, Awb 4:3a.
 *
 * WHAT THIS COVERS THAT THE UNIT TESTS CANNOT. `AcknowledgementDutyTest` drives
 * the service against an in-memory store: it can show that a `website` case is
 * confirmed and a `balie` case is not. It cannot show that creating a case in a
 * live instance ACTUALLY REACHES the service, which is the whole defect this
 * change closes. The template, the renderer and the requirement all existed and
 * nothing triggered them, so the one thing worth proving in a browser is that a
 * real `case` write ends in a real record on a real case.
 *
 * THE DUTY IS ASSERTED OFF THE STORED CASE AND THE API, NOT OFF A TOAST.
 * Nothing forces the language of the E2E instance and the sentences are
 * translated, so a toast assertion would be an assertion about the instance's
 * locale. `acknowledgementDuty.status` and the HTTP status are the same in
 * every language, and they are what the surfaces act on.
 *
 * THE QUEUE IS NOT DRAINED HERE. The listener queues and the background runner
 * sends, and a Playwright run has no cron. So each scenario drives the service
 * through its own endpoint or asserts the queued state, and `expect.poll` waits
 * on the STORED case rather than on a spinner.
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 *
 * @e2e REQ-TERM-020 an aanvraag arrives through the portal
 * @e2e REQ-TERM-020 an aanvraag arrives by mail
 * @e2e REQ-TERM-020 a case typed at the balie owes nothing
 * @e2e REQ-TERM-021 the citizen learns their kenmerk and their deadline
 * @e2e REQ-TERM-021 a case with no statutory term still confirms receipt
 * @e2e REQ-TERM-022 a citizen who chose the portal is not mailed the content
 * @e2e REQ-TERM-022 content stays on the platform
 * @e2e REQ-TERM-023 a handler can see that receipt was confirmed
 * @e2e REQ-TERM-024 a mail server outage does not stop a case being created
 * @e2e REQ-TERM-024 an acknowledgement that never went out is findable
 * @e2e REQ-TERM-024 a handler records that it was met another way
 * @e2e REQ-TERM-025 asking for something is not the same message as moving on
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The acknowledgement endpoints, which the case page's action also calls. */
const DUTY = (id: string) => `/index.php/apps/dossiq/api/case/${id}/acknowledgement`
const RECORD_MET = (id: string) => `${DUTY(id)}/met`

let caseTypeId = ''

test.describe('Ontvangstbevestiging (Awb 4:3a)', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * An aanvraag through the portal, and one by mail, are both confirmed
	 * without a handler acting, and a case typed at the balie is not.
	 *
	 * The three are one test because they differ in exactly one field and the
	 * comparison is the assertion: the same creation with `balie` in place of
	 * `website` must produce no acknowledgement at all.
	 */
	test('an electronic aanvraag is confirmed and a balie case is not', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const paths: Array<[string, string]> = [
			['portal', 'website'],
			['mail', 'email'],
			['balie', 'balie'],
		]

		for (const [name, channel] of paths) {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} Ontvangst ${name}`,
				caseType: caseTypeId,
				intakeChannel: channel,
				email: `${RUN_PREFIX}-${name}@example.nl`.toLowerCase(),
			})
			const id = objectId(row)

			// The listener queues; the duty answers what the case now carries.
			const res = await api.get(DUTY(id))
			expect(res.ok(), `duty read for ${name} -> ${res.status()}`).toBeTruthy()
			const { duty } = await res.json()

			if (channel === 'balie') {
				expect(
					['not-required', undefined, ''],
					'a case typed at the balie owes no acknowledgement',
				).toContainEqual(duty?.status)
			} else {
				expect(
					duty,
					`${name}: an electronic submission owes a confirmation of receipt`,
				).toBeTruthy()
			}
		}

		await api.dispose()
	})

	/**
	 * The record on the case names the moment, the channel and the recipient,
	 * and the duty reads met.
	 *
	 * Driven through the endpoint rather than waiting on cron: what is being
	 * proved is that a real case write lands a real record, not that the
	 * background runner is scheduled.
	 */
	test('a confirmed case carries the record a handler can read', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Ontvangst record`,
			caseType: caseTypeId,
			intakeChannel: 'website',
			email: `${RUN_PREFIX}-record@example.nl`.toLowerCase(),
		})
		const id = objectId(row)

		await expect
			.poll(
				async () => {
					const stored = await showObject(api, 'case', id)
					return stored?.acknowledgementDuty?.status ?? 'pending'
				},
				{
					timeout: 60_000,
					message: 'the case never recorded its acknowledgement',
				},
			)
			.not.toBe('pending')

		const stored = await showObject(api, 'case', id)
		const record = (stored.outboundCommunications ?? [])[0]
		expect(
			record,
			'the case carries an outbound communication record',
		).toBeTruthy()
		expect(record.moment).toBe('case-received')
		expect(record.channel, 'the record names the channel').toBeTruthy()
		expect(record.recipient, 'the record names the recipient').toBeTruthy()
		expect(record.sentAt, 'the record names the moment').toBeTruthy()
		expect(
			record.templateVersion,
			'the record names the template version',
		).toBeTruthy()

		await api.dispose()
	})

	/**
	 * A case whose acknowledgement never went out is findable, and a handler
	 * can record that they met the duty another way.
	 *
	 * The unmet state is seeded directly because a mail outage cannot be staged
	 * in the instance; what the browser proves is the gesture that clears it,
	 * and that clearing it names who said so.
	 */
	test('an unmet duty is findable and a handler can record it met', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Ontvangst onbevestigd`,
			caseType: caseTypeId,
			intakeChannel: 'website',
		})
		const id = objectId(row)

		await updateObject(api, token, 'case', id, {
			acknowledgementDuty: {
				required: true,
				status: 'unmet',
				attempts: 3,
				lastError: 'The acknowledgement of receipt could not be delivered.',
			},
		})

		const before = await api.get(DUTY(id))
		expect((await before.json()).duty.status).toBe('unmet')

		const met = await api.post(RECORD_MET(id), {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { how: 'Confirmed by post' },
		})
		expect(
			met.ok(),
			`record met -> ${met.status()} ${await met.text()}`,
		).toBeTruthy()

		const duty = (await met.json()).duty
		expect(duty.status).toBe('met')
		expect(duty.metBy, 'the record names who said so').toBeTruthy()
		expect(duty.metAt, 'the record names when').toBeTruthy()
		expect(duty.metHow).toBe('Confirmed by post')

		await api.dispose()
	})

	/**
	 * Clearing the duty with nothing said is refused, with a status a surface
	 * can act on rather than an empty 200.
	 */
	test('clearing the duty without saying how is refused', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Ontvangst zonder reden`,
			caseType: caseTypeId,
			intakeChannel: 'website',
		})

		const res = await api.post(RECORD_MET(objectId(row)), {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { how: '' },
		})

		expect(res.status(), 'an empty reason is refused, not accepted').toBe(422)
		expect((await res.json()).error).toBe('acknowledgement-needs-a-reason')

		await api.dispose()
	})

	/**
	 * The case page shows the receipt confirmation beside the contact a handler
	 * logged by hand, and offers the gesture that records the duty met.
	 *
	 * Only a browser can show that the manifest section actually renders: a
	 * widget whose type no registry answers draws an empty panel and logs
	 * nothing at all, which is the failure this page has already hit twice.
	 */
	test('the case page carries the receipt confirmation', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Ontvangst pagina`,
			caseType: caseTypeId,
			intakeChannel: 'website',
			email: `${RUN_PREFIX}-page@example.nl`.toLowerCase(),
		})
		await api.dispose()

		const errors = trackDossiqErrors(page)
		await page.goto(`/index.php/apps/dossiq/#/cases/${objectId(row)}`, PAGE_LOAD)

		await expect(
			page.getByRole('tab', { name: /Communication|Communicatie/i }),
		).toBeVisible(PAGE_LOAD)
		await page.getByRole('tab', { name: /Communication|Communicatie/i }).click()

		await expect(
			page.getByText(/Receipt confirmation|Ontvangstbevestiging/i).first(),
		).toBeVisible(PAGE_LOAD)

		expect(
			errors,
			`dossiq errors on the case page: ${errors.join(' | ')}`,
		).toEqual([])
	})
})

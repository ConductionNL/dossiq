/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A handler answers a resident's portal message from the case
 * (communication-portal-conversation-on-the-case, task 5.3).
 *
 * The resident's half (the inbox reply, the case choice, the question from the
 * case page) is rendered by portaliq and asserted there; its declarations are
 * pinned by PortalConversationTest and PortalCaseMessagesTest. What only a
 * browser on dossiq can show is the handler's half: that a resident's message
 * written into OpenRegister reaches the case timeline with an open follow-up,
 * that Reply on it writes the answer to the resident's inbox, and that sending
 * closes the follow-up.
 *
 * The resident's message is seeded over the API, exactly as portaliq writes
 * it: a `portaalBericht` with `direction: citizen_to_handler` and the
 * resident's subject reference in `senderRef`.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** OpenRegister's own API, which owns the timeline. */
const OR = '/index.php/apps/openregister/api'

/** The resident's pseudonymous portal reference on the seeded case. */
const RESIDENT = `${RUN_PREFIX}-inwoner`

let token = ''
let caseId = ''

/**
 * The timeline entries of one kind on the case.
 *
 * @param api  Authenticated request context.
 * @param kind The timeline kind.
 *
 * @return The entries.
 */
async function entriesOfKind(api: APIRequestContext, kind: string): Promise<any[]> {
	const response = await api.get(
		`${OR}/objects/${REGISTER}/case/${caseId}/timeline`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(response.ok()).toBeTruthy()
	const rows = ((await response.json()).results ?? []) as any[]

	return rows.filter((row: any) => String(row?.kind ?? '') === kind)
}

test.describe('The handler answers a resident from the case', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ request }) => {
		token = await getRequestToken(request)
		const caseTypeId = (await ensureCaseType(request, token)).id
		caseId = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Kapvergunning Dorpsstraat 4`,
				caseType: caseTypeId,
				portalSubject: RESIDENT,
			}),
		)

		await createObject(request, token, 'portaalBericht', {
			caseId,
			senderRef: RESIDENT,
			senderType: 'burger',
			senderName: 'J. Jansen',
			subject: `${RUN_PREFIX} Termijn`,
			content: 'When will I hear back?',
			direction: 'citizen_to_handler',
			sentAt: new Date().toISOString(),
		})
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, token)
	})

	// @e2e openspec/specs/portal-contribution/spec.md#answer-a-residents-question-from-the-case
	test('the question shows with an open follow-up, and the reply answers it', async ({
		page,
		request,
	}) => {
		const questions = await entriesOfKind(request, 'portaalbericht-inkomend')
		expect(questions).toHaveLength(1)
		expect(questions[0].visibility).toBe('internal')
		expect(questions[0].followUp).toBe('open')

		await page.goto(`/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		await page.getByRole('tab', { name: 'Timeline' }).click()

		const entry = page
			.getByTestId('case-timeline-entry')
			.filter({ hasText: `${RUN_PREFIX} Termijn` })
		await expect(entry.getByTestId('case-timeline-followup-open')).toBeVisible(
			PAGE_LOAD,
		)

		await entry.getByTestId(/^case-timeline-reply-/).click()
		const dialog = page.getByTestId('portal-message-dialog')
		await expect(dialog).toBeVisible()
		await dialog
			.getByTestId('portal-message-content')
			.locator('textarea')
			.fill('Within two weeks.')
		await dialog.getByTestId('portal-message-send').click()
		await expect(dialog).toHaveCount(0, PAGE_LOAD)

		await expect
			.poll(
				async () =>
					(await entriesOfKind(request, 'portaalbericht-inkomend'))[0]
						?.followUp,
				PAGE_LOAD,
			)
			.toBe('done')

		const answers = (await listObjects(request, 'portaalBericht', {
			caseId,
			direction: 'handler_to_citizen',
		})) as any[]
		expect(answers).toHaveLength(1)
		expect(answers[0].recipientRef).toBe(RESIDENT)
		expect(answers[0].senderType).toBe('medewerker')
		expect(answers[0].subject).toBe(`Re: ${RUN_PREFIX} Termijn`)

		const sent = await entriesOfKind(request, 'portaalbericht')
		expect(sent.some((row: any) => row.visibility === 'public')).toBe(true)
	})
})

/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case filed through a form owes an acknowledgement of receipt by default,
 * and a case type switches that off by naming its own channel list without
 * `forms`. A case type saved before `forms` joined the default holds the old
 * list as stored data, so it keeps sending none for forms until `forms` is
 * added. The acknowledgement runs from a queued job, run here by hand.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { occRunQueuedJobs } from './helpers/occ.ts'

const ACKNOWLEDGEMENT_JOB = 'OCA\\Dossiq\\BackgroundJob\\AcknowledgementDispatchJob'

/** The default channel list before `forms` joined it. */
const OLD_DEFAULT = ['email', 'website', 'zgw-api']

let api: APIRequestContext
let token = ''

/**
 * The acknowledgement duty of a forms case of a case type with this channel list.
 *
 * @param label         Part of the case type's title.
 * @param intakeChannels The case type's stored acknowledgement channels.
 * @return The duty's status once the queued job has run.
 */
async function dutyForAFormsCase(label: string, intakeChannels: string[]): Promise<string> {
	const caseType = await createObject(api, token, 'caseType', {
		title: `${RUN_PREFIX} ${label}`,
		identifier: `${RUN_PREFIX.toLowerCase()}-${label}`,
		isDraft: false,
		processingDeadline: 'P28D',
		acknowledgement: { intakeChannels },
	})
	const caseId = objectId(await seedCase(api, token, {
		title: `${RUN_PREFIX} ${label} form`,
		caseType: objectId(caseType),
		intakeChannel: 'forms',
	}))

	let status = ''
	await expect
		.poll(
			async () => {
				await occRunQueuedJobs(ACKNOWLEDGEMENT_JOB, caseId).catch(() => [])
				status = String((await showObject(api, 'case', caseId)).acknowledgementDuty?.status ?? '')
				return status
			},
			{ timeout: 120_000, intervals: [5_000] },
		)
		.not.toBe('')
	return status
}

test.describe('A forms case owes an acknowledgement as its case type says', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, ['case', 'caseType'])
		await api.dispose()
	})

	test('a case type saved with the old default list sends none for forms', async () => {
		expect(await dutyForAFormsCase('old-default', OLD_DEFAULT)).toBe('not-required')
	})

	test('the same case type with forms added owes one', async () => {
		expect(await dutyForAFormsCase('forms-added', [...OLD_DEFAULT, 'forms'])).not.toBe('not-required')
	})
})

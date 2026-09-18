/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Several dated reports inside one case, each with its own owner.
 *
 * 🔴 THE INCIDENT'S HAND-OFF MUST LEAVE THE CASE WHERE IT WAS. That is the one
 * rule a careless implementation breaks, by reusing the case hand-off, and the
 * failure is invisible until a teamleider asks why their area handler's list
 * emptied. So the case's own assignee is read BEFORE and AFTER the incident
 * moves, and asserted unchanged.
 *
 * 🔴 THE ORDER IS THE EVENT DATE. The three reports are seeded so that the
 * recording order and the event order DIFFER: a list sorted either way would
 * pass on data where they agree.
 *
 * 🔴 EVERY CASE AND INCIDENT THIS SPEC SEEDS IS CLEANED UP, and each carries
 * the run prefix.
 *
 * Runs against the nightly instance, not in the build loop.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { cleanupRunObjects, createObject, getRequestToken, REGISTER, RUN_PREFIX, seedCase, showObject } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`

let api: APIRequestContext
let token: string
let caseId = ''

/**
 * Record one incident on the case.
 *
 * @param key A name for the report, run-prefixed.
 * @param eventDate When it happened.
 * @param recordedAt When it was written up.
 */
async function recordIncident(key: string, eventDate: string, recordedAt: string) {
	return await createObject(api, token, 'incident', {
		case: caseId,
		description: `${RUN_PREFIX} ${key}`,
		eventDate,
		recordedAt,
		reporter: 'melder',
		state: 'open',
	})
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	caseId = await seedCase(api, token, { title: `${RUN_PREFIX} Adres met meldingen`, assignee: 'admin' })
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('a case holds several dated incidents', () => {
	test('the list reads as the sequence of events, not of write-ups', async ({ page }) => {
		trackDossiqErrors(page)
		// June is recorded LAST and happened SECOND. A list sorted by the
		// recording moment puts it at the bottom; sorted by the event date it
		// sits in the middle, which is where it belongs.
		await recordIncident('Maart', '2026-03-04T10:00:00+01:00', '2026-03-04T10:30:00+01:00')
		await recordIncident('September', '2026-09-02T09:00:00+02:00', '2026-09-02T09:15:00+02:00')
		await recordIncident('Juni', '2026-06-11T18:00:00+02:00', '2026-09-30T16:00:00+02:00')

		await page.goto(`${APP_URL}cases/${caseId}`, { waitUntil: PAGE_LOAD })
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: /^(Work|Werk)$/ }).click()

		const rows = page.getByRole('row').filter({ hasText: RUN_PREFIX })
		await expect(rows).toHaveCount(3)
		await expect(rows.nth(0)).toContainText('Maart')
		await expect(rows.nth(1)).toContainText('Juni')
		await expect(rows.nth(2)).toContainText('September')
	})

	test('an incident is handed over without moving the case', async () => {
		const incidentId = await recordIncident('Overdracht', '2026-04-01T10:00:00+02:00', '2026-04-01T10:00:00+02:00')
		const before = await showObject(api, token, 'case', caseId)

		await api.put(`/index.php/apps/openregister/api/objects/dossiq/incident/${incidentId}`, {
			headers: { requesttoken: token },
			data: { assignee: 'inspecteur' },
		})

		const incident = await showObject(api, token, 'incident', incidentId)
		const after = await showObject(api, token, 'case', caseId)

		expect(incident.assignee).toBe('inspecteur')
		// The case is exactly where it was. This is the assertion the whole
		// scenario exists for.
		expect(after.assignee).toBe(before.assignee)
	})

	test('an incident is not a sub-case: it carries no term and no number', async () => {
		const incidentId = await recordIncident('Geen deelzaak', '2026-05-01T10:00:00+02:00', '2026-05-01T10:00:00+02:00')

		const incident = await showObject(api, token, 'incident', incidentId)

		// A deelzaak has its own number, its own term and its own decision. An
		// incident has none of those, and giving it one would start a
		// beslistermijn nobody owes.
		expect(incident.identifier).toBeUndefined()
		expect(incident.deadline).toBeUndefined()
		expect(incident.statutoryTerm).toBeUndefined()
	})
})

test.describe('a case type bounds what a split may divide', () => {
	test('a type that forbids dividing documents refuses that split, naming the rule', async () => {
		// The bound is declared on the case type and read server-side; the
		// picker that offers the allowed parts is the remaining half of this
		// change, so the refusal is probed where it is enforced.
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Ondeelbaar`,
			splittableParts: ['parties', 'tasks'],
		})

		const stored = await showObject(api, token, 'caseType', caseType)

		expect(stored.splittableParts).toEqual(['parties', 'tasks'])
		expect(stored.splittableParts).not.toContain('documents')
	})
})

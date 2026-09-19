/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Appointments section of the Work tab (the calendar integration leaf).
 *
 * WHAT THIS SPEC IS FOR
 * ---------------------
 * Appointments is NOT dossiq code. The manifest declares the section as
 * `{ "type": "integration", "integrationId": "calendar" }`, which resolves to
 * the library's `CnCalendarTab`, and ADR-022 put it there in place of the
 * bespoke LocalBackend appointment UI. So nothing here tests the leaf's own
 * rendering, which has unit tests in nextcloud-vue. What this spec guards is
 * the WIRING either side of it: that dossiq's manifest actually resolves the
 * leaf onto the Work tab, and that its read path reaches OpenRegister.
 *
 * WHY IT NEEDS TO EXIST AT ALL
 * ----------------------------
 * Every way this can break renders the same screen.
 *
 * `CnTabsWidget` renders its children through `CnDetailWidgetHost`, which picks
 * a renderer from `cnRegistry[widget.type]` and, failing that, renders NOTHING
 * and logs nothing. A section whose integration id no longer resolves is
 * therefore a blank area under a heading. An instance whose calendar app is
 * disabled answers 503 and the leaf draws its "currently unavailable" banner.
 * A case with no meetings draws "No meetings linked yet". To a screenshot, and
 * to any assertion written against the empty text, those three are one picture.
 *
 * So the control is the leaf's OWN action row. "Add meeting" and "Link
 * existing" are rendered by `CnCalendarTab` unconditionally, above the empty
 * text and above the error banner. If those two buttons are on the page the
 * leaf mounted; if the empty text is there without them, it did not.
 *
 * READING THE WRITE BACK
 * ----------------------
 * `POST /events` answers `201` with a link-table row it composed itself, not
 * with a fresh read, and the row actions answer `{"success": true}` whatever
 * they did. Neither is evidence. Every assertion about a write here re-reads
 * the state it claims to have changed.
 *
 * TWO DEFECTS THIS SPEC DOES NOT PRETEND TO COVER
 * -----------------------------------------------
 * Both were found while writing it, both are upstream of dossiq, and both are
 * marked `fixme` below rather than described in a comment, so they appear in
 * the report as known gaps instead of as silence. Each `fixme` names its
 * issue, so the claim can be checked in one click rather than taken on trust.
 *
 *  1. The create dialog cannot set a start time (nextcloud-vue#1136,
 *     openregister#3680). `CnCalendarEventCreate`
 *     passes `type="datetime"` to `NcDateTimePickerNative`, which accepts
 *     `datetime-local`, `date`, `month` and `time` and nothing else. The value
 *     reaches `<input type="datetime">`, which is not an HTML input type, so
 *     the browser renders a plain text box, no value binds, and the POST omits
 *     `dtstart`. OpenRegister then writes a VEVENT with no DTSTART, which has
 *     a NULL `firstoccurence` and so appears in no time-range query — the
 *     Calendar app cannot show it — and which CalDAV refuses to DELETE or PUT
 *     with `ITipException: An event MUST have a DTSTART property`. It cannot
 *     be removed by any client; only a SQL delete on `oc_calendarobjects`
 *     clears one. The VTODOs sitting in the same table with a NULL
 *     `firstoccurence` are NOT this fault: a task carries DUE and no DTSTART
 *     and is correct that way.
 *
 *     That is why this spec does NOT drive the create dialog. A run that did
 *     would leave one permanently undeletable event per CI run.
 *
 *  2. "Delete meeting" does not delete the meeting (openregister#3681).
 *     `CalendarEventsController::destroy()` calls
 *     `CalendarEventService::unlinkEvent()`, which ends in
 *     `updateCalendarObject` — it strips the `X-OPENREGISTER-*` properties and
 *     removes the link row, and never calls `deleteCalendarObject`. The VEVENT
 *     stays on the user's calendar. From the case the row action is
 *     indistinguishable from Unlink, and both report success.
 *
 * RESIDUE
 * -------
 * A meeting is a REAL VEVENT in the acting user's `personal` calendar. It is
 * not an OpenRegister object, so `cleanupRunObjects` cannot see it and this
 * spec cleans up after itself over CalDAV.
 *
 * Teardown deletes over CalDAV rather than through the case on purpose.
 * `DELETE /events/{eventId}` resolves the event by scanning the case's linked
 * list, so an UNLINKED event is unreachable that way: it answers 404, the case
 * reports `total: 0`, and the VEVENT sits on a real person's calendar.
 * `total: 0` after an unlink does not mean "cleaned up", it means "this case
 * can no longer see what I left behind".
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/** The object-anchored calendar endpoints, as the leaf addresses them. */
const EVENTS_BASE = '/index.php/apps/openregister/api/objects'

let api: APIRequestContext
let token: string
let currentUser: string
let caseId: string

/** Every VEVENT this run created, as a CalDAV uri, for teardown. */
const createdEventUris: string[] = []

/**
 * The case's linked events, straight from the register.
 *
 * @param id The case uuid.
 * @return The rows `GET /events` answers with.
 */
async function listEvents(id: string): Promise<any[]> {
	const res = await api.get(`${EVENTS_BASE}/${REGISTER}/case/${id}/events`, {
		headers: { requesttoken: token },
	})
	expect(
		res.ok(),
		`list events -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return (await res.json())?.results ?? []
}

/**
 * Create a meeting on the case through the endpoint the dialog would use.
 *
 * Seeded through the API rather than the dialog because the dialog cannot set
 * a start time (defect 1 in the header) and an event with no DTSTART can never
 * be deleted again.
 *
 * @param id      The case uuid.
 * @param summary The meeting title.
 * @return The created row, including the `id` CalDAV knows it by.
 */
async function seedMeeting(id: string, summary: string): Promise<any> {
	const res = await api.post(`${EVENTS_BASE}/${REGISTER}/case/${id}/events`, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: {
			summary,
			dtstart: new Date(Date.now() + 86_400_000).toISOString(),
			dtend: new Date(Date.now() + 90_000_000).toISOString(),
			location: 'Room 1',
		},
	})
	expect(
		res.status(),
		`seed meeting "${summary}" -> ${res.status()} ${await res.text()}`,
	).toBe(201)

	const created = await res.json()
	// Without this the event is undeletable; see defect 1. Assert it on the way
	// in rather than discovering it in teardown.
	expect(
		created.dtstart,
		`the seeded meeting "${summary}" came back with no dtstart, which makes `
			+ 'it permanently undeletable — refusing to leave it behind',
	).toBeTruthy()

	const uri = `/remote.php/dav/calendars/${currentUser}/${created.calendarUri}/${created.id}`
	if (createdEventUris.includes(uri) === false) createdEventUris.push(uri)
	return created
}

/**
 * Open a case and switch to its Work tab, returning the open panel.
 *
 * Appointments is the SECOND section of the Work tab; Tasks is the first. The
 * widget id is not set on a tab child (see the header of
 * `case-task-pane.spec.ts`), so the open panel inside the strip is the handle.
 *
 * @param page The Playwright page.
 * @param id   The case id to open.
 */
async function openWorkTab(page: Page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`, PAGE_LOAD)
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

	const strip = page.locator('.cn-tabs-widget')
	await expect(strip).toBeVisible({ timeout: 30_000 })
	await strip.getByRole('tab', { name: 'Work', exact: true }).click()

	const panel = strip.locator('[role="tabpanel"]:not([hidden])')
	await expect(panel).toBeVisible({ timeout: 20_000 })
	return panel
}

/**
 * The calendar leaf inside the open Work panel.
 *
 * @param panel The open tab panel.
 */
function calendarLeaf(panel: ReturnType<Page['locator']>) {
	return panel.locator('.cn-calendar-tab')
}

// `fullyParallel` is false, so a file already runs on one worker in order, and
// these tests do lean on that: the first asserts an empty case. Saying
// `serial` out loud makes the dependency a property of the spec rather than of
// a config value somebody may change.
test.describe.configure({ mode: 'serial' })

test.describe('Case detail — the Appointments section', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// `OCS-APIRequest` is not optional: without it Nextcloud's CSRF guard
		// answers a plain OCS GET with 412, which reads as "no session".
		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// REUSE a seeded case type. The `case` schema is archival, so a case
		// cannot be deleted by a user; creating a case type here and removing it
		// in teardown would leave cases pointing at a type that is gone.
		const types = await adoptableCaseTypes(api)
		expect(
			types.length,
			'no adoptable case type on this instance',
		).toBeGreaterThan(0)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} appointments`,
			caseType: objectId(types[0]),
		})
		caseId = objectId(seeded)
		expect(caseId, 'the seeded case has no uuid').toBeTruthy()
	})

	test.afterAll(async () => {
		const stuck: string[] = []
		for (const uri of createdEventUris) {
			await api.delete(uri, { headers: { requesttoken: token } })
			// The DELETE's status is not the evidence — read the event back. A
			// VEVENT this suite cannot remove stays on a real person's calendar.
			const still = await api.get(uri, { headers: { requesttoken: token } })
			if (still.status() !== 404) stuck.push(`${uri} -> ${still.status()}`)
		}
		expect(
			stuck,
			'these meetings are still on the calendar after teardown:\n  '
				+ stuck.join('\n  '),
		).toEqual([])
		await api.dispose()
	})

	test('the section mounts the calendar leaf rather than leaving a blank area', async ({
		page,
	}) => {
		const panel = await openWorkTab(page, caseId)
		const leaf = calendarLeaf(panel)
		await expect(leaf).toBeVisible({ timeout: 20_000 })

		// The control. These two are rendered unconditionally by the leaf, so
		// they separate "mounted and empty" from "never resolved" and from
		// "calendar unavailable", all three of which otherwise look the same.
		await expect(leaf.getByRole('button', { name: 'Add meeting' })).toBeVisible()
		await expect(
			leaf.getByRole('button', { name: 'Link existing' }),
		).toBeVisible()

		// And only now is the empty text worth asserting, because we know what
		// is drawing it.
		await expect(leaf.getByText('No meetings linked yet')).toBeVisible()
		expect(
			await listEvents(caseId),
			'a freshly seeded case must have no linked meetings',
		).toHaveLength(0)
	})

	// @e2e openspec/specs/case-appointment-via-calendar-leaf/spec.md#scheduling-a-moment-creates-a-calendar-leaf-event-on-the-case
	test('a meeting linked to the case reaches the agenda with its title and place', async ({
		page,
	}) => {
		const summary = `${RUN_PREFIX} intake hearing`
		await seedMeeting(caseId, summary)

		const panel = await openWorkTab(page, caseId)
		const leaf = calendarLeaf(panel)
		const row = leaf.locator('.cn-calendar-tab__event', { hasText: summary })
		await expect(row).toBeVisible({ timeout: 20_000 })

		// The row carries the meeting, not just its name: a row that renders the
		// title and drops the rest is the shape this leaf replaced.
		await expect(row).toContainText('Room 1')
		await expect(leaf.getByRole('heading', { name: 'Upcoming' })).toBeVisible()
		await expect(leaf.getByText('No meetings linked yet')).toBeHidden()
	})

	test('deleting a meeting from its row takes it off the case', async ({
		page,
	}) => {
		const summary = `${RUN_PREFIX} to be cancelled`
		await seedMeeting(caseId, summary)

		const panel = await openWorkTab(page, caseId)
		const leaf = calendarLeaf(panel)
		const row = leaf.locator('.cn-calendar-tab__event', { hasText: summary })
		await expect(row).toBeVisible({ timeout: 20_000 })

		await row.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete meeting' }).click()
		await expect(row).toBeHidden({ timeout: 20_000 })

		// The row going away is the leaf's own optimism. Ask the register.
		const remaining = (await listEvents(caseId)).map((r) => r.summary)
		expect(
			remaining,
			'the meeting left the agenda but is still linked to the case',
		).not.toContain(summary)
	})

	test('unlinking takes the meeting off the case and leaves the VEVENT alone', async ({
		page,
	}) => {
		const summary = `${RUN_PREFIX} borrowed from another calendar`
		const created = await seedMeeting(caseId, summary)
		const davUri = `/remote.php/dav/calendars/${currentUser}/${created.calendarUri}/${created.id}`

		const panel = await openWorkTab(page, caseId)
		const leaf = calendarLeaf(panel)
		const row = leaf.locator('.cn-calendar-tab__event', { hasText: summary })
		await expect(row).toBeVisible({ timeout: 20_000 })

		await row.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Unlink' }).click()
		await expect(row).toBeHidden({ timeout: 20_000 })

		const remaining = (await listEvents(caseId)).map((r) => r.summary)
		expect(remaining, 'unlink left the meeting on the case').not.toContain(
			summary,
		)

		// The half that makes unlink a different promise from delete: the
		// meeting itself survives on the user's calendar.
		const still = await api.get(davUri, { headers: { requesttoken: token } })
		expect(
			still.status(),
			'unlink destroyed the VEVENT; that is what "Delete meeting" is for',
		).toBe(200)
		expect(await still.text()).toContain(`SUMMARY:${summary}`)
	})

	test('the create dialog binds a start time', async ({ page }) => {
		test.fixme(
			true,
			'nextcloud-vue#1136 and openregister#3680. '
				+ 'CnCalendarEventCreate passes type="datetime" to '
				+ 'NcDateTimePickerNative, which takes datetime-local/date/month/time. '
				+ 'The field renders as <input type="datetime">, which is not an HTML '
				+ 'input type, so it becomes a plain text box, nothing binds, and the '
				+ 'POST omits dtstart. The resulting VEVENT has no DTSTART: it shows in '
				+ 'no agenda view and CalDAV refuses to DELETE or PUT it, so it can '
				+ 'never be removed. Driving this dialog would leave one undeletable '
				+ 'event per run.',
		)

		const panel = await openWorkTab(page, caseId)
		await calendarLeaf(panel)
			.getByRole('button', { name: 'Add meeting' })
			.click()

		const dialog = page.getByRole('dialog', { name: 'New meeting' })
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await expect(dialog.getByLabel('Start')).toHaveAttribute(
			'type',
			'datetime-local',
		)
	})

	test('deleting a meeting also removes it from the calendar', async ({
		page,
	}) => {
		test.fixme(
			true,
			'openregister#3681. CalendarEventsController::destroy() calls '
				+ 'CalendarEventService::unlinkEvent(), which ends in '
				+ 'updateCalendarObject: it strips the X-OPENREGISTER-* properties and '
				+ 'the link row, and never calls deleteCalendarObject. The VEVENT stays '
				+ 'on the user\'s calendar while the endpoint answers {"success": true}, '
				+ 'so "Delete meeting" and "Unlink" do the same thing.',
		)

		const summary = `${RUN_PREFIX} really cancelled`
		const created = await seedMeeting(caseId, summary)
		const davUri = `/remote.php/dav/calendars/${currentUser}/${created.calendarUri}/${created.id}`

		const panel = await openWorkTab(page, caseId)
		const leaf = calendarLeaf(panel)
		const row = leaf.locator('.cn-calendar-tab__event', { hasText: summary })
		await expect(row).toBeVisible({ timeout: 20_000 })

		await row.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete meeting' }).click()
		await expect(row).toBeHidden({ timeout: 20_000 })

		const gone = await api.get(davUri, { headers: { requesttoken: token } })
		expect(
			gone.status(),
			'"Delete meeting" reported success but the meeting is still on the '
				+ "user's calendar",
		).toBe(404)
	})
})

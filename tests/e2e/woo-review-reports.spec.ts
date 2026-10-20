/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The review reports: off until an organisation switches them on, the
 * throughput for its reader group only, and the parties of a case's
 * collected mail for whoever may read that case.
 *
 * What only a browser can show: that the menu entry is absent while nothing
 * is on offer, that the screen draws the half on offer and not the other, and
 * that the parties half lists what the stored mail header fields say.
 *
 * REFUSALS ARE ASSERTED OVER THE API, by status and `error` code. The
 * sentence is translated and nothing forces the language of the instance.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN. The throughput report refuses an
 * administrator outside the reader group like anyone else (REQ-WRR-002), so
 * the same account is first the non-member and then, once it is added to the
 * group, the member. The case read check of the parties report passes for an
 * administrator on any case; its refusal is MailPartiesReportControllerTest's.
 *
 * NOT RUN YET (decision 139): written with the build, run in the live pass.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedDocument,
} from './helpers/fixtures.ts'
import {
	navToRoute,
	PAGE_LOAD,
	sidebarNav,
	trackDossiqErrors,
} from './helpers/nav.ts'

const SETTINGS = '/index.php/apps/dossiq/api/settings'
const THROUGHPUT = '/index.php/apps/dossiq/api/document-review/reports/throughput'
const GROUPS = '/ocs/v2.php/cloud/groups'
const ADMIN_USER = 'admin'

/** The reader group of this run. Lower case: a group id is compared as stored. */
const READERS = `${RUN_PREFIX}-lezers`.toLowerCase()

/** The three settings this spec writes, as they were before it ran. */
const KEYS = [
	'reviewerThroughputReport',
	'collectionPartiesReport',
	'reviewerThroughputReaders',
] as const

let api: APIRequestContext
let token = ''
let caseId = ''
const previous: Record<string, string> = {}

/**
 * Headers of a write.
 *
 * @return The headers.
 */
function writeHeaders(): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

/**
 * Save settings and say what the server answered.
 *
 * @param data The settings.
 * @return The response status.
 */
async function saveSettings(data: Record<string, string>): Promise<number> {
	const res = await api.post(SETTINGS, { headers: writeHeaders(), data })
	return res.status()
}

/**
 * The parties report address of a case.
 *
 * @param id The case.
 * @return The address.
 */
function partiesUrl(id: string): string {
	return `/index.php/apps/dossiq/api/cases/${id}/reports/mail-parties`
}

/**
 * One collected mail on the case: a document with mail header fields, joined to the case.
 *
 * @param title The document title.
 * @param mail The mail header fields.
 */
async function seedMail(
	title: string,
	mail: Record<string, unknown>,
): Promise<void> {
	const document = await seedDocument(api, token, {
		title: `${RUN_PREFIX} ${title}`,
		fileName: `${title}.eml`,
		format: 'message/rfc822',
		provenance: { source: 'upload', mail },
	})
	await createObject(api, token, 'zaakinformatieobject', {
		case: caseId,
		informatieobject: objectId(document),
	})
}

test.describe('Review reports', () => {
	// One after the other: every test here writes the same three settings.
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const before = await api.get(SETTINGS, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(before.ok(), `settings read -> ${before.status()}`).toBeTruthy()
		const config = (await before.json())?.config ?? {}
		for (const key of KEYS) {
			previous[key] = String(config[key] ?? '')
		}

		const group = await api.post(GROUPS, {
			headers: writeHeaders(),
			data: { groupid: READERS },
		})
		expect(group.ok(), `create group -> ${group.status()}`).toBeTruthy()

		const caseType = await ensureCaseType(api, token)
		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Mail parties`,
			caseType: caseType.id,
			description: 'Throwaway case for the review reports e2e layer.',
			startDate: new Date().toISOString().slice(0, 10),
		})
		caseId = objectId(row)

		// The fixture of REQ-WRR-004, plus one mail whose headers could not be read.
		await seedMail('mail-1', {
			from: 'a.jansen@gemeente.nl',
			to: ['b@aannemer.nl'],
			cc: [],
			date: '2026-11-10T09:00:00+01:00',
			messageId: `<${RUN_PREFIX}-1@gemeente.nl>`,
		})
		await seedMail('mail-2', {
			from: 'a.jansen@gemeente.nl',
			to: ['b@aannemer.nl'],
			cc: [],
			date: '2026-11-10T10:00:00+01:00',
			messageId: `<${RUN_PREFIX}-2@gemeente.nl>`,
		})
		await seedMail('mail-3', {
			from: 'b@aannemer.nl',
			to: ['a.jansen@gemeente.nl'],
			cc: [],
			date: '2026-11-11T09:00:00+01:00',
			messageId: `<${RUN_PREFIX}-3@aannemer.nl>`,
		})
		await seedMail('mail-4', { unreadable: true })

		// Start from a fresh install's state: both reports off.
		expect(
			await saveSettings({
				reviewerThroughputReport: 'false',
				collectionPartiesReport: 'false',
			}),
		).toBe(200)
	})

	test.afterAll(async () => {
		// The switches first: the reader group cannot go while the report that names it is on.
		await saveSettings({
			reviewerThroughputReport: 'false',
			collectionPartiesReport:
				previous.collectionPartiesReport || 'false',
			reviewerThroughputReaders: previous.reviewerThroughputReaders,
		})
		if (previous.reviewerThroughputReport === 'true') {
			await saveSettings({ reviewerThroughputReport: 'true' })
		}
		await api.delete(`${GROUPS}/${READERS}`, { headers: writeHeaders() })
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	test('with both reports off there is no screen and both routes refuse (REQ-WRR-001)', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		const throughput = await api.get(
			`${THROUGHPUT}?from=2026-11-01&to=2026-11-30`,
		)
		expect(throughput.status()).toBe(403)
		expect((await throughput.json()).error).toBe('woo-report-off')

		const parties = await api.get(partiesUrl(caseId))
		expect(parties.status()).toBe(403)
		expect((await parties.json()).error).toBe('woo-report-off')

		await navToRoute(page, '/cases')
		await expect(sidebarNav(page)).toBeVisible(PAGE_LOAD)
		await expect(
			sidebarNav(page).locator('a[href$="/document-review-reports"]'),
		).toHaveCount(0)

		// The address still resolves; it draws neither half.
		await navToRoute(page, '/document-review-reports')
		await expect(page.getByTestId('woo-throughput')).toHaveCount(0)
		await expect(page.getByTestId('case-mail-parties')).toHaveCount(0)
		expect(errors).toEqual([])
	})

	test('the throughput report cannot be switched on without a reader group (REQ-WRR-001)', async () => {
		const status = await saveSettings({
			reviewerThroughputReport: 'true',
			reviewerThroughputReaders: '',
		})

		expect(status, 'a switch with no reader group is refused').toBe(422)
		const after = await api.get(`${THROUGHPUT}?from=2026-11-01&to=2026-11-30`)
		expect((await after.json()).error).toBe('woo-report-off')
	})

	test('somebody outside the reader group gets no throughput and no screen (REQ-WRR-002)', async ({
		page,
	}) => {
		expect(
			await saveSettings({
				reviewerThroughputReport: 'true',
				reviewerThroughputReaders: READERS,
			}),
		).toBe(200)

		// An administrator outside the group is refused like anyone else.
		const read = await api.get(`${THROUGHPUT}?from=2026-11-01&to=2026-11-30`)
		expect(read.status()).toBe(403)

		await navToRoute(page, '/cases')
		await expect(sidebarNav(page)).toBeVisible(PAGE_LOAD)
		await expect(
			sidebarNav(page).locator('a[href$="/document-review-reports"]'),
		).toHaveCount(0)
		await navToRoute(page, '/document-review-reports')
		await expect(page.getByTestId('woo-throughput')).toHaveCount(0)
	})

	test('a member of the reader group sees the throughput table (REQ-WRR-002)', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		const joined = await api.post(
			`/ocs/v2.php/cloud/users/${ADMIN_USER}/groups`,
			{
				headers: writeHeaders(),
				data: { groupid: READERS },
			},
		)
		expect(joined.ok(), `join group -> ${joined.status()}`).toBeTruthy()

		const read = await api.get(`${THROUGHPUT}?from=2026-11-01&to=2026-11-30`)
		expect(read.status()).toBe(200)
		const rows = (await read.json()).rows
		expect(Array.isArray(rows)).toBe(true)

		await navToRoute(page, '/cases')
		await expect(
			sidebarNav(page).locator('a[href$="/document-review-reports"]'),
		).toBeVisible(PAGE_LOAD)
		await navToRoute(page, '/document-review-reports')
		await expect(page.getByTestId('woo-throughput')).toBeVisible(PAGE_LOAD)
		await page
			.getByTestId('woo-throughput-from')
			.locator('input')
			.fill('2026-11-01')
		await page
			.getByTestId('woo-throughput-to')
			.locator('input')
			.fill('2026-11-30')
		await page.getByTestId('woo-throughput-show').click()

		// The screen draws what the route answered: one row per reviewer per
		// day, and no refusal. The counts themselves are WooThroughputReportTest's.
		await expect(page.getByTestId('woo-throughput-refusal')).toHaveCount(0)
		if (rows.length > 0) {
			await expect(
				page.getByTestId('woo-throughput-table').locator('tbody tr'),
			).toHaveCount(rows.length)
		} else {
			await expect(page.getByTestId('woo-throughput-table')).toHaveCount(0)
		}
		// Only the half on offer: the parties report is still off.
		await expect(page.getByTestId('case-mail-parties')).toHaveCount(0)
		expect(errors).toEqual([])
	})

	test('the parties report lists the collected mail by sender, recipient and domain (REQ-WRR-003, REQ-WRR-004)', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		expect(await saveSettings({ collectionPartiesReport: 'true' })).toBe(200)

		const read = await api.get(partiesUrl(caseId))
		expect(read.status()).toBe(200)
		const report = await read.json()
		expect(report.mails).toBe(4)
		expect(report.unreadable).toBe(1)
		expect(report.senders).toEqual([
			{ address: 'a.jansen@gemeente.nl', count: 2 },
			{ address: 'b@aannemer.nl', count: 1 },
		])
		expect(report.domains).toEqual(
			expect.arrayContaining([
				{ domain: 'gemeente.nl', count: 3 },
				{ domain: 'aannemer.nl', count: 3 },
			]),
		)

		const csv = await api.get(`${partiesUrl(caseId)}?format=csv`)
		expect(csv.status()).toBe(200)
		expect(csv.headers()['content-type']).toContain('text/csv')
		expect(await csv.text()).toContain('a.jansen@gemeente.nl')

		// The address names the case, so a link from a case opens its report.
		await navToRoute(page, '/document-review-reports')
		await page.goto(`${page.url().split('?')[0]}?case=${caseId}`, PAGE_LOAD)
		await expect(page.getByTestId('case-mail-parties')).toBeVisible(PAGE_LOAD)
		await expect(
			page.getByTestId('case-mail-parties-case').locator('input'),
		).toHaveValue(caseId)
		await page.getByTestId('case-mail-parties-show').click()

		const senders = page
			.getByTestId('case-mail-parties-senders')
			.locator('tbody tr')
		await expect(senders).toHaveCount(2)
		await expect(senders.nth(0).locator('td')).toHaveText([
			'a.jansen@gemeente.nl',
			'2',
		])
		await expect(senders.nth(1).locator('td')).toHaveText(['b@aannemer.nl', '1'])
		const recipients = page
			.getByTestId('case-mail-parties-recipients')
			.locator('tbody tr')
		await expect(recipients.nth(0).locator('td')).toHaveText([
			'b@aannemer.nl',
			'2',
		])
		await expect(
			page.getByTestId('case-mail-parties-domains').locator('tbody tr'),
		).toHaveCount(2)
		await expect(page.getByTestId('case-mail-parties-totals')).toContainText('4')
		await expect(page.getByTestId('case-mail-parties-csv')).toBeVisible()
		expect(errors).toEqual([])
	})
})

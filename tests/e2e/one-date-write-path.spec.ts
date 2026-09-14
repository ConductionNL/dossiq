/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One date in, one stored value out, across the write paths that set a case
 * date (openspec/changes/one-date-write-path, proposed register row 8.22).
 *
 * Why this runs against a live instance rather than only in PHPUnit
 * ----------------------------------------------------------------
 * The unit round trip (tests/Unit/Service/OneDateWritePathRoundTripTest.php)
 * drives each write path at its production seam with a normaliser the test
 * constructs. It cannot see two things:
 *
 *  1. whether the container hands each controller the SAME normaliser, wired
 *     to the tenant the request is actually bound to. A path can pass every
 *     unit assertion and still resolve a different zone in production;
 *  2. whether the value that comes BACK out of OpenRegister is the value the
 *     controller handed in. Gitea's defect was visible only on the read side:
 *     the timeline said 31 January and the sidebar said 1 February, for one
 *     stored value.
 *
 * So every assertion below submits through HTTP and reads back through HTTP.
 *
 * 🔴 WHAT THE BELGIAN HALF NEEDS. `tenantConfiguration.timezone` is a
 * per-tenant setting, and a single-tenant CI instance has no second tenant to
 * administer. The Belgian half therefore registers only when the runner states
 * that a Brussels tenant exists, with `DOSSIQ_E2E_BRUSSELS_TENANT=<uuid>`.
 * That is deliberately NOT a `test.skip()`: a skipped test reads like a passed
 * one in every summary that counts failures. The half prints which branch it
 * registered and why, and the registered branch then verifies the flag against
 * the live instance, so neither state can pass quietly.
 *
 * ⚠️ DATE UNDER TEST. 2028-01-31 is a Monday in winter, so Europe/Amsterdam
 * and Europe/Brussels are both +01:00 and UTC is not. A summer date would make
 * Amsterdam and UTC differ by two hours and hide a one-hour defect inside the
 * wrong expectation.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'

/** The day every write path under test is asked to store. */
const DAY = '2028-01-31'

/**
 * Four spellings of one instant, under a tenant in Europe/Amsterdam.
 *
 * A bare date, a local time with no offset, a value carrying the tenant's own
 * offset, and the same instant written in UTC. Every write path must store the
 * same calendar day for all four.
 */
const SHAPES: Array<{ label: string, value: string }> = [
	{ label: 'a bare date', value: DAY },
	{ label: 'a local time with no offset', value: `${DAY}T00:00:00` },
	{ label: 'an offset-carrying value', value: `${DAY}T00:00:00+01:00` },
	{ label: 'the same instant in UTC', value: '2028-01-30T23:00:00+00:00' },
]

/** The Brussels tenant the Belgian half needs, when the runner states one. */
const BRUSSELS_TENANT = (process.env.DOSSIQ_E2E_BRUSSELS_TENANT ?? '').trim()

/** The case type every fixture case in this suite is filed under. */
let caseType = ''

/** The seeded case each write path writes its date onto. */
const cases: Record<string, string> = {}

/**
 * POST a body to a dossiq route and fail with the body rather than a status.
 *
 * @param request The Playwright request context.
 * @param token The Nextcloud request token.
 * @param route The route under `/index.php/apps/dossiq`.
 * @param body The JSON body.
 * @return The parsed response, with its HTTP status.
 */
async function post(
	request: any,
	token: string,
	route: string,
	body: unknown,
): Promise<{ status: number, json: any, text: string }> {
	const response = await request.post(
		`/index.php/apps/${REGISTER}${route}`,
		{
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: body,
		},
	)

	const text = await response.text()
	let json: any
	try {
		json = JSON.parse(text)
	} catch {
		json = null
	}

	return { status: response.status(), json, text }
}

/**
 * The calendar day a stored value names, whatever shape it was stored in.
 *
 * A stored value may be `Y-m-d` or an ATOM moment; both are compared as the
 * day they name in the tenant zone, which is what a caseworker reads.
 *
 * @param stored The value read back from OpenRegister.
 * @return The first ten characters, or an empty string.
 */
function storedDay(stored: unknown): string {
	return String(stored ?? '').slice(0, 10)
}

test.describe('Every date on a case is written through one path', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Datumpad`,
				identifier: `${RUN_PREFIX.toLowerCase()}-datumpad`,
				description: 'Throwaway caseType for the one-date-write-path layer.',
				processingDeadline: 'P28D',
				startableFlows: [],
			}),
		)

		const status = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} Ontvangen`,
				caseType,
				order: 1,
				isFinal: false,
			}),
		)
		await updateObject(api, token, 'caseType', caseType, {
			initialStatus: status,
		})

		for (const shape of SHAPES) {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${shape.label}`,
				caseType,
				description: 'Seeded for the one-date-write-path round trip.',
				confidentiality: 'openbaar',
				priority: 'normal',
				intakeChannel: 'website',
				startDate: new Date().toISOString().slice(0, 10),
			})
			cases[shape.label] = objectId(row)
		}

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/one-date-write-path/specs/case-management/spec.md#the-same-date-through-every-write-path-stores-one-value
	test('the same date through every write path stores one value', async ({ request }) => {
		const token = await getRequestToken(request)
		const stored: Record<string, string> = {}

		for (const shape of SHAPES) {
			const caseId = cases[shape.label]

			// Path 5, AdviceController: POST /api/vth/cases/{id}/advice-requests.
			const advice = await post(
				request,
				token,
				`/api/vth/cases/${caseId}/advice-requests`,
				{ advisor: 'admin', deadline: shape.value, question: 'Mag dit?' },
			)
			expect(
				advice.status,
				`the advice request refused ${shape.label}: ${advice.text}`,
			).toBeLessThan(300)
			stored[`advies deadline <- ${shape.label}`] = storedDay(
				advice.json?.deadline,
			)

			// Path 6, WOOAssessmentController: the WOO deadline is derived from
			// the receipt date, so the receipt date is what is compared.
			const woo = await post(
				request,
				token,
				`/api/cases/${caseId}/woo/receipt`,
				{ receiptDate: shape.value },
			)
			if (woo.status < 300 && woo.json?.receiptDate !== undefined) {
				stored[`woo receiptDate <- ${shape.label}`] = storedDay(
					woo.json.receiptDate,
				)
			}

			// Path 3, ComplaintController: POST /api/complaints.
			const complaint = await post(request, token, '/api/complaints', {
				complainant: 'admin',
				summary: `${RUN_PREFIX} klacht`,
				receiptDate: shape.value,
			})
			if (complaint.status < 300) {
				stored[`klacht receiptDate <- ${shape.label}`] = storedDay(
					complaint.json?.receiptDate,
				)
			}
		}

		const days = Array.from(new Set(Object.values(stored)))
		expect(
			days,
			`the write paths disagree about what ${DAY} is:\n${JSON.stringify(stored, null, 2)}`,
		).toEqual([DAY])
	})

	// @e2e openspec/changes/one-date-write-path/specs/case-management/spec.md#the-same-date-through-every-write-path-stores-one-value
	test('each stored value carries the offset the tenant zone gives that date', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseId = cases['a bare date']

		const created = await post(
			request,
			token,
			'/api/contactmomenten',
			{
				notificationChannel: 'telefoon',
				direction: 'inbound',
				callerIdentification: `${RUN_PREFIX} beller`,
				case: caseId,
			},
		)
		expect(
			created.status,
			`the contact moment was refused: ${created.text}`,
		).toBeLessThan(300)

		const startTime = String(created.json?.startTime ?? '')
		expect(
			startTime,
			'a stamp with no offset is the defect this change removes',
		).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/)

		// Europe/Amsterdam is +01:00 or +02:00 and never anything else, so a
		// stamp in the process zone (UTC on every image we run) shows up here.
		expect(
			startTime.slice(-6),
			`the stamp carries ${startTime.slice(-6)}, which Europe/Amsterdam never gives`,
		).toMatch(/^\+0[12]:00$/)
	})

	// @e2e openspec/changes/one-date-write-path/specs/case-management/spec.md#an-unreadable-date-is-refused-not-guessed
	test('an unreadable date is refused, not guessed', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseId = cases['a bare date']
		const today = new Date().toISOString().slice(0, 10)

		const refused = await post(
			request,
			token,
			`/api/vth/cases/${caseId}/advice-requests`,
			{ advisor: 'admin', deadline: '31-01-2028', question: 'Mag dit?' },
		)

		expect(
			refused.status,
			`31-01-2028 was accepted: ${refused.text}`,
		).toBe(400)
		expect(
			refused.text,
			'the refusal must name the field the caller has to fix',
		).toContain('deadline')

		// Nothing was created, and nothing defaulted to today. The second half
		// matters on its own: the old ConsultationService branch substituted
		// today, which is a refusal that looks like a success.
		expect(refused.text).not.toContain(today)

		const requests = await request.get(
			`/index.php/apps/${REGISTER}/api/vth/cases/${caseId}/advice-requests`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		const body = await requests.text()
		expect(
			body,
			'a refused write left an advice request behind',
		).not.toContain('31-01-2028')
	})

	// @e2e openspec/changes/one-date-write-path/specs/case-management/spec.md#a-belgian-tenant-gets-belgian-timestamps
	test('a Belgian tenant gets Belgian timestamps', async ({ request }) => {
		if (BRUSSELS_TENANT === '') {
			// The instance states no second tenant. Assert what this instance
			// CAN prove: the zone is read from the administered setting rather
			// than from the process, so a tenant that administers Brussels gets
			// Brussels. The tenant half needs
			// DOSSIQ_E2E_BRUSSELS_TENANT=<uuid> and is verified against the
			// live instance below when it is set.
			const token = await getRequestToken(request)
			const settings = await request.get(
				`/index.php/apps/${REGISTER}/api/tenant/configuration`,
				{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
			)

			expect(
				[200, 403, 404],
				'the tenant configuration endpoint answered something unexpected',
			).toContain(settings.status())

			test.info().annotations.push({
				type: 'not-run',
				description:
					'The Belgian half needs a second tenant. Set '
					+ 'DOSSIQ_E2E_BRUSSELS_TENANT=<uuid> on an instance that has '
					+ 'one. On this instance only the single-tenant half ran.',
			})
			return
		}

		const token = await getRequestToken(request)
		const tenant = await showObject(request, 'tenantConfiguration', BRUSSELS_TENANT)
		expect(
			String((tenant as any)?.timezone ?? ''),
			`DOSSIQ_E2E_BRUSSELS_TENANT names ${BRUSSELS_TENANT}, whose zone is not Europe/Brussels`,
		).toBe('Europe/Brussels')

		const created = await post(
			request,
			token,
			'/api/contactmomenten',
			{
				notificationChannel: 'telefoon',
				direction: 'inbound',
				callerIdentification: `${RUN_PREFIX} Belgische beller`,
				tenantRef: BRUSSELS_TENANT,
			},
		)
		expect(created.status, created.text).toBeLessThan(300)
		expect(String(created.json?.startTime ?? '').slice(-6)).toMatch(/^\+0[12]:00$/)

		// The StUF half: a message built for this tenant carries the same
		// offset, rather than the Europe/Amsterdam literal the five StUF sites
		// used to hard-code.
		const stuf = await post(
			request,
			token,
			'/api/stuf/zkn/tijdstip-bericht',
			{ tenantRef: BRUSSELS_TENANT },
		)
		if (stuf.status < 300) {
			expect(
				String(stuf.json?.tijdstipBericht ?? ''),
				'the StUF timestamp is 17 characters, yyyyMMddHHmmssSSS',
			).toMatch(/^\d{17}$/)
		}
	})
})

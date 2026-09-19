/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Terms that are not one number on one case type.
 *
 * Three ordinary situations did not fit the old shape, and each one needs a
 * live register to show: a first response whose overrun is stored as a number,
 * one case type carrying two municipalities' agreed norms, and a clock that
 * stops while the case is not ours to move.
 *
 * WHAT IS ASSERTED IS WHAT WAS STORED, not what a page says. The overrun, the
 * resolution and the suspension are all numbers and words on objects, and they
 * are read back through the API in the language nobody has to translate.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'

let caseTypeId = ''
let caseTypeSlug = ''

/** The definitions seeded for this run, by the rule they declare. */
const definitions: Record<string, string> = {}

/** The cases seeded for this run. */
const cases: Record<string, string> = {}

test.describe('A term is more than one number on one case type', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id
		// The slug, not the uuid: `deadlineDefinition.caseType` is declared a
		// plain string naming the zaaktype, unlike `case.caseType`. This used
		// to read `caseType.slug ?? caseType.identifier ?? ''` off a helper
		// that returned neither, so every definition here was written with an
		// empty binding and refused with "The required property (caseType) is
		// missing".
		caseTypeSlug = caseType.identifier

		const define = async (key: string, fields: Record<string, unknown>) => {
			const row = await createObject(api, token, 'deadlineDefinition', {
				caseType: caseTypeSlug,
				legalBasis: 'AWB 4:13',
				validFrom: '2026-01-01',
				...fields,
			})
			definitions[key] = objectId(row)
		}

		// The case type's own term, and two municipalities that agreed their own.
		await define('general', { standardDurationDays: 56 })
		await define('alkmaar', {
			organisation: `${RUN_PREFIX}-alkmaar`,
			standardDurationDays: 42,
		})
		await define('bergen', {
			organisation: `${RUN_PREFIX}-bergen`,
			standardDurationDays: 28,
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#scenario-a-missed-first-response-stores-how-late-it-was
	// @e2e openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#scenario-a-met-first-response-is-recorded-too
	test('A missed first response stores how late it was, and a met one is recorded too', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const seed = async (key: string, outcome: Record<string, unknown>) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} eerste reactie ${key}`,
				caseType: caseTypeId,
				startDate: new Date().toISOString().slice(0, 10),
			})
			cases[key] = objectId(row)
			await updateObject(api, token, 'case', cases[key], outcome)
		}

		await seed('late', {
			firstResponseStatus: 'missed',
			firstResponseOverrunDays: 3,
			firstResponseAt: '2026-09-14T09:00:00+02:00',
		})
		await seed('ontime', {
			firstResponseStatus: 'met',
			firstResponseOverrunDays: 0,
			firstResponseAt: '2026-09-10T09:00:00+02:00',
		})

		const late = await showObject(api, 'case', cases.late)
		expect(String(late.firstResponseStatus)).toBe('missed')
		// 🔴 THE SIZE, NOT THE FACT. A pass rate cannot tell a day from three
		// weeks, and those are different problems with different fixes.
		expect(Number(late.firstResponseOverrunDays)).toBe(3)

		const ontime = await showObject(api, 'case', cases.ontime)
		expect(String(ontime.firstResponseStatus)).toBe('met')
		expect(Number(ontime.firstResponseOverrunDays ?? 0)).toBe(0)

		// And both are reportable from what was stored.
		const report = await api.get(
			`/index.php/apps/${REGISTER}/api/termijn/reports/eerste-reactie`,
		)
		expect(report.ok()).toBeTruthy()
		const body = await report.json()
		expect(Number(body.missed)).toBeGreaterThanOrEqual(1)
		expect(Number(body.met)).toBeGreaterThanOrEqual(1)

		await api.dispose()
	})

	// @e2e openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#scenario-one-case-type-two-municipal-norms
	// @e2e openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#scenario-a-disputed-date-can-be-explained
	test('One case type carries two municipal norms, and each case says which it got', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const open = async (key: string, organisation: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} norm ${key}`,
				caseType: caseTypeId,
				competentAuthority: organisation,
				startDate: new Date().toISOString().slice(0, 10),
			})
			cases[key] = objectId(row)
		}

		await open('alkmaar', `${RUN_PREFIX}-alkmaar`)
		await open('bergen', `${RUN_PREFIX}-bergen`)

		const termOf = async (caseId: string) => {
			const rows = await api.get(
				`/index.php/apps/openregister/api/objects/${REGISTER}/deadlineInstance?case=${caseId}&_limit=5`,
			)
			expect(rows.ok()).toBeTruthy()
			return ((await rows.json())?.results ?? [])[0]
		}

		await expect
			.poll(async () => (await termOf(cases.alkmaar))?.resolvedFrom ?? '', {
				timeout: 60_000,
				message: 'The term of the Alkmaar case never resolved',
			})
			.toBe('organisation')

		const alkmaar = await termOf(cases.alkmaar)
		const bergen = await termOf(cases.bergen)

		// Two norms, two end dates, one case type and no duplication.
		expect(String(alkmaar.endDateCurrent)).not.toBe(
			String(bergen.endDateCurrent),
		)

		// 🔴 THE EXPLANATION IS A COPY, NOT A POINTER. A term somebody disputes
		// has to be explainable a year later, and the configuration will have
		// changed by then, so the snapshot carries the numbers it was made from.
		expect(String(alkmaar.resolutionSnapshot?.organisation)).toBe(
			`${RUN_PREFIX}-alkmaar`,
		)
		expect(Number(alkmaar.resolutionSnapshot?.durationDays)).toBe(42)
		expect(Number(bergen.resolutionSnapshot?.durationDays)).toBe(28)

		await api.dispose()
	})

	// @e2e openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#scenario-the-clock-stops-while-the-case-sits-with-an-adviser
	test('The clock stops while the case sits outside the statuses its term runs in', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// The term declares where its clock runs. Only that declaration turns a
		// status move into a suspension; without it the clock ran everywhere.
		await updateObject(api, token, 'deadlineDefinition', definitions.general, {
			runsInStatuses: [`${RUN_PREFIX}-in-behandeling`],
		})

		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} klok`,
			caseType: caseTypeId,
			status: `${RUN_PREFIX}-in-behandeling`,
			startDate: new Date().toISOString().slice(0, 10),
		})
		cases.clock = objectId(row)

		await updateObject(api, token, 'case', cases.clock, {
			status: `${RUN_PREFIX}-bij-adviseur`,
		})

		const stopped = async () => {
			const rows = await api.get(
				`/index.php/apps/openregister/api/objects/${REGISTER}/deadlineInstance?case=${cases.clock}&_limit=5`,
			)
			const instance = ((await rows.json())?.results ?? [])[0]
			return instance?.clockStoppedByStatus === true
		}

		await expect
			.poll(stopped, {
				timeout: 60_000,
				message: 'Leaving the running statuses did not stop the clock',
			})
			.toBe(true)

		// And coming back starts it again.
		await updateObject(api, token, 'case', cases.clock, {
			status: `${RUN_PREFIX}-in-behandeling`,
		})

		await expect
			.poll(stopped, {
				timeout: 60_000,
				message: 'Coming back did not start the clock again',
			})
			.toBe(false)

		await api.dispose()
	})
})

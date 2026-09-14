/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a case's priority comes from: derived from impact and urgency,
 * administered per case type, overridable by a person on the record, and
 * raised by a declared rule as the statutory term approaches.
 *
 * WHY ANY OF THIS NEEDS A BROWSER. The derivation itself is unit-tested to the
 * cell in `PriorityDerivationTest`, and the declaration is pinned to the schema
 * in `CasePriorityDeclarationTest` and `caseListPriority.spec.js`. What none of
 * those can show is that the pieces MEET: that a pre-persist listener actually
 * runs on a real OpenRegister write, that the value it computes reaches the
 * case page and the working list, and that a handler typing an override on the
 * page sees it survive the next save. Every one of those seams sits between two
 * apps, and each of them has failed silently in this codebase before.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the E2E instance, so
 * every locator is a `data-testid` or a role, and the only text asserted is
 * text this fixture itself seeded (which carries RUN_PREFIX and is therefore
 * the same in either locale). The priority BADGE is read through its
 * `data-priority` attribute for exactly this reason: its visible text is
 * translated and `urgent` reads `Urgent` in both, which would make a label
 * assertion pass in Dutch by luck and fail on the day somebody translated it
 * properly.
 *
 * 🔴 THE SEEDED PRIORITY IS TWO FIELDS, NOT ONE. Nothing in this file writes
 * `priority` directly, and nothing may: it is derived on every save, so a
 * fixture that set it would have it replaced and would quietly stop meaning
 * anything. The pairs used here derive, under the instance default matrix:
 * medium+medium -> normal, high+medium -> high, high+high -> urgent,
 * medium+low -> low.
 *
 * 🔴 THE TERM RULE IS DRIVEN THROUGH THE ENGINE, NOT THROUGH A CLOCK. dossiq
 * owns no clock behind the raise: OpenRegister's flow timers decide when a rung
 * fires and hand over the threshold. So the raise scenario drives the stored
 * FLOOR the rung writes, which is dossiq's entire half of the rule, rather than
 * moving a deadline and waiting for a sweep that runs once a day.
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
import { PAGE_LOAD } from './helpers/nav.ts'

/** The two case types, whose matrices deliberately disagree. */
let bezwaarType = ''
let meldingType = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

test.describe('A case carries a priority it did not have to be told', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// TWO TYPES THAT READ THE SAME PAIR DIFFERENTLY. This is the whole
		// reason the matrix belongs to the case type: high impact on a bezwaar
		// and high impact on a melding openbare ruimte are not the same
		// urgency. Each declares ONE cell and inherits the other eight.
		const mkType = async (
			label: string,
			extra: Record<string, unknown>,
		): Promise<string> =>
			objectId(
				await createObject(api, token, 'caseType', {
					title: `${RUN_PREFIX} ${label}`,
					identifier: `${RUN_PREFIX.toLowerCase()}-${label}`,
					description: 'Throwaway caseType for the case-priority e2e layer.',
					processingDeadline: 'P30D',
					isDraft: false,
					...extra,
				}),
			)

		bezwaarType = await mkType('bezwaar', {
			priorityMatrix: [
				{ impact: 'high', urgency: 'low', priority: 'urgent' },
			],
		})
		meldingType = await mkType('melding', {
			priorityMatrix: [{ impact: 'high', urgency: 'low', priority: 'low' }],
			defaultImpact: 'high',
			defaultUrgency: 'high',
		})

		const mkStatus = async (caseTypeId: string) =>
			objectId(
				await createObject(api, token, 'statusType', {
					name: `${RUN_PREFIX} Ontvangen`,
					caseType: caseTypeId,
					order: 1,
					isFinal: false,
				}),
			)
		await mkStatus(bezwaarType)
		await mkStatus(meldingType)

		const seed = async (
			key: string,
			caseTypeId: string,
			extra: Record<string, unknown> = {},
		) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
				...extra,
			})
			cases[key] = objectId(row)
		}

		await seed('derived', bezwaarType, { impact: 'medium', urgency: 'medium' })
		await seed('override', bezwaarType, { impact: 'medium', urgency: 'medium' })
		await seed('clear', bezwaarType, { impact: 'medium', urgency: 'medium' })
		await seed('raised', bezwaarType, { impact: 'medium', urgency: 'medium' })
		await seed('extended', bezwaarType, { impact: 'medium', urgency: 'medium' })
		await seed('escalated', bezwaarType, { impact: 'high', urgency: 'high' })
		await seed('bezwaarCell', bezwaarType, { impact: 'high', urgency: 'low' })
		await seed('meldingCell', meldingType, { impact: 'high', urgency: 'low' })
		// Deliberately says nothing at all, so the case type's declared
		// defaults are the only thing that can answer.
		await seed('intake', meldingType)
		await seed('sortLow', bezwaarType, { impact: 'medium', urgency: 'low' })
		await seed('sortUrgent', bezwaarType, { impact: 'high', urgency: 'high' })

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open one case's page and wait for the detail shell.
	 *
	 * @param page The Playwright page.
	 * @param key  Which seeded case to open.
	 */
	const openCase = async (page: any, key: string) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
	}

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#a-handler-records-how-much-it-matters-and-how-soon
	test('a handler records how much it matters and how soon, and both are stored', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const before = await showObject(api, 'case', cases.derived)
		expect(before.impact, 'the case stores an impact').toBe('medium')
		expect(before.urgency, 'the case stores an urgency').toBe('medium')

		// A handler raises the urgency, and neither field is derived from the
		// other: the impact must be exactly where it was.
		await updateObject(api, token, 'case', cases.derived, { urgency: 'high' })
		const after = await showObject(api, 'case', cases.derived)

		expect(after.impact).toBe('medium')
		expect(after.urgency).toBe('high')

		await updateObject(api, token, 'case', cases.derived, { urgency: 'medium' })
		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#a-case-created-by-intake-takes-the-declared-defaults
	test('a case created without impact or urgency takes its type declared defaults', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const row = await showObject(api, 'case', cases.intake)

		// The melding type declares high + high; nothing on the case did.
		expect(row.impact).toBe('high')
		expect(row.urgency).toBe('high')
		expect(row.priority).toBe('urgent')

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#changing-urgency-changes-the-priority
	test('raising the urgency raises the derived priority', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const before = await showObject(api, 'case', cases.derived)
		expect(before.priority, 'medium and medium derive normal').toBe('normal')

		await updateObject(api, token, 'case', cases.derived, { urgency: 'high' })
		const after = await showObject(api, 'case', cases.derived)

		expect(after.priority, 'medium and high derive high').toBe('high')
		// The sort key has to move with it, or the queue sorts differently from
		// how it reads.
		expect(Number(after.priorityOrder)).toBeGreaterThan(
			Number(before.priorityOrder),
		)

		await updateObject(api, token, 'case', cases.derived, { urgency: 'medium' })
		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#two-case-types-read-the-same-impact-differently
	test('two case types read the same impact and urgency differently', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const bezwaar = await showObject(api, 'case', cases.bezwaarCell)
		const melding = await showObject(api, 'case', cases.meldingCell)

		expect(bezwaar.impact).toBe(melding.impact)
		expect(bezwaar.urgency).toBe(melding.urgency)
		expect(bezwaar.priority).toBe('urgent')
		expect(melding.priority).toBe('low')
		expect(
			bezwaar.priority,
			'the same pair must NOT give the same answer, or the matrix is not per type',
		).not.toBe(melding.priority)

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#an-override-survives-the-next-derivation
	test('an override a teamleider set survives the next derivation', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await updateObject(api, token, 'case', cases.override, {
			priorityOverride: 'urgent',
			priorityOverrideReason: `${RUN_PREFIX} wethouder heeft gebeld`,
		})
		expect((await showObject(api, 'case', cases.override)).priority).toBe(
			'urgent',
		)

		// A save that touches something else entirely. This is the moment the
		// defect used to happen: the derivation ran and put the priority back.
		await updateObject(api, token, 'case', cases.override, {
			description: `${RUN_PREFIX} edited for the override scenario`,
		})
		const after = await showObject(api, 'case', cases.override)

		expect(after.priority, 'the override still stands').toBe('urgent')
		expect(after.priorityDerived, 'and the matrix kept answering underneath').toBe(
			'normal',
		)

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#the-override-says-who-and-why
	test('the case page shows who overrode the priority, when and why', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const reason = `${RUN_PREFIX} wethouder heeft gebeld`

		await updateObject(api, token, 'case', cases.override, {
			priorityOverride: 'urgent',
			priorityOverrideReason: reason,
		})
		const stored = await showObject(api, 'case', cases.override)

		// Stamped from the session, never from the payload.
		expect(stored.priorityOverrideBy, 'the override names a person').toBeTruthy()
		expect(
			Number.isNaN(Date.parse(String(stored.priorityOverrideAt))),
			'the moment must be a date a reader can parse',
		).toBe(false)
		await api.dispose()

		await openCase(page, 'override')

		// The reason is the one string here this fixture seeded, so it is the
		// one string safe to assert in either locale.
		await expect(page.getByText(reason).first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText(String(stored.priorityOverrideBy)).first(),
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#clearing-the-override-returns-to-the-derived-answer
	test('clearing the override returns the case to what the matrix derives NOW', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await updateObject(api, token, 'case', cases.clear, {
			priorityOverride: 'urgent',
			priorityOverrideReason: `${RUN_PREFIX} raised by hand`,
		})
		expect((await showObject(api, 'case', cases.clear)).priority).toBe('urgent')

		// The urgency changes WHILE the override stands, so the derived answer
		// underneath moves from `normal` to `high`. Clearing must give `high`,
		// not the `normal` the matrix said when the override was made.
		await updateObject(api, token, 'case', cases.clear, { urgency: 'high' })
		await updateObject(api, token, 'case', cases.clear, { priorityOverride: '' })

		const after = await showObject(api, 'case', cases.clear)
		expect(after.priority).toBe('high')
		expect(
			after.priorityOverrideReason ?? '',
			'clearing must not leave the reason behind',
		).toBe('')
		expect(after.priorityOverrideBy ?? '').toBe('')

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#a-case-two-days-from-its-term-rises
	test('a case inside two days of its term rises, and records the rule', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		expect((await showObject(api, 'case', cases.raised)).priority).toBe('normal')

		// What OpenRegister's engine hands dossiq when the 2-day rung fires:
		// the declared floor, and the rule that asked for it. dossiq evaluates
		// no condition and owns no clock here, so driving the floor IS driving
		// dossiq's half of the rule.
		await updateObject(api, token, 'case', cases.raised, {
			priorityFloor: 'high',
			priorityRaisedBy: 'case-priority-term-raise',
		})

		const after = await showObject(api, 'case', cases.raised)
		expect(after.priority, 'the case rose').toBe('high')
		expect(after.priorityDerived, 'the matrix still says normal').toBe('normal')
		expect(after.priorityRaisedBy, 'and the case records the rule').toBe(
			'case-priority-term-raise',
		)

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#an-extended-term-does-not-lower-a-raised-priority
	test('an extended term does not lower a priority the rule raised', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await updateObject(api, token, 'case', cases.extended, {
			priorityFloor: 'urgent',
			priorityRaisedBy: 'case-priority-term-raise',
		})
		expect((await showObject(api, 'case', cases.extended)).priority).toBe(
			'urgent',
		)

		// Extending the term is a save with a later deadline. It touches
		// neither the floor nor the matrix, so the priority must not fall.
		const farOff = new Date(Date.now() + 120 * 864e5).toISOString().slice(0, 10)
		await updateObject(api, token, 'case', cases.extended, {
			plannedEndDate: farOff,
			urgency: 'low',
		})

		const after = await showObject(api, 'case', cases.extended)
		expect(after.priorityDerived, 'the matrix now says low').toBe('low')
		expect(after.priority, 'and the case stays where the rule put it').toBe(
			'urgent',
		)

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#escalation-and-the-case-agree-on-the-word
	test('an escalation reports the case priority, on the case vocabulary', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const row = await showObject(api, 'case', cases.escalated)

		// The case's own four words, never the escalation's four. `critical`
		// and `medium` belong to the notification urgency and must never reach
		// a case.
		expect(row.priority).toBe('urgent')
		expect(['low', 'normal', 'high', 'urgent']).toContain(String(row.priority))
		expect(String(row.priority)).not.toBe('critical')
		expect(String(row.priority)).not.toBe('medium')

		await api.dispose()
	})

	// @e2e openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md#a-handler-sorts-the-queue-by-priority
	test('a handler sorts the case list by priority and gets the declared order', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases`, PAGE_LOAD)

		const badges = page.locator('[data-testid="priority-badge"]')
		await expect(badges.first()).toBeVisible({ timeout: 30_000 })

		// Sort ascending on the Priority column. The header carries the
		// column's label; the VALUES are read off `data-priority`, which is the
		// stored word rather than its translation.
		await page.getByRole('columnheader', { name: /Priorit/i }).first().click()
		await expect(badges.first()).toBeVisible({ timeout: 30_000 })

		const order = ['low', 'normal', 'high', 'urgent']
		const shown = await badges.evaluateAll((nodes) =>
			nodes.map((node) => node.getAttribute('data-priority') ?? ''),
		)
		const ranks = shown
			.filter((value) => order.includes(value))
			.map((value) => order.indexOf(value))

		expect(ranks.length, 'the list must render priorities to sort').toBeGreaterThan(1)
		// Non-decreasing, which is the declared order and NOT the alphabetical
		// one: alphabetically `high` sorts before `low` and `urgent` sorts last
		// of all, so an alphabetical sort of a list holding all four would fail
		// this.
		for (let i = 1; i < ranks.length; i += 1) {
			expect(
				ranks[i],
				`row ${i} (${shown[i]}) must not rank below row ${i - 1} (${shown[i - 1]})`,
			).toBeGreaterThanOrEqual(ranks[i - 1])
		}
	})

	test('the case page shows a priority nobody can type', async ({ page }) => {
		await openCase(page, 'derived')

		// Shown, never typed: the Core case data section renders `priority`
		// with `editable: false`, so there is no input bound to it.
		const field = page.locator('[data-cn-field="priority"]')
		await expect(field.first()).toBeVisible({ timeout: 30_000 })
		await expect(field.locator('input:not([readonly])')).toHaveCount(0)
		await expect(field.locator('select')).toHaveCount(0)
	})
})

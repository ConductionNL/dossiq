/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-type-conditional-rules, the two live checks of tasks 2 and 4: a case
 * type's rule, published, adds its task to a case that meets the condition,
 * and a case saved twice still has that task once.
 *
 * Dossiq evaluates no rule itself: CaseTypeRuleProjector projects each rule at
 * publish onto a guarded OpenRegister flow (object trigger on the case type,
 * filter with the conditions, `dossiq.createTask`), and the task carries
 * `dossiq:once:rule:<case type>:<rule>:<case>` as its engine key. So the
 * evidence is read where it lands: the engine tasks of the case.
 *
 * The flows run in OpenRegister's flow worker, so the spec drives one worker
 * pass (what cron would do) after each save, as case-flow-live-journeys does.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	listFlowTasks,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { occFlowWorkerPass } from './helpers/occ.ts'

/** The task the rule adds. */
const TASK_TITLE = `${RUN_PREFIX} Toets door constructeur`

/**
 * The engine tasks on one case that carry the rule's title.
 *
 * @param api    Authenticated request context.
 * @param caseId The case.
 * @return The tasks.
 */
async function ruleTasks(api: APIRequestContext, caseId: string): Promise<any[]> {
	const tasks = await listFlowTasks(api, { objectUuid: caseId, scope: 'all', limit: '50' })
	return tasks.filter((task) => String(task.title ?? task.name ?? '') === TASK_TITLE)
}

/**
 * One flow worker pass, as cron would run it.
 */
async function workerPass(): Promise<void> {
	const pass = await occFlowWorkerPass()
	expect(pass.code, `The flow worker pass exited ${pass.code}: ${pass.output.trim().slice(0, 400)}`).toBe(0)
}

test.describe('A case type rule adds its task once', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''
	let caseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	test('a published rule adds its task to a case that meets the condition', async () => {
		const machine = await seedStateMachine(api, token)
		await updateObject(api, token, 'caseType', machine.caseTypeId, {
			// What publishing asks of every case type (CaseTypePublishService::validate).
			purpose: 'Een omgevingsvergunning beoordelen.',
			trigger: 'Aanvraag van een inwoner.',
			subject: 'Omgevingsvergunning',
			processingDeadline: 'P56D',
			origin: 'aanvragen',
			confidentiality: 'openbaar',
			responsibleUnit: 'Team Vergunningen',
			rules: [
				{
					id: 'constructeur',
					enabled: true,
					when: { event: 'saved' },
					conditions: [{ kind: 'fieldPresent', field: 'description' }],
					actions: [{ action: 'addTask', title: TASK_TITLE }],
				},
			],
		})

		const published = await api.post(`/index.php/apps/dossiq/api/case-types/${machine.caseTypeId}/publish`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { changeNote: 'conditional rules e2e' },
		})
		expect(published.ok(), `publish -> ${published.status()} ${await published.text()}`).toBeTruthy()

		// A case without the field: the condition does not hold, no task.
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} rule case`,
			caseType: machine.caseTypeId,
		})
		caseId = objectId(seeded)
		await workerPass()
		expect(await ruleTasks(api, caseId), 'no task while the condition does not hold').toHaveLength(0)

		// Saved with the field: the condition holds, the task is added.
		await updateObject(api, token, 'case', caseId, { description: 'Bouwkosten boven de 100.000.' })
		await expect
			.poll(async () => {
				await workerPass()
				return (await ruleTasks(api, caseId)).length
			}, { timeout: 90_000, message: 'the rule adds its task' })
			.toBe(1)
	})

	// @e2e openspec/specs/case-types/spec.md#requirement-a-rules-effect-happens-once-per-rule-case-and-trigger-version
	test('a case saved twice more still has the task once', async () => {
		test.skip(caseId === '', 'the first test seeded no case')
		for (const description of ['Bouwkosten 120.000.', 'Bouwkosten 150.000.']) {
			await updateObject(api, token, 'case', caseId, { description })
			await workerPass()
		}
		await workerPass()

		expect(String((await showObject(api, 'case', caseId)).description)).toBe('Bouwkosten 150.000.')
		expect(await ruleTasks(api, caseId), 'one task per rule and case, however often it is saved').toHaveLength(1)
	})
})

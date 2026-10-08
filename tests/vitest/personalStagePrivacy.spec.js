// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A personal stage is private, and the screen has to say so.
 *
 * 🔴 THE RISK IS TWO STATUSES, NOT A LEAK. The stage is stored as the reader's
 * own preference, so there is no endpoint that could hand it to anybody else.
 * What CAN go wrong is a colleague looking over a shoulder, seeing "Wachten op
 * advies" on a case whose real status is "In behandeling", and acting on the
 * wrong one. So the field says whose it is and what it does not do, every
 * time, in the label a reader cannot miss.
 *
 * The second spec below is the one that keeps it honest next year: the API
 * client has no call that takes a user id, so no future caller can ask for
 * somebody else's stage by adding an argument.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	t: (app, text) => text,
}))

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const field = readFileSync(
	resolve(root, 'src/components/case/PersonalStageField.vue'),
	'utf8',
)
const api = readFileSync(resolve(root, 'src/services/personalQueueApi.js'), 'utf8')
const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
const controller = readFileSync(
	resolve(root, 'lib/Controller/PersonalQueueController.php'),
	'utf8',
)
const en = JSON.parse(
	readFileSync(resolve(root, 'l10n/en.json'), 'utf8'),
).translations
const nl = JSON.parse(
	readFileSync(resolve(root, 'l10n/nl.json'), 'utf8'),
).translations

describe('the field says the stage is yours alone', () => {
	it('tells the reader nobody else sees it', () => {
		expect(field).toContain(
			'Only you can see this. It does not change the case status.',
		)
	})

	it('ships that sentence in Dutch too', () => {
		const key = 'Only you can see this. It does not change the case status.'

		expect(en[key]).toBe(key)
		expect(nl[key]).toBe(
			'Alleen jij ziet dit. Het verandert de status van de zaak niet.',
		)
	})

	it("labels the field as the reader's own", () => {
		expect(field).toContain("t('dossiq', 'Your own stage')")
	})

	it('writes no case status', () => {
		// The field talks to the personal-stage endpoints and nothing else. A
		// status write from here would be the second status this design refuses.
		expect(field).not.toMatch(/status['"]?\s*[:=]/)
		expect(field).toContain('savePersonalStage')
	})
})

describe("nothing can ask for somebody else's stage", () => {
	it('the client takes a case, never a person', () => {
		expect(api).toContain('export async function fetchPersonalStage(caseId)')
		expect(api).toContain(
			'export async function savePersonalStage(caseId, stage)',
		)
		expect(api).not.toMatch(/fetchPersonalStage\([^)]*user/i)
	})

	it('the route carries a case id and no user id', () => {
		expect(routes).toContain("'url' => '/api/personal-queue/stages/{caseId}'")
		expect(routes).not.toMatch(/personal-queue\/[^']*\{user/i)
	})

	it('the controller answers for the caller it resolves itself', () => {
		expect(controller).toContain('private function caller(): string')
		expect(controller).toContain('userSession->getUser()?->getUID()')
		expect(controller).not.toMatch(/getParam\(\s*'user/i)
	})

	it('every queue endpoint refuses an unauthenticated caller', () => {
		const methods = [
			...controller.matchAll(
				/public function (\w+)\([^)]*\): JSONResponse \{\n([^}]*)/g,
			),
		]

		expect(methods.length).toBeGreaterThan(5)
		for (const [, name, body] of methods) {
			expect(
				body,
				`${name} does not refuse an unauthenticated caller`,
			).toContain('return $this->unauthenticated()')
		}
	})
})

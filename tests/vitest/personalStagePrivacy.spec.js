// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A personal stage is private: nobody can ask for somebody else's.
 *
 * The stage is stored as the reader's own preference. The API client has no
 * call that takes a user id, so no future caller can ask for somebody else's
 * stage by adding an argument.
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

const api = readFileSync(resolve(root, 'src/services/personalQueueApi.js'), 'utf8')
const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
const controller = readFileSync(
	resolve(root, 'lib/Controller/PersonalQueueController.php'),
	'utf8',
)

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

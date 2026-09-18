// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Waits on and blocks: the pair that makes a moved term reach the cases
 * behind it.
 *
 * Each assertion guards a way the pair could ship dark:
 *
 *  - a relation type with no entry in `x-openregister-relation-types` is
 *    written but never NAMED, so the Related tab shows the link with no
 *    words and each side reads the near label;
 *  - a typed property whose items carry no `x-openregister-relation` is not
 *    a relation at all: OpenRegister stores the uuids and answers no reverse
 *    rows, so the offer finds nobody waiting and reports nothing wrong;
 *  - the accept and decline endpoints must have routes behind them, or the
 *    task's two buttons are two 404 toasts;
 *  - the days are never in the request body, which is the whole reason the
 *    endpoints take only the case and the task.
 *
 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const register = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'), 'utf8'),
)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const controller = fs.readFileSync(
	path.join(ROOT, 'lib', 'Controller', 'CaseTermFollowController.php'),
	'utf8',
)

const caseSchema = register.components.schemas.case

describe('The waits on and blocks pair', () => {
	it('is declared with both halves of its name', () => {
		const declared = (
			caseSchema.configuration['x-openregister-relation-types'] || []
		).find((type) => type.key === 'waitsOn')

		expect(declared, 'waitsOn is not a declared relation type').toBeTruthy()
		expect(declared.label.nl).toBe('wacht op')
		expect(declared.inverseLabel.nl).toBe('blokkeert')
		expect(declared.symmetric).toBeUndefined()
	})

	it('is carried by a property OpenRegister reads back from the far side', () => {
		const property = caseSchema.properties.blockingCases

		expect(property, 'blockingCases is missing from the case schema').toBeTruthy()
		expect(property.type).toBe('array')
		expect(property.items.$ref).toBe('case')
		expect(property.items['x-openregister-relation'].type).toBe('waitsOn')
	})
})

describe('Accepting or declining a followed move', () => {
	it('has a route behind each answer', () => {
		expect(routes).toContain("'name' => 'caseTermFollow#accept'")
		expect(routes).toContain("'name' => 'caseTermFollow#decline'")
		expect(routes).toContain(
			"'url' => '/api/case/{caseId}/term-follow/{taskId}/accept'",
		)
		expect(routes).toContain(
			"'url' => '/api/case/{caseId}/term-follow/{taskId}/decline'",
		)
	})

	it('takes no number of days from the caller', () => {
		expect(controller).toContain('public function accept(string $caseId, string $taskId)')
		expect(controller).toContain('public function decline(string $caseId, string $taskId)')
		expect(controller).not.toContain('$days')
	})
})

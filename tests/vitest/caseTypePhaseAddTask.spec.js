/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every phase on the case type's phase board adds a task through dossiq's
 * steps endpoint (board DqZaaktype "Taak toevoegen").
 *
 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md#requirement-a-task-is-added-to-a-phase-of-the-draft-workflow
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const board = simple.pages.find((entry) => entry.id === 'CaseTypeDetail').configAppend.widgets.find((widget) => widget.id === 'case-type-phase-board')

describe('add a task to a phase', () => {
	it('posts to the phase\'s steps endpoint from every group', () => {
		const action = board.content.groupAction
		expect(action.type).toBe('open-form')
		expect(action.endpoint).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/phases/@group.id/steps', method: 'POST' })
		expect(Object.keys(action.formProperties)).toEqual(['title', 'description', 'leadTimeDays'])
	})

	it('has a route for that endpoint', () => {
		expect(routes).toMatch(/'caseTypePhases#addStep',\s*'url' => '\/api\/case-types\/\{id\}\/phases\/\{phase\}\/steps',\s*'verb' => 'POST'/)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case and task forms keep a draft (task-forms-keep-a-draft): every page
 * that opens one of them names dossiq's draft key, and only the case forms
 * offer Save draft, on the case's own draft marker.
 *
 * @spec openspec/changes/task-forms-keep-a-draft/specs/task-management/spec.md#requirement-a-task-form-keeps-what-you-typed-req-tm-draft-01
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)

/**
 * One page's config.
 *
 * @param {string} id The page id.
 * @return {object} The config.
 */
const config = (id) => manifest.pages.find((page) => page.id === id).config

describe('task-forms-keep-a-draft', () => {
	it.each(['Cases', 'CaseDetail', 'TaskDetail'])(
		'%s keeps its form drafts under dossiq',
		(id) => {
			expect(config(id).draftAppId).toBe('dossiq')
		},
	)

	it.each(['Cases', 'CaseDetail'])(
		'%s offers Save draft on the case draft marker',
		(id) => {
			expect(config(id).allowDraft).toBe(true)
			expect(config(id).draftField).toBe('isDraft')
			expect(register.components.schemas.case.properties.isDraft.type).toBe(
				'boolean',
			)
		},
	)

	it('the task page offers no Save draft: an engine task has no draft marker', () => {
		expect(config('TaskDetail').allowDraft).toBeUndefined()
	})
})

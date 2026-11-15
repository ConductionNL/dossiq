// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The task form and the case edit form keep a draft (REQ-TM-DRAFT-01).
 *
 * CnDetailPage forwards `draftAppId` to every form it opens (the record edit
 * form and a source verb's form such as the engine task's Edit) and
 * `draftField` to the record forms. Without `draftAppId` a form keeps nothing
 * when it closes, so a handler who loses the tab halfway through starts over.
 * The case's draft marker is the schema's own `isDraft`: a `draftField` the
 * schema does not declare would make Save as provisional inert.
 *
 * @spec openspec/changes/task-forms-keep-a-draft/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'
import manifest from '../../src/manifest.json'

const page = (id) => manifest.pages.find((p) => p.id === id)

describe('forms that keep a draft', () => {
	it.each(['TaskDetail', 'CaseDetail'])('%s keeps unsaved input under dossiq\'s key', (id) => {
		const config = page(id).config
		expect(config.draftAppId).toBe('dossiq')
		expect(config.draftField).toBe('isDraft')
	})

	it('marks a case draft with a property the case schema declares as a boolean', () => {
		const field = page('CaseDetail').config.draftField
		expect(register.components.schemas.case.properties[field]).toMatchObject({ type: 'boolean' })
	})
})

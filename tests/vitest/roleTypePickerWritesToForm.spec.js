// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Role field of the Add party form writes the picked role to the form.
 *
 * The field used to be dossiq's RoleTypePicker, a registry form-field widget
 * that emitted `select` while CnFormDialog listens for `input` and
 * `update:modelValue` only, so a picked role never reached the form. It is
 * now the library's own `resource` widget, which CnFormDialog draws itself
 * and writes through `updateField` (the write and the shown value are asserted
 * in the library's tests/components/CnFormDialogPartyAndResource.spec.js). What
 * this asserts is that dossiq declares that widget and no longer routes the
 * field through a registry component.
 *
 * @spec openspec/specs/roles-decisions/spec.md
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const addParty = manifest.pages
	.find((page) => page.id === 'CaseDetail')
	.config.headerActions.find((action) => action.id === 'add-party')

describe('the Role field inside CnFormDialog', () => {
	it('is the form\'s own resource widget, which writes the picked role uuid', () => {
		expect(addParty.fieldOverrides.roleType.widget).toBe('resource')
		expect(addParty.fieldOverrides.roleType.label).toBe('Role')
	})

	it('is no longer a registry component that could emit past the form', () => {
		expect(registry).not.toContain('RoleTypePicker')
		expect(fs.existsSync(path.join(ROOT, 'src', 'components', 'case', 'RoleTypePicker.vue'))).toBe(false)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a content template looks like as an option of the library's
 * CnTemplateSelect. The select reads `items` and offers nothing when the
 * library could not be read (tested in its tests/components/CnTemplateSelect.spec.js);
 * dossiq owns the shape of one row.
 */

import { describe, expect, it } from 'vitest'
import { contentTemplateOption } from '../../src/utils/templatePicker.js'

describe('contentTemplateOption', () => {
	it('maps a library row onto a pickable option', () => {
		const option = contentTemplateOption({
			id: 'tpl-task',
			kind: 'task',
			name: 'Vraag advies aan juridische zaken',
			body: '',
			presets: { title: 'Vraag advies', group: 'jz', leadTimeDays: 10 },
		})

		expect(option.id).toBe('tpl-task')
		expect(option.name).toBe('Vraag advies aan juridische zaken')
		expect(option.presets.leadTimeDays).toBe(10)
	})

	it('drops a row with no id rather than rendering an option that cannot be chosen', () => {
		expect(contentTemplateOption({ name: 'Nameless' })).toBeNull()
		expect(contentTemplateOption(undefined)).toBeNull()
		expect(contentTemplateOption(null)).toBeNull()
	})

	it('always carries a presets object, so a caller can spread it unchecked', () => {
		const option = contentTemplateOption({ id: 'tpl-1', name: 'No presets' })

		expect(option.presets).toEqual({})
		expect(option.body).toBe('')
	})

	it('falls back to the id when a template has no name', () => {
		expect(contentTemplateOption({ id: 'tpl-1' }).name).toBe('tpl-1')
	})
})

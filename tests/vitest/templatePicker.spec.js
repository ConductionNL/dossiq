/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the template picker offers, given what the library answered.
 *
 * 🔑 THE MALFORMED ANSWER IS THE ONE WORTH PINNING. The library answers 503
 * when the register is unreachable, and a picker that read `data.items` off
 * that body would map over undefined and take the dialog it sits in down with
 * it. An empty offer is the right answer for that, and it is also the right
 * answer for a kind that genuinely has no templates.
 */

import { describe, expect, it } from 'vitest'
import { templateOptions } from '../../src/utils/templatePicker.js'

describe('templateOptions', () => {
	it('maps the library answer onto pickable options', () => {
		const options = templateOptions({
			items: [
				{
					id: 'tpl-task',
					kind: 'task',
					name: 'Vraag advies aan juridische zaken',
					body: '',
					presets: { title: 'Vraag advies', group: 'jz', leadTimeDays: 10 },
				},
			],
			total: 1,
		})

		expect(options).toHaveLength(1)
		expect(options[0].id).toBe('tpl-task')
		expect(options[0].name).toBe('Vraag advies aan juridische zaken')
		expect(options[0].presets.leadTimeDays).toBe(10)
	})

	it('offers nothing when the library could not be read', () => {
		expect(templateOptions(undefined)).toEqual([])
		expect(templateOptions(null)).toEqual([])
		expect(templateOptions({ error: 'The register is not available' })).toEqual([])
	})

	it('offers nothing when the kind genuinely has no templates', () => {
		expect(templateOptions({ items: [], total: 0 })).toEqual([])
	})

	it('drops a row with no id rather than rendering an option that cannot be chosen', () => {
		const options = templateOptions({ items: [{ name: 'Nameless' }, { id: 'tpl-1', name: 'Real' }] })

		expect(options).toHaveLength(1)
		expect(options[0].id).toBe('tpl-1')
	})

	it('always carries a presets object, so a caller can spread it unchecked', () => {
		const options = templateOptions({ items: [{ id: 'tpl-1', name: 'No presets' }] })

		expect(options[0].presets).toEqual({})
		expect(options[0].body).toBe('')
	})

	it('falls back to the id when a template has no name', () => {
		expect(templateOptions({ items: [{ id: 'tpl-1' }] })[0].name).toBe('tpl-1')
	})
})

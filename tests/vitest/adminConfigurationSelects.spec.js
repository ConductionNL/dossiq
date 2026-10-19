/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin Configuratie section picks the register and its schemas by name,
 * while the app config keeps the OpenRegister ids it has always held.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcSelect: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: {} }))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({}),
}))

const { default: axios } = await import('@nextcloud/axios')
const { default: Settings } = await import('../../src/views/settings/Settings.vue')

const t = (app, text) => text

describe('the Configuratie section', () => {
	it('names the stored id by the option that carries it', () => {
		const options = [
			{ id: 172, label: 'Case' },
			{ id: 174, label: 'Role' },
		]
		expect(Settings.methods.optionFor(options, '172')).toEqual({
			id: 172,
			label: 'Case',
		})
	})

	it('keeps a stored id that no option carries yet, and shows nothing when none is stored', () => {
		expect(Settings.methods.optionFor([], 23)).toEqual({ id: 23, label: '23' })
		expect(Settings.methods.optionFor([], '')).toBeNull()
	})

	it('labels a register and a schema by title, then slug, then id', () => {
		const vm = {
			registers: [
				{ id: 23, title: 'Dossiq', slug: 'dossiq' },
				{ id: 24, slug: 'other' },
			],
			schemas: [{ id: 172, title: 'Case' }, { id: 9 }],
			t: (app, text) => text,
		}
		expect(Settings.computed.registerOptions.call(vm)).toEqual([
			{ id: 23, label: 'Dossiq' },
			{ id: 24, label: 'other' },
		])
		expect(Settings.computed.schemaOptions.call(vm)).toEqual([
			{ id: 172, label: 'Case' },
			{ id: 9, label: '9' },
		])
	})

	it('names a schema by its title in the reader\'s language', () => {
		const vm = {
			schemas: [{ id: 172, title: 'Case' }, { id: 173, title: 'Status type' }],
			t: (app, text) => ({ Case: 'Zaak', 'Status type': 'Statustype' })[text] ?? text,
		}
		expect(Settings.computed.schemaOptions.call(vm)).toEqual([
			{ id: 172, label: 'Zaak' },
			{ id: 173, label: 'Statustype' },
		])
	})

	it('asks for the chosen register schemas only, and none without a register', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 172 }] } })
		const vm = { form: { register: 23 }, schemas: [], loadingSchemas: false }
		await Settings.methods.loadSchemas.call(vm)
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/registers/23/schemas',
		)
		expect(vm.schemas).toEqual([{ id: 172 }])

		axios.get.mockClear()
		const empty = { form: { register: '' }, schemas: [{ id: 1 }] }
		await Settings.methods.loadSchemas.call(empty)
		expect(axios.get).not.toHaveBeenCalled()
		expect(empty.schemas).toEqual([])
	})

	it('offers the seven schema settings the app config holds', () => {
		const keys = Settings.computed.schemaFields
			.call({ t })
			.map((field) => field.key)
		expect(keys).toEqual([
			'case_schema',
			'case_type_schema',
			'status_schema',
			'role_schema',
			'result_schema',
			'decision_schema',
			'status_type_schema',
		])
	})
})

describe('every Configuratie select is labelled', () => {
	const source = fs.readFileSync(
		path.resolve(__dirname, '../../src/views/settings/Settings.vue'),
		'utf8',
	)
	const template = source.slice(0, source.indexOf('<script>'))
	const selects = [...template.matchAll(/<NcSelect([\s\S]*?)\/>/g)].map(
		(m) => m[1],
	)

	it('finds the selects it checks', () => {
		expect(selects.length).toBe(2)
	})

	it.each(selects.map((attrs, i) => [i, attrs]))(
		'select %i names its input and keeps the label outside',
		(i, attrs) => {
			const id = attrs.match(/:?inputId="([^"]+)"/)
			expect(id, 'inputId').not.toBeNull()
			expect(attrs).toContain(':labelOutside="true"')
			const forAttr = id[0].startsWith(':')
				? `:for="${id[1]}"`
				: `for="${id[1]}"`
			expect(template).toContain(forAttr)
		},
	)
})

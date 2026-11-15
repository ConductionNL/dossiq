// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A package's admin tab: one card per list the package declares, one row per
 * item it installed here, each row opening the editor the app has for it.
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import PackageAdminTab from '../../src/views/settings/components/PackageAdminTab.vue'
import {
	PACKAGE_LISTS,
	packageItemStatus,
	packageItemUrl,
	packageListLabel,
	packageTabsOf,
} from '../../src/utils/packageAdminTab.js'

const ROOT = path.resolve(__dirname, '../..')
const vth = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/packages/vth/package.json'), 'utf8'))

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section :data-name="name"><h3>{{ name }}</h3><slot /></section>',
	},
}

const pkg = {
	id: 'vth',
	title: 'VTH',
	version: '1.4.0',
	lists: {
		caseTypes: [{ id: 'ct-1', title: 'Sloopmelding', status: '' }],
		flows: [
			{ id: 'fl-1', title: 'Handhavingstraject', status: 'published' },
			{ id: 'fl-2', title: '', status: 'draft' },
		],
		templates: [],
		checklists: [{ id: 'ck-1', title: 'Fundering', status: 'active' }],
	},
}

describe('the VTH package declares its tab', () => {
	it('titles it VTH and lists all six kinds of configuration', () => {
		expect(vth.adminTab).toEqual({ title: 'VTH', lists: PACKAGE_LISTS })
		expect(vth.version).toBe('1.4.0')
	})
})

describe('what a package tab reads', () => {
	it('makes one tab per listed package, titled as the package declares', () => {
		expect(packageTabsOf([{ id: 'vth', title: 'VTH' }, { id: 'woo' }, { title: 'no id' }, null])).toEqual([
			{ id: 'vth', label: 'VTH' },
			{ id: 'woo', label: 'woo' },
		])
		expect(packageTabsOf(undefined)).toEqual([])
	})

	it('opens case types and flows in their own editor, and fee rules and connections where they are kept', () => {
		expect(packageItemUrl('caseTypes', 'ct 1')).toBe('/index.php/apps/dossiq/settings/case-types/ct%201')
		expect(packageItemUrl('flows', 'fl-1')).toBe('/index.php/apps/dossiq/flows/fl-1')
		expect(packageItemUrl('feeRules', 'f')).toBe('/index.php/apps/shillinq/overheid/fee-schedules')
		expect(packageItemUrl('connections', 'c')).toBe('/index.php/apps/dossiq/settings/integrations')
		expect(packageItemUrl('templates', 't')).toBe('')
		expect(packageItemUrl('checklists', 'k')).toBe('')
	})

	it('names each list and each status', () => {
		expect(PACKAGE_LISTS.map(packageListLabel)).toEqual(['Case types', 'Flows', 'Fee rules', 'Templates', 'Connections', 'Inspection checklists'])
		expect(packageListLabel('other')).toBe('other')
		expect(['draft', 'published', 'active', 'paused', undefined].map(packageItemStatus)).toEqual(['Draft', 'Published', 'Active', 'paused', ''])
	})
})

describe('the package tab', () => {
	it('draws a card per declared list, in declared order, with a row per installed item', () => {
		const wrapper = mount(PackageAdminTab, { props: { pkg }, global: { stubs } })

		expect(wrapper.findAll('section').map((section) => section.attributes('data-name'))).toEqual(['Case types', 'Flows', 'Templates', 'Inspection checklists'])
		const flows = wrapper.findAll('[data-testid="package-item-flows"]')
		expect(flows.map((row) => row.text())).toEqual(['HandhavingstrajectPublished', 'fl-2Draft'])
		expect(flows[0].find('a').attributes('href')).toBe('/index.php/apps/dossiq/flows/fl-1')
		expect(wrapper.find('[data-testid="package-item-caseTypes"] a').attributes('href')).toBe('/index.php/apps/dossiq/settings/case-types/ct-1')
		expect(wrapper.find('[data-testid="package-item-checklists"] a').exists()).toBe(false)
		expect(wrapper.text()).toContain('This package installed nothing here.')
	})

	it('draws nothing for a package that lists nothing', () => {
		const wrapper = mount(PackageAdminTab, { props: { pkg: { id: 'x', title: 'X' } }, global: { stubs } })
		expect(wrapper.findAll('section')).toHaveLength(0)
	})
})

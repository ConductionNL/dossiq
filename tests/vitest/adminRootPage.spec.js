// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings page's own logic, run against a plain component context:
 * the tabs with their counts, page title and breadcrumb per tab, the formatters
 * it provides to the library widgets, the sources and links the sections read,
 * and saving the register mapping.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = []
const comp = (name) => ({ default: { name, render: () => null } })
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: comp('NcButton').default, NcNoteCard: comp('NcNoteCard').default }))
vi.mock('../../src/views/admin/SubstitutionAdmin.vue', () => comp('A'))
vi.mock('../../src/views/settings/components/AdviceSettingsFacts.vue', () => comp('B'))
vi.mock('../../src/views/settings/components/DependencyAppsList.vue', () => comp('C'))
vi.mock('../../src/views/settings/components/SearchIndexFacts.vue', () => comp('D'))
vi.mock('../../src/views/settings/components/SettingsFactsList.vue', () => comp('E'))
vi.mock('../../src/views/settings/ShippedConfiguration.vue', () => comp('F'))
vi.mock('../../src/views/settings/StufAuditLog.vue', () => comp('G'))
vi.mock('../../src/views/settings/StufEndpoints.vue', () => comp('H'))
vi.mock('../../src/views/settings/tabs/ChecklistTemplatesTab.vue', () => comp('I'))
vi.mock('../../src/views/settings/tabs/DeadlineDefinitionsTab.vue', () => comp('J'))
vi.mock('../../src/views/settings/tabs/DelegationMatrixTab.vue', () => comp('K'))
vi.mock('../../src/views/settings/tabs/FirstRunTab.vue', () => comp('L'))
vi.mock('../../src/views/settings/tabs/PrerequisitesTab.vue', () => comp('M'))
vi.mock('../../src/views/settings/tabs/SearchIndexTab.vue', () => comp('N'))
vi.mock('../../src/views/settings/tabs/TenantOnboardingTab.vue', () => comp('O'))
vi.mock('../../src/views/settings/ZgwMappingSettings.vue', () => comp('P'))
vi.mock('../../src/services/adminTabCounts.js', () => ({ fetchAdminTabCounts: vi.fn(async () => ({ vth: 3 })) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn(async () => {}) }))

const { default: AdminRoot } = await import('../../src/views/settings/AdminRoot.vue')
const stores = await import('../../src/store/store.js')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * An admin page context with its computed values as getters and methods bound.
 *
 * @return {object} The context.
 */
function page() {
	const ctx = { ...AdminRoot.data.call({}), t }
	for (const [name, def] of Object.entries(AdminRoot.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(AdminRoot.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	axios.get.mockReset()
	axios.put.mockReset()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('tabs and titles', () => {
	it('lists the tabs, adding a count where one is known', () => {
		const p = page()
		expect(p.adminTabs.map((tab) => tab.id)).toEqual(['general', 'system', 'integrations', 'mail', 'map-layers', 'vth', 'substitution', 'advanced'])
		p.tabCounts = { vth: 3, mail: 'x' }
		expect(p.adminTabs.find((tab) => tab.id === 'vth').count).toBe(3)
		expect('count' in p.adminTabs.find((tab) => tab.id === 'mail')).toBe(false)
	})

	it('titles the page after the tab, keeping an abbreviation in capitals', () => {
		const p = page()
		expect([p.activeTabLabel, p.pageTitle]).toEqual(['General', 'Dossiq administration: general'])
		p.onTabChange('vth')
		expect(p.pageTitle).toBe('Dossiq administration: VTH')
		p.activeTab = 'gone'
		expect(p.activeTabLabel).toBe('Dossiq settings')
	})

	it('builds the breadcrumb and its note per tab', () => {
		const p = page()
		expect(p.breadcrumbs.map((crumb) => crumb.label)).toEqual(['Admin settings', 'Dossiq', 'General'])
		expect(p.breadcrumbs[0].href).toBe('/index.php/settings/admin')
		expect(p.breadcrumbNote).toBe('applies to the whole organisation')
		p.activeTab = 'vth'
		expect(p.breadcrumbNote).toContain('permits, supervision and enforcement')
		p.activeTab = 'advanced'
		expect(p.breadcrumbNote).toContain('settings you set once or with a specialist')
		p.activeTab = 'mail'
		expect(p.breadcrumbNote).toContain('case types, flows and departments are in the app')
	})
})

describe('what the sections read', () => {
	it('reads the connections of this app from integriq, newest order first, and links each one', () => {
		const p = page()
		expect(p.connectionsSource).toEqual({ register: 'integriq', schema: 'app_connection', filter: { app: 'dossiq' }, order: { order: 'asc' }, limit: 50 })
		expect(p.connectionRowActions[0].href({ settingsUrl: '/x' })).toBe('/x')
		const title = p.connectionColumns.find((c) => c.key === 'title')
		expect([title.secondary({ description: 'Mail' }), title.secondary({})]).toEqual(['Mail', ''])
		expect(p.connectionColumns.find((c) => c.key === 'status').enumLabels.unconfigured).toBe('Off')
	})

	it('points the links at their pages', () => {
		const p = page()
		expect(p.mailIntakeLogUrl).toBe('/index.php/apps/dossiq/mail-intake-log')
		expect(p.integriqAdminUrl).toBe('/index.php/settings/admin/integriq')
		expect(p.allTermDefinitionsUrl).toBe('/index.php/settings/admin/dossiq?tab=advanced#section-term-definitions-all')
		expect([p.feeSchedulesUrl, p.mapLayersUrl, p.caseTypesUrl]).toEqual(['/index.php/apps/shillinq/overheid/fee-schedules', '/index.php/apps/dossiq/settings/wms-layers', '/index.php/apps/dossiq/settings/case-types'])
		expect(p.integriqConnectionsUrl).toMatch(/^\/index\.php\/apps\/integriq\//)
	})

	it('reads the enforcement matrix and the working calendar', () => {
		const p = page()
		expect(p.enforcementMatrixSource).toEqual({ register: 'dossiq', schema: 'lhsMatrix', limit: 10 })
		expect(p.enforcementMatrixFacts.source).toEqual({ register: 'dossiq', schema: 'lhsMatrix', filter: { active: true } })
		expect(p.enforcementMatrixColumns.map((c) => c.key)).toEqual(['name', 'version', 'active', '@self.updated'])
		expect(p.workingCalendarContent.calendar).toEqual({ register: 'flow-timers', schema: 'working-calendar', filter: { slug: 'nl-national' } })
		expect(p.intakeActivitySource).toEqual({ url: '/apps/integriq/api/admin/dso-activities', responsePath: 'results' })
		expect(p.intakeActivityColumns[0].pills[0]).toEqual({ field: 'isActive', equals: false, label: 'Inactive' })
	})

	it('builds every field section from the shared field lists', () => {
		const p = page()
		const sections = ['kadasterFields', 'houseStyleFields', 'textsAndAccessFields', 'menuStructureFields', 'queueUrgencyFields', 'consultationFields', 'reviewReportFields', 'paymentCallbackFields', 'storeRegistryFields', 'archivalFields', 'sharedMailboxFields', 'aiFields', 'backgroundAccountFields', 'contactCentreFields', 'queueOrderFields', 'registerGroups', 'feeScheduleColumns', 'wooRefusalGroundColumns', 'publicationRows']
		for (const name of sections) {
			expect(Array.isArray(p[name]), name).toBe(true)
			expect(p[name].length, name).toBeGreaterThan(0)
		}
		expect(Array.isArray(p.setupSteps)).toBe(true)
	})
})

describe('provided formatters', () => {
	it('translates strings and formats fee rules for the library widgets', () => {
		const provided = AdminRoot.provide.call({ t })
		expect(provided.cnTranslate('Hello {name}', { name: 'Ann' })).toBe('Hello Ann')
		expect([provided.cnFormatters.translated('Text'), provided.cnFormatters.translated(''), provided.cnFormatters.translated(4)]).toEqual(['Text', '', 4])
		expect(typeof provided.cnFormatters.feeRuleName(null, { name: 'Leges' })).toBe('string')
	})
})

describe('loading and saving', () => {
	it('loads the register configuration, empty when it cannot', async () => {
		const p = page()
		axios.get.mockResolvedValueOnce({ data: { config: { register: 'dossiq' } } })
		await p.loadRegisterConfiguration()
		expect(p.registerConfiguration).toEqual({ register: 'dossiq' })
		axios.get.mockRejectedValueOnce(new Error('x'))
		await p.loadRegisterConfiguration()
		expect(p.registerConfiguration).toEqual({})
	})

	it('saves the register mapping, or shows why not', async () => {
		const p = page()
		axios.put.mockResolvedValueOnce({ data: { config: { register: 'saved' } } })
		await p.saveRegisterMapping({ register: 'x' })
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/dossiq/api/settings', { register: 'x' })
		expect([p.registerConfiguration, p.registerSaving]).toEqual([{ register: 'saved' }, false])
		axios.put.mockResolvedValueOnce({ data: {} })
		await p.saveRegisterMapping({ register: 'y' })
		expect(p.registerConfiguration).toEqual({ register: 'y' })
		axios.put.mockRejectedValueOnce({ response: { data: { message: 'Schema missing' } } })
		await p.saveRegisterMapping({})
		axios.put.mockRejectedValueOnce({})
		await p.saveRegisterMapping({})
		expect(toasts).toEqual([['success', 'Configuration saved'], ['success', 'Configuration saved'], ['error', 'Schema missing'], ['error', 'That change could not be saved.']])
	})

	it('reloads the stores after a reimport', async () => {
		const p = page()
		p.storesReady = true
		const done = p.onReimported()
		expect(p.storesReady).toBe(false)
		await done
		expect([p.storesReady, stores.initializeStores.mock.calls.length]).toEqual([true, 1])
	})
})

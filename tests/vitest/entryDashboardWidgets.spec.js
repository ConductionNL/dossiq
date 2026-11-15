/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboard widget entry: which widgets it registers with the library,
 * what each one reads, and how the widget app is set up.
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

const registered = vi.hoisted(() => ({ widgets: null, options: null }))

vi.mock('@conduction/nextcloud-vue', () => ({
	registerNcDashboardWidgets: (widgets, options) => {
		registered.widgets = widgets
		registered.options = options
	},
}))
vi.mock('@conduction/nextcloud-vue/css/index.css', () => ({}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one, other, count) => (count === 1 ? one : other) }))
vi.mock('../../src/views/widgets/StartCaseWidget.vue', () => ({ default: { name: 'StartCaseWidget' } }))
vi.mock('../../src/pinia.js', () => ({ default: { name: 'pinia' } }))

const byId = (id) => registered.widgets.find((widget) => widget.id === id)

describe('dashboardWidgets entry', () => {
	beforeAll(async () => {
		await import('../../src/dashboardWidgets.js')
	})

	it('registers every dossiq widget once, for the dossiq app', () => {
		const ids = registered.widgets.map((widget) => widget.id)

		expect(new Set(ids).size).toBe(ids.length)
		expect(ids).toContain('procest_cases_overview_widget')
		expect(ids).toContain('procest_overdue_cases_widget')
		expect(ids).toContain('procest_deadline_alerts_widget')
		expect(ids).toContain('procest_stalled_cases_widget')
		expect(ids[ids.length - 1]).toBe('procest_start_case_widget')
		expect(registered.options.appId).toBe('dossiq')
	})

	it('reads only open cases, ordered by deadline', () => {
		const overview = byId('procest_cases_overview_widget').config

		expect(overview.source).toEqual({ register: 'dossiq', schema: 'case', params: { isFinalStatus: { ne: true, orNull: true }, _order: { deadline: 'asc' } } })
		expect(overview.href).toBe('/apps/dossiq/cases/{id}')
		expect(overview.viewAll).toEqual({ href: '/apps/dossiq/cases' })
	})

	it('selects overdue, nearly due and stalled cases by their own windows', () => {
		expect(byId('procest_overdue_cases_widget').config.source.params.deadline).toEqual({ lt: '@today' })
		expect(byId('procest_deadline_alerts_widget').config.source.params.deadline).toEqual({ lte: '@today+3d' })
		expect(byId('procest_deadline_alerts_widget').config.due).toEqual({ field: 'deadline', todayLabel: 'Due today', upcomingLabel: { one: '{count} day remaining', other: '{count} days remaining' } })
		expect(byId('procest_stalled_cases_widget').config.source.params['@self']).toEqual({ lastUserActivity: { lt: '@today-7d', orNull: true } })
	})

	it('mounts the start-case widget as a component', () => {
		expect(byId('procest_start_case_widget')).toEqual({ id: 'procest_start_case_widget', component: { name: 'StartCaseWidget' } })
	})

	it('sets the widget app up with pinia and the translation helpers', () => {
		const app = { use: vi.fn(), config: { globalProperties: {} } }

		registered.options.setup(app)

		expect(app.use).toHaveBeenCalledWith({ name: 'pinia' })
		expect(app.config.globalProperties.t('dossiq', 'Open')).toBe('Open')
		expect(app.config.globalProperties.n('dossiq', 'one case', 'cases', 2)).toBe('cases')
	})
})

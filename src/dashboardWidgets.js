/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * dossiq's Nextcloud Dashboard widgets, registered from configuration with
 * the library's registrar. Each signal is a filter OpenRegister runs, not a
 * list the browser computes from a thousand cases.
 *
 * The ids are FROZEN at the old `procest_*` prefix: Nextcloud's Dashboard app
 * stores each user's chosen widgets by id (see CasesOverviewWidget::getId()).
 *
 * A case is open while `isFinalStatus` is not true; `orNull` keeps a case
 * whose flag was never written. A case is stalled when no person has written
 * it for seven days: `@self.lastUserActivity` is stamped only on a person's
 * write, and `orNull` keeps a case nobody has written since it was added.
 * Both need the OpenRegister release that adds `orNull` and that field.
 *
 * @spec openspec/specs/signalering-widgets/spec.md
 */

import { registerNcDashboardWidgets } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import StartCaseWidget from './views/widgets/StartCaseWidget.vue'
import pinia from './pinia.js'

import '@conduction/nextcloud-vue/css/index.css'

const OPEN = { isFinalStatus: { ne: true, orNull: true } }
const CASE_HREF = '/apps/dossiq/cases/{id}'
const CASES = { href: '/apps/dossiq/cases' }
const TASK_HREF = '/apps/dossiq/tasks/{uuid}'
const TASKS = { href: '/apps/dossiq/tasks' }
const MY_TASKS = { url: '/apps/openregister/api/flow-tasks', responsePath: 'results' }
// A date still to come reads as the days left, as the widgets always said it.
const DUE = { todayLabel: 'Due today', upcomingLabel: { one: '{count} day remaining', other: '{count} days remaining' } }

registerNcDashboardWidgets([
	{
		id: 'procest_cases_overview_widget',
		config: {
			source: { register: 'dossiq', schema: 'case', params: { ...OPEN, _order: { deadline: 'asc' } } },
			subtitle: '#{identifier}',
			href: CASE_HREF,
			viewAll: CASES,
			emptyText: 'No cases found',
		},
	},
	{
		id: 'procest_overdue_cases_widget',
		config: {
			source: { register: 'dossiq', schema: 'case', params: { ...OPEN, deadline: { lt: '@today' }, _order: { deadline: 'asc' } } },
			due: { field: 'deadline' },
			href: CASE_HREF,
			viewAll: CASES,
			emptyText: 'No open cases',
		},
	},
	{
		id: 'procest_deadline_alerts_widget',
		config: {
			source: { register: 'dossiq', schema: 'case', params: { ...OPEN, deadline: { lte: '@today+3d' }, _order: { deadline: 'asc' } } },
			limit: 10,
			due: { field: 'deadline', ...DUE },
			href: CASE_HREF,
			viewAll: CASES,
			emptyText: 'No deadline alerts',
		},
	},
	{
		id: 'procest_stalled_cases_widget',
		config: {
			source: { register: 'dossiq', schema: 'case', params: { ...OPEN, '@self': { lastUserActivity: { lt: '@today-7d', orNull: true } }, _order: { '@self.lastUserActivity': 'asc' } } },
			limit: 5,
			subtitle: '{@self.lastUserActivity|days} days inactive',
			href: CASE_HREF,
			viewAll: CASES,
			emptyText: 'All cases active',
		},
	},
	{
		id: 'procest_my_tasks_widget',
		config: {
			source: { ...MY_TASKS, params: { scope: 'assigned', isTerminal: 'false', sort: 'due' } },
			due: { field: 'dueAt', todayLabel: 'Due today', emptyText: 'No deadline' },
			href: TASK_HREF,
			viewAll: TASKS,
			emptyText: 'No tasks found',
		},
	},
	{
		id: 'procest_task_reminders_widget',
		config: {
			source: { ...MY_TASKS, params: { scope: 'assigned', isTerminal: 'false', dueBefore: '@today+3d', sort: 'due' } },
			limit: 10,
			due: { field: 'dueAt', ...DUE },
			href: TASK_HREF,
			viewAll: TASKS,
			emptyText: 'No task reminders',
		},
	},
	{ id: 'procest_start_case_widget', component: StartCaseWidget },
], {
	appId: 'dossiq',
	setup(app) {
		app.use(pinia)
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
	},
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The widget declarations, as the manifest carries them.
 *
 * Two of these assertions exist because the failure they catch is silent.
 *
 * A DECLARATION WITH NO PLACEMENT IS A RULE ABOUT NOTHING, and a placement
 * whose widget declares roles while the definition does not is a rule in the
 * wrong half: the audience belongs on the DEFINITION, because a widget placed
 * twice must not be able to have two audiences that disagree.
 *
 * A ROLE THAT APPEARS IN ONE WIDGET AND NOWHERE ELSE is usually a typo, and a
 * typo in a role name is not a smaller version of the right rule: it hides the
 * widget from everyone (ADR-102) and looks exactly like a widget somebody
 * removed. The vocabulary is asserted as a set so a new spelling has to be a
 * deliberate addition here too.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

/** Every widget DEFINITION on a dashboard page, with the page it is on. */
const dashboardWidgets = manifest.pages
	.filter((page) => page.type === 'dashboard')
	.flatMap((page) =>
		[...(page.config?.widgets ?? []), ...(page.widgets ?? [])]
			.filter(
				(widget) => widget && widget.id && widget.widgetKey === undefined,
			)
			.map((widget) => ({ page: page.id, widget })),
	)

const declared = dashboardWidgets.filter(
	({ widget }) => Array.isArray(widget.roles) && widget.roles.length > 0,
)

describe('the audience is declared on the widget definition', () => {
	it('declares roles on the managerial dashboards', () => {
		const pages = new Set(declared.map(({ page }) => page))
		expect(pages).toEqual(
			new Set([
				'Dashboard',
				'Doorlooptijd',
				'ProcessMiningDashboard',
				'TermijnDashboard',
			]),
		)
	})

	it('names one role vocabulary and not a spelling per widget', () => {
		const roles = new Set(declared.flatMap(({ widget }) => widget.roles))
		// A typo here hides a widget from everybody and reads as a widget
		// somebody deleted, so a new spelling is a deliberate edit to this set.
		//
		// The three names are the groups `ProvisionAssignedGroups` creates and
		// the tile's own endpoint already enforces. The set read
		// `dossiq-teamleider` until #2947, which replaced it because nothing
		// created that group, so every tile named an audience with no members.
		expect(roles).toEqual(new Set(['controllers', 'beheerders', 'admin']))
	})

	it('says why, beside every declaration', () => {
		for (const { widget } of declared) {
			expect(
				widget._rolesNote,
				`${widget.id} declares roles and does not say why`,
			).toBeTruthy()
			expect(widget._rolesNote.length).toBeGreaterThan(40)
		}
	})

	it('puts the audience on the definition and never on a placement', () => {
		const placements = manifest.pages
			.filter((page) => page.type === 'dashboard')
			.flatMap((page) => [
				...(page.config?.layout ?? []),
				...(page.widgets ?? []),
			])
			.filter((entry) => entry && entry.widgetKey !== undefined)

		for (const placement of placements) {
			// A widget placed twice must not be able to carry two audiences
			// that disagree, so a placement carries none.
			expect(
				placement.roles,
				`${placement.widgetKey} carries roles on its placement`,
			).toBeUndefined()
		}
	})

	it("leaves the reader's own work undeclared, which the allowlist explains", () => {
		const ids = dashboardWidgets.map(({ widget }) => widget.id)
		// The tiles about the reader's own work are deliberately public. The
		// reason lives in WidgetDeclaresRolesTest, which fails on a widget
		// that is neither declared nor listed there.
		expect(ids).toContain('kpi-my-tasks')
		const myTasks = dashboardWidgets.find(
			({ widget }) => widget.id === 'kpi-my-tasks',
		).widget
		expect(myTasks.roles).toBeUndefined()
	})
})

describe('the figure behind a restricted widget is a server-side concern', () => {
	it('binds the SLA tile to a field the endpoint can withhold', () => {
		const sla = dashboardWidgets.find(
			({ widget }) => widget.id === 'kpi-sla-compliance',
		).widget
		// The enforcement removes FIELDS from the payload, so a restricted
		// tile must name the field it reads rather than computing it in the
		// browser from something else.
		expect(sla.content.valueField).toBeTruthy()
		expect(sla.content.endpointSource?.url).toContain('/api/dashboard/kpis')
	})
})

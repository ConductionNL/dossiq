/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The way in to the data model, and who gets to see it.
 *
 * Every dossiq schema is registered in OpenRegister, whose register page
 * lists each schema with its properties. dossiq had no way in: one cross-app
 * link, `AvgRegisterLink`, and nothing pointing at the model itself. This
 * change adds the entry and the two cards that reach the same page.
 *
 * WHAT THIS FILE GUARDS, and why each clause is worth a test rather than a
 * reading of the manifest.
 *
 * `section: "integrations"` is NOT written on the entry. ADR-110 moved that
 * decision into `src/menu-layout.json`, and `applyIntegrationsSection` stamps
 * the key on as the effective manifest is built. So the declaration and the
 * effect live in two files, and the test below runs the real builder over the
 * real pair rather than asserting the same fact twice.
 *
 * `permission: "admin"` is enforced on this surface and NOT on every other.
 * CnAppRoot filters the Integrations list through `passesIntegrationPermission`
 * against the `permissions` prop App.vue feeds from `currentPermissions()`.
 * The same word on a page `config.actions[]` entry is read by nothing, which
 * is why the object-type link below is gated by `visibleIf` instead.
 *
 * `visibleIf: { "user.isAdmin": true }` resolves against `manifest.runtime`,
 * and `passesContextPredicates` hides an entry whenever runtime is missing.
 * A card gated this way against a manifest carrying no runtime is invisible
 * to everyone, admin included, and nothing warns. Hence the runtime assertion.
 *
 * An icon outside `src/icons.js` renders a question mark rather than failing.
 *
 * @spec openspec/changes/data-model-link/specs/admin-settings/spec.md
 */

// The REAL utilities, by source path. `vitest.config.js` aliases the package
// barrel to a stub, so importing them from `@conduction/nextcloud-vue` gives
// back undefined and every assertion below would be run against a mock of
// this change's own subject.
import {
	applyIntegrationsSection,
	applyMenuLayout,
} from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { passesContextPredicates } from '@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const menuLayout = readJson('src', 'menu-layout.json')
const iconsSource = read('src', 'icons.js')
const mainSource = read('src', 'main.js')
const registerSource = read('src', 'components', 'case', 'registerCaseSections.js')

/** OpenRegister's schema list for the dossiq register. One target, two doors. */
const TARGET = '/apps/openregister/#/registers/dossiq'

const entry = manifest.menu.find((m) => m.id === 'DataModelLink')
const page = (id) => manifest.pages.find((p) => p.id === id)

/**
 * The Object types section of the case page's Related tab.
 *
 * @return {object|undefined} The section, `{ label, widget }`.
 */
function caseObjectTypesSection() {
	const related = page('CaseDetail').config.widgets.find((w) => w.id === 'case-related-panel')
	return related?.content?.sections?.find((sec) => sec.widget?.id === 'case-object-types-link')
}

/**
 * The Manage object types card on a page, by the page's id.
 *
 * Two spellings, because the two page types host a widget two ways. An index
 * page takes a page-level `widgets[]` entry (`widgetKey`, `props`); a detail
 * page that already has `config.widgets` may NOT, since carrying both is the
 * render-path shadowing gate-55 refuses, so there the card is a case section
 * (`type`, `content`).
 *
 * @param {string} pageId The page id.
 * @return {object|undefined} The card entry.
 */
function objectTypesCard(pageId) {
	const entries = pageId === 'CaseDetail'
		? caseObjectTypesSection()?.widget?.content?.entries
		: (page(pageId).widgets ?? []).find((w) => w.widgetKey === 'nav-card-grid')?.props?.entries
	return entries?.find((e) => e.id === 'manage-object-types')
}

describe('the Data model entry', () => {
	it('is declared, and points at the dossiq register', () => {
		expect(entry).toBeDefined()
		expect(entry.label).toBe('Data model')
		expect(entry.href).toBe(TARGET)
		expect(entry.route).toBeUndefined()
	})

	it('names an icon src/icons.js registers (gate-60)', () => {
		expect(entry.icon).toBe('DatabaseCogOutline')
		expect(iconsSource).toContain(`\n\t${entry.icon},`)
	})

	it('is gated on OpenRegister being installed', () => {
		// Without this the Integrations section advertises a 404 as a feature.
		expect(entry.visibleIf.appInstalled).toBe('openregister')
	})

	it('is admin-only', () => {
		expect(entry.permission).toBe('admin')
	})

	it('leaves the section to menu-layout.json, and is listed there', () => {
		expect(entry.section).toBeUndefined()
		expect(menuLayout.integrationsSection).toContain('DataModelLink')
		expect(menuLayout.removals).not.toContain('DataModelLink')
		expect(menuLayout.settingsSection).not.toContain('DataModelLink')
	})

	it('lands in the integrations section of the effective manifest', () => {
		// The builder, not a second reading of the two files. A declaration
		// the build then ignores fails here rather than passing above.
		const built = applyMenuLayout(manifest.menu, menuLayout)
		const effective = built.find((m) => m.id === 'DataModelLink')

		expect(effective).toBeDefined()
		expect(effective.section).toBe('integrations')
		expect(effective.href).toBe(TARGET)
		expect(effective.permission).toBe('admin')
	})

	it('is not in the navigation', () => {
		// The point of ADR-110: a link that leaves the app can never be the
		// active route, and in the nav it reads as a feature of this app.
		const built = applyMenuLayout(manifest.menu, menuLayout)
		const inNav = built.filter((m) => (m.section ?? 'main') === 'main')

		expect(inNav.map((m) => m.id)).not.toContain('DataModelLink')
	})

	it('renders in Integrations only for an account holding admin', () => {
		// The shape CnAppRoot.passesIntegrationPermission applies, run over
		// the two lists `utils/permissions.js` can actually produce.
		const lifted = applyIntegrationsSection(
			manifest.menu,
			menuLayout.integrationsSection,
		)
		const visibleTo = (permissions) =>
			lifted
				.filter((item) => item.section === 'integrations')
				.filter((item) => !item.permission || permissions.includes(item.permission))
				.map((item) => item.id)

		expect(visibleTo(['user', 'admin'])).toContain('DataModelLink')
		expect(visibleTo(['user'])).not.toContain('DataModelLink')
	})
})

describe('Manage object types', () => {
	it.each(['CaseObjects', 'CaseDetail'])(
		'reaches the same page from %s',
		(pageId) => {
			const card = objectTypesCard(pageId)
			expect(card).toBeDefined()
			expect(card.label).toBe('Manage object types')
			expect(card.href).toBe(TARGET)
			// route and href are mutually exclusive in the schema, and an
			// unresolvable route renders a disabled card rather than a link.
			expect(card.route).toBeUndefined()
		},
	)

	it.each(['CaseObjects', 'CaseDetail'])(
		'is admin-only and OpenRegister-gated on %s',
		(pageId) => {
			const card = objectTypesCard(pageId)
			// NOT `permission`: CnNavCardGrid declares that key for parity with
			// menuItem and evaluates it nowhere, so a card carrying it alone is
			// visible to every account. `visibleIf` is the half that runs.
			expect(card.permission).toBeUndefined()
			expect(card.visibleIf.appInstalled).toBe('openregister')

			// The predicate CnNavCardGrid actually runs, over the three runtimes
			// dossiq can hand it. `appInstalled` is a reserved key the evaluator
			// skips, so this is the admin half on its own.
			const admin = { user: { isAdmin: true } }
			const handler = { user: { isAdmin: false } }

			expect(passesContextPredicates(card.visibleIf, admin)).toBe(true)
			expect(passesContextPredicates(card.visibleIf, handler)).toBe(false)
			expect(passesContextPredicates(card.visibleIf, null)).toBe(false)
		},
	)

	it('sits beside the objects list rather than replacing it', () => {
		// `widgetsBySlot.has('body')` makes CnPageRenderer render the grid
		// INSTEAD of the typed page component. A nav card in the body slot
		// would silently replace the objects list with one link.
		const grid = page('CaseObjects').widgets.find((w) => w.widgetKey === 'nav-card-grid')

		expect(grid.slot).toBe('footer')
		expect(grid.gridX + grid.gridWidth).toBeLessThanOrEqual(12)
	})

	it('sits right after the Objects section on the case page', () => {
		const related = page('CaseDetail').config.widgets.find((w) => w.id === 'case-related-panel')
		const ids = related.content.sections.map((sec) => sec.widget.id)

		expect(ids.indexOf('case-object-types-link')).toBe(ids.indexOf('case-objects') + 1)
		// gate-55: a detail page carries config.widgets OR page widgets, never both.
		expect(page('CaseDetail').widgets).toBeUndefined()
	})

	it('resolves on the case page, because the shared catalog carries the type', () => {
		// CnDetailWidgetHost reads the shared catalog only. Without this
		// registration the section renders nothing and logs nothing.
		expect(caseObjectTypesSection().widget.type).toBe('nav-card-grid')
		expect(registerSource).toContain("registerDashboardWidget('nav-card-grid'")
		expect(registerSource).toContain('renderer: CnNavCardGrid')
	})

	it('has a runtime to resolve user.isAdmin against', () => {
		// passesContextPredicates returns false when runtime is absent, so a
		// card gated on a dot-path against a manifest with no runtime block is
		// hidden from everyone and nothing says so.
		expect(mainSource).toContain('runtime: { user: { isAdmin:')
		expect(mainSource).toContain("currentPermissions().includes('admin')")
	})
})

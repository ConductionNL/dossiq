/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contacts you can FIND: the organisations index, where it sits in the
 * navigation, and where a unified-search hit on a person or a company lands.
 *
 * `contacts-domain` gave an organisation a page and no way to reach it. Ten
 * `kvkCompany` rows sat on the instance listed by nothing, and the only door
 * was the initiator card of a case that already named the company. This
 * change opens the front door. What has to stay true is small and each half
 * of it fails silently:
 *
 *  - The organisations index must be a CHILD of Contacts. A top-level entry
 *    would spend the fifth of six slots ADR-097 leaves, and nothing in a
 *    browser would look wrong while it did.
 *  - A child behind a collapsed chevron is not found. `Contacts` carries
 *    `open: true` and the assertion below measures the BUILT menu, because
 *    the relocation lives in one file and the flag in another and reading
 *    either alone proves nothing.
 *  - A deepLink whose urlTemplate names a route the manifest does not carry
 *    turns a search result into a 404, and the search result still renders.
 *
 * @spec openspec/changes/contacts-you-can-find/specs/initiator-display/spec.md
 * @spec openspec/changes/contacts-you-can-find/specs/case-search-via-or-unified-search/spec.md
 */

// The REAL implementation, by deep path: vitest.config.js aliases the bare
// package name to a stub, and the anchored regex does not match a subpath.
// This is the same module `src/main.js` runs, so what it returns here is what
// the navigation renders.
import { buildManifest } from '@conduction/nextcloud-vue/dist/esm/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const menuLayout = readJson('src', 'menu-layout.json')
const iconsSource = read('src', 'icons.js')
const brpRegister = readJson('lib', 'Settings', 'register.d', '25-brp-kvk.json')

const page = (id) => manifest.pages.find((p) => p.id === id)
const organisations = page('Organisations')

/** The menu as CnAppNav receives it, layout applied. */
const built = buildManifest(manifest, [], menuLayout)
const builtEntry = (id) => built.menu.find((m) => m.id === id)
const mainEntries = built.menu.filter((m) => (m.section ?? 'main') === 'main')

describe('the organisations index', () => {
	it('is an index over kvkCompany, not a custom page', () => {
		expect(organisations).toBeDefined()
		expect(organisations.type).toBe('index')
		expect(organisations.route).toBe('/organisations')
		expect(organisations.config.register).toBe('dossiq')
		expect(organisations.config.schema).toBe('kvkCompany')
		// A custom page would spend the ADR-100 budget for a list a standard
		// index already renders.
		expect(organisations.component).toBeUndefined()
	})

	it('reads only properties kvkCompany actually has', () => {
		// A column key naming a property the schema does not carry renders an
		// empty cell, which is indistinguishable from an empty value.
		const props = brpRegister.components.schemas.kvkCompany.properties
		for (const column of organisations.config.columns) {
			const root = column.key.split('.')[0]
			expect(Object.keys(props)).toContain(root)
		}
	})

	it('shows the two things you would look an organisation up by', () => {
		const keys = organisations.config.columns.map((c) => c.key)
		expect(keys).toContain('tradeName')
		expect(keys).toContain('kvkNumber')
	})

	it('does not sort on a dot-path the server cannot order by', () => {
		for (const column of organisations.config.columns) {
			if (column.key.includes('.')) {
				expect(column.sortable).toBe(false)
			}
		}
	})

	it('opens a row on the organisation page that already exists', () => {
		const view = organisations.config.actions.find((a) => a.id === 'view')
		expect(view.route).toBe('OrganisationDetail')
		expect(page('OrganisationDetail').route).toBe('/organisations/:id')
		expect(organisations.config.showViewAction).toBe(false)
	})
})

describe('where the organisations index sits', () => {
	it('is a child of Contacts in the menu the app actually builds', () => {
		const contacts = builtEntry('Contacts')
		expect(contacts).toBeDefined()
		const childIds = (contacts.children || []).map((c) => c.id)
		expect(childIds).toContain('OrganisationsMenu')

		const child = contacts.children.find((c) => c.id === 'OrganisationsMenu')
		expect(child.route).toBe('Organisations')
		expect(manifest.pages.some((p) => p.id === child.route)).toBe(true)
	})

	it('is visible without expanding anything', () => {
		// CnAppNav's isItemOpen falls back to `item.open`, and hasActiveChild
		// is false while the PARENT is the active route — so on /contacts the
		// child would be display:none without this flag.
		expect(builtEntry('Contacts').open).toBe(true)
	})

	it('spends no top-level slot', () => {
		expect(mainEntries.map((m) => m.id)).toEqual([
			'Dashboard',
			'WorkGroup',
			'Contacts',
			'CaseObjectsMenu',
		])
		// ADR-097 allows six. Contacts spent the fifth as the gate counts it;
		// this change spends none, so the arithmetic is unchanged.
		expect(mainEntries.length).toBe(4)
		expect(built.menu.some((m) => m.id === 'OrganisationsMenu')).toBe(false)
	})

	it('names an icon src/icons.js registers (gate-60)', () => {
		const entry = manifest.menu.find((m) => m.id === 'OrganisationsMenu')
		expect(entry.icon).toBe('OfficeBuildingOutline')
		expect(iconsSource).toContain(`\n\t${entry.icon},`)
	})

	it('does not collide with the tenants entry that carries the same label', () => {
		// `TenantsMenu` is also labelled Organisations and means the tenant,
		// not a company. It is removed, so the two never render together; if
		// it ever comes back it must be renamed first.
		expect(menuLayout.removals).toContain('TenantsMenu')
		const labelled = built.menu.filter((m) => m.label === 'Organisations')
		expect(labelled).toHaveLength(0)
	})
})

describe('a search hit on a person or a company', () => {
	const bySlug = Object.fromEntries(
		manifest.deepLinks.map((entry) => [entry.schemaSlug, entry]),
	)

	it('lands on the contact page, not on OpenRegister', () => {
		// Measured on the running instance 2026-09-08: OpenRegister's schema
		// `searchable` column DEFAULTS to true, so both schemas were already
		// in unified search with no flag in the register JSON. What was wrong
		// was the destination — the formatter falls back to
		// `/apps/openregister/api/objects/<register>/<schema>/<uuid>`, a JSON
		// endpoint. The deep link is the whole fix; no register change and no
		// version bump are needed, and adding a redundant flag would force a
		// schema re-import that changes nothing.
		expect(bySlug.brpPerson).toBeDefined()
		expect(bySlug.brpPerson.registerSlug).toBe('dossiq')
		expect(bySlug.brpPerson.urlTemplate).toBe('/apps/dossiq/contacts/{uuid}')

		expect(bySlug.kvkCompany).toBeDefined()
		expect(bySlug.kvkCompany.registerSlug).toBe('dossiq')
		expect(bySlug.kvkCompany.urlTemplate).toBe(
			'/apps/dossiq/organisations/{uuid}',
		)
	})

	it('names a route the manifest carries, so the link is not a 404', () => {
		const pageRoutes = manifest.pages.map((p) => p.route)
		for (const [slug, route] of [
			['brpPerson', '/contacts/:id'],
			['kvkCompany', '/organisations/:id'],
		]) {
			expect(pageRoutes, `no page route "${route}" for "${slug}"`).toContain(
				route,
			)
			// The template's path segment and the route's must agree: they are
			// written in two places and only one of them 404s.
			const segment = bySlug[slug].urlTemplate
				.replace('/apps/dossiq', '')
				.replace('{uuid}', ':id')
			expect(segment).toBe(route)
		}
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contacts as a place you can go: the entry, the index, and the two pages.
 *
 * The entry is the point of the guard. ADR-097 leaves dossiq two spare
 * top-level slots and this change spends one, so what has to stay true is
 * that it spends exactly one: a second entry, or a `Contacts` id that
 * `menu-layout.json` later relocates into the gear, breaks the decision
 * without breaking anything a browser would notice. Everything else here is
 * the same class of silent failure the manifest specs guard: an icon outside
 * `src/icons.js` renders nothing rather than a fallback glyph, a widget whose
 * `@objectId` filter names a property no schema carries returns an empty list
 * that reads as "this person has no cases", and a header action prefilling a
 * field the form does not include drops the prefill without a word.
 *
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md
 * @spec openspec/specs/initiator-display/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const menuLayout = readJson('src', 'menu-layout.json')
const iconsSource = read('src', 'icons.js')
const registrySource = read('src', 'registry.js')
const kccRegister = readJson('lib', 'Settings', 'register.d', '40-kcc-werkplek.json')
const mockRegister = readJson('lib', 'Settings', 'dossiq_mock_register.json')
const brpRegister = readJson('lib', 'Settings', 'register.d', '25-brp-kvk.json')

const page = (id) => manifest.pages.find((p) => p.id === id)
const menuEntry = manifest.menu.find((m) => m.id === 'Contacts')
const contacts = page('Contacts')
const contactDetail = page('ContactDetail')
const organisationDetail = page('OrganisationDetail')

/** A page's widget by id. */
const widget = (p, id) => p.config.widgets.find((w) => w.id === id)
/** A page's header action by id. */
const action = (p, id) => p.config.headerActions.find((a) => a.id === id)

describe('the Contacts menu entry', () => {
	it('is one top-level leaf, after My work', () => {
		expect(menuEntry).toBeDefined()
		expect(menuEntry.route).toBe('Contacts')
		expect(menuEntry.order).toBe(25)
		expect(menuEntry.section).toBeUndefined()
		expect(menuEntry.children).toBeUndefined()

		const workGroup = manifest.menu.find((m) => m.id === 'WorkGroup')
		expect(menuEntry.order).toBeGreaterThan(workGroup.order)
	})

	it('names an icon src/icons.js registers (gate-60)', () => {
		expect(menuEntry.icon).toBe('AccountGroupOutline')
		expect(iconsSource).toContain(`\n\t${menuEntry.icon},`)
	})

	it('is left where the manifest put it', () => {
		expect(menuLayout.relocations.Contacts).toBeUndefined()
		expect(menuLayout.removals).not.toContain('Contacts')
		expect(menuLayout.settingsSection).not.toContain('Contacts')
		expect(menuLayout.integrationsSection).not.toContain('Contacts')
	})

	it('leaves the effective top level at Dashboard, My work and Contacts', () => {
		const relocated = new Set(Object.keys(menuLayout.relocations))
		const removed = new Set(menuLayout.removals)
		const lifted = new Set([
			...menuLayout.settingsSection,
			...menuLayout.integrationsSection,
		])

		const topLevel = manifest.menu
			.filter((m) => (m.section ?? 'main') === 'main')
			.filter(
				(m) =>
					!relocated.has(m.id) && !removed.has(m.id) && !lifted.has(m.id),
			)
			.map((m) => m.id)

		// FIVE, not the design's three. The design and the delta spec both say
		// the top level is Dashboard, My work and Contacts; measured against
		// this tree it is five, and the two extras are real. `CaseObjectsMenu`
		// (Objects) arrived with custom-objects-on-the-case and was never
		// relocated into the My work group, and `SettingsGroup` is the gear
		// group's own header, which carries no route and is not a domain.
		// Asserting the design's three would have been a test that fails on a
		// correct tree, so the measurement is what is written down.
		expect(topLevel).toEqual([
			'Dashboard',
			'WorkGroup',
			'Contacts',
			'CaseObjectsMenu',
			'SettingsGroup',
		])
		// ADR-097 allows six. This change spends the fifth, so the NEXT domain
		// is the last one that fits — which is exactly why the number is
		// asserted here rather than counted off a diff.
		expect(topLevel.length).toBeLessThanOrEqual(6)
	})

	it('is the only entry routing at the Contacts page', () => {
		const routing = manifest.menu.filter((m) => m.route === 'Contacts')
		expect(routing).toHaveLength(1)
	})

	it('adds no other menu entry', () => {
		for (const id of ['ContactDetail', 'OrganisationDetail']) {
			expect(manifest.menu.some((m) => m.route === id)).toBe(false)
		}
	})
})

describe('the Contacts index', () => {
	it('lists people from the register set dossiq already carries', () => {
		expect(contacts.type).toBe('index')
		expect(contacts.route).toBe('/contacts')
		expect(contacts.config.register).toBe('dossiq')
		expect(contacts.config.schema).toBe('brpPerson')
		expect(contacts.component).toBeUndefined()
	})

	it('shows the columns you would search on', () => {
		const keys = contacts.config.columns.map((c) => c.key)
		expect(keys).toContain('displayName')
		expect(keys).toContain('citizenServiceNumber')
	})

	it('reads only properties brpPerson actually has', () => {
		const props = brpRegister.components.schemas.brpPerson.properties
		for (const column of contacts.config.columns) {
			const root = column.key.split('.')[0]
			expect(Object.keys(props)).toContain(root)
		}
	})

	it('opens a row on the contact page', () => {
		const view = contacts.config.actions.find((a) => a.id === 'view')
		expect(view.route).toBe('ContactDetail')
		expect(contacts.config.showViewAction).toBe(false)
	})

	it('ships NO folder sidebar, and says why in the manifest', () => {
		// The design asked for one. CnFolderTree renders every entry of
		// `folders[]` — there is no `hidden` — so the Organisations folder
		// would appear and filter this page's brpPerson rows to nothing; and
		// `filterField: "@self.schema"` is not a filter OpenRegister answers,
		// so even the People folder would have emptied the list on its first
		// click. Shipping neither is the honest state, and the note carries
		// the block to restore when the seam lands.
		expect(contacts.config.folderSidebar).toBeUndefined()
		expect(contacts.config._folderSidebarNote).toContain('kvkCompany')
		expect(contacts.config._folderSidebarNote).toContain('@self.schema')
	})
})

describe.each([
	['ContactDetail', () => contactDetail, 'brpPerson', '/contacts/:id'],
	[
		'OrganisationDetail',
		() => organisationDetail,
		'kvkCompany',
		'/organisations/:id',
	],
])('%s', (id, get, schema, route) => {
	it('is a detail page over its own schema, not a custom page', () => {
		const p = get()
		expect(p.type).toBe('detail')
		expect(p.route).toBe(route)
		expect(p.config.schema).toBe(schema)
		expect(p.component).toBeUndefined()
	})

	it('carries the card, the cases and the moments', () => {
		const p = get()
		expect(p.config.widgets.map((w) => w.id)).toEqual([
			'contact-card',
			'contact-cases',
			'contact-moments',
		])
		expect(widget(p, 'contact-card').content.editable).toBe(false)
	})

	it('filters both lists on this object, not on a field that does not exist', () => {
		const p = get()
		expect(widget(p, 'contact-cases').content.filter).toEqual({
			requester: '@objectId',
		})
		expect(widget(p, 'contact-moments').content.filter).toEqual({
			contact: '@objectId',
		})
	})

	it('opens a case row on the case page and offers a way to all of them', () => {
		const cases = widget(get(), 'contact-cases').content
		expect(cases.rowRoute).toBe('CaseDetail')
		expect(cases.viewAllRoute).toBe('Cases')
		expect(cases.viewAllQuery).toEqual({ requester: '@objectId' })
		expect(cases.emptyText).toBeTruthy()
	})

	it('shows the newest contact moment first', () => {
		expect(widget(get(), 'contact-moments').content.sort).toEqual({
			field: 'startTime',
			dir: 'desc',
		})
	})

	it('fills the grid, leaving no void (ADR-062)', () => {
		const p = get()
		const ids = p.config.layout.map((l) => l.widgetId)
		expect(new Set(ids).size).toBe(p.config.widgets.length)
		for (const item of p.config.layout) {
			expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
			expect(item.gridWidth).toBe(12)
		}
	})

	it('carries the audit sidebar, which is the timeline', () => {
		expect(get().config.sidebar.tabs[0].widgets[0].type).toBe('audit')
	})

	it('files a case with the contact already chosen', () => {
		const p = get()
		const a = action(p, 'new-case-for-contact')
		expect(a.type).toBe('open-form')
		expect(a.schema).toBe('case')
		expect(a.props).toEqual({ requester: '@objectId' })
		// A prefill of a field the form does not ask for is dropped silently.
		expect(a.includeFields).toContain('requester')
		expect(a.fieldOverrides.requester.widget).toBe('InitiatorPicker')
	})

	it('logs a contact moment against this contact', () => {
		const p = get()
		const a = action(p, 'log-contact')
		expect(a.type).toBe('open-form')
		expect(a.schema).toBe('contactmoment')
		expect(a.props).toEqual({ contact: '@objectId' })
		expect(a.includeFields).toEqual([
			'notificationChannel',
			'direction',
			'startTime',
			'nature',
			'summary',
			'relatedCases',
		])
	})

	it('names icons src/icons.js registers (gate-60)', () => {
		const p = get()
		for (const icon of [
			...p.config.headerActions.map((a) => a.icon),
			...p.config.widgets.map((w) => w.icon),
		]) {
			expect(iconsSource).toContain(`\n\t${icon},`)
		}
	})
})

describe('the contact on a contact moment', () => {
	it('is a uuid with the requester semantic type, in both register files', () => {
		for (const register of [kccRegister, mockRegister]) {
			const schema = register.components.schemas.contactmoment
			const contact = schema.properties.contact
			expect(contact.type).toBe('string')
			expect(contact.format).toBe('uuid')
			expect(contact.referenceSemanticType).toBe(
				'https://openregister.app/ns#Requester',
			)
			expect(contact.facetable).toBe(true)
			// Optional: a moment logged before this change has no contact, and
			// making it required would make every one of them invalid.
			expect(schema.required || []).not.toContain('contact')
		}
	})

	it('bumps the version past what development holds, in both files', () => {
		// OpenRegister FAST-SKIPS a schema whose version did not move, so the
		// property would be inert on every existing install. Development is at
		// 1.2.0 here, not the design's 1.1.0, so the bump is to 1.3.0.
		for (const register of [kccRegister, mockRegister]) {
			expect(register.components.schemas.contactmoment.version).toBe('1.3.0')
		}
	})

	it('leaves geidentificeerdeBurgerId alone for the KCC bridge', () => {
		expect(
			kccRegister.components.schemas.contactmoment.properties
				.geidentificeerdeBurgerId,
		).toBeDefined()
	})

	it('is served by the same picker as case.requester', () => {
		// Both fields resolve ns#Requester, so one picker covers both — but
		// only once its appliesTo says so. Without this the Log contact form
		// renders the field as a bare uuid box.
		expect(registrySource).toContain(
			"appliesTo: ['case.requester', 'contactmoment.contact']",
		)
	})
})

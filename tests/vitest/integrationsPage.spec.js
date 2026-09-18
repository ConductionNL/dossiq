/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page, its declaration, and the promise the declaration makes.
 *
 * Since adopt-connection-registry the rows are integriq's. Dossiq ships two
 * things: the page in its manifest and `lib/Settings/connections.json`, which
 * integriq syncs into its `app_connection` schema.
 *
 * Everything asserted here fails SILENTLY in the browser. A menu entry without
 * its `query` lists every app's rows as though they were dossiq's; an icon that
 * is not in `src/icons.js` renders nothing; a menu id missing from
 * `settingsSection` lands in the MAIN nav; a header action naming a handler
 * nobody exports does nothing when clicked; and a `settingsUrl` pointing at an
 * anchor no section carries scrolls nowhere and logs nothing. The page exists
 * to stop exactly this class of quiet untruth, so the guard has to be here
 * rather than in a reviewer's eye.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const menuLayout = readJson('src', 'menu-layout.json')
const register = readJson('lib', 'Settings', 'dossiq_register.json')
const declaration = readJson('lib', 'Settings', 'connections.json')
const iconsSource = read('src', 'icons.js')
const adminRoot = read('src', 'views', 'settings', 'AdminRoot.vue')
const registrySource = read('src', 'registry.js')
const integriqSource = read('src', 'utils', 'integriqConnections.js')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

const page = manifest.pages.find((p) => p.id === 'Integrations')
const menuEntry = manifest.menu.find((m) => m.id === 'IntegrationsMenu')
const oldSchema = register.components.schemas.dossiqIntegration
const connections = declaration.connections
const byKey = Object.fromEntries(connections.map((c) => [c.key, c]))

describe('the Integrations page', () => {
	it("is declared, admin only, and reads integriq's app_connection schema", () => {
		expect(page).toBeDefined()
		expect(page.permission).toBe('admin')
		expect(page.route).toBe('/settings/integrations')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
	})

	// Without it, a deep link on an instance without integriq renders an empty
	// table, and "not installed" looks exactly like "no connections".
	it('names Integriq as the app it needs', () => {
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
	})

	it('is not a custom page, the ADR-100 ratchet is the point of task 2.2', () => {
		expect(page.type).toBe('index')
		expect(page.component).toBeUndefined()
	})

	it('carries no configuration fields of its own (ADR-079, REQ-ADMIN-018)', () => {
		expect(page.config.fields).toBeUndefined()
		expect(page.config.sections).toBeUndefined()
	})

	it('shows the four things a row has to show', () => {
		const keys = page.config.columns.map((c) => c.key)
		expect(keys).toContain('title')
		expect(keys).toContain('status')
		expect(keys).toContain('statusMessage')
		expect(keys).toContain('checkedAt')
	})

	it('renders the status through the contract formatter, never the raw enum', () => {
		const status = page.config.columns.find((c) => c.key === 'status')
		expect(status.formatter).toBe('connectionStatus')
	})

	it('offers Open settings as a per-row link, which is the only shape that can hide itself', () => {
		const settings = page.config.columns.find((c) => c.key === 'settingsUrl')
		expect(settings.widget).toBe('link')
		expect(settings.widgetProps.href).toBe('{settingsUrl}')
		expect(settings.formatter).toBe('connectionSettingsLabel')
		expect(page.config.actions ?? []).toEqual([])
	})

	it('sorts on the declared order so ZGW is first', () => {
		expect(page.config.defaultSort).toEqual({
			field: 'order',
			direction: 'asc',
		})
	})

	it('groups the sidebar on status', () => {
		expect(page.config.folderSidebar.source).toBe('field')
		expect(page.config.folderSidebar.field).toBe('status')
	})

	// A row nothing declared has nothing to check (connection-registry D9).
	it('offers no generic Add button', () => {
		expect(page.config.showAdd).toBe(false)
	})

	it('sends Add integration to integriq through a handler that exists', () => {
		const add = (page.config.headerActions ?? []).find(
			(a) => a.id === 'add-integration',
		)
		expect(add).toBeDefined()
		expect(add.label).toBe('Add integration')
		expect(add.handler).toBe('openIntegriqConnections')
		// Defined AND registered as a handler, or the renderer cannot resolve it.
		expect(integriqSource).toContain('export function openIntegriqConnections(')
		expect(integriqSource).toContain(
			"'/apps/integriq/connections?app=dossiq&link=1'",
		)
		expect(registrySource).toMatch(
			/\n\topenIntegriqConnections: \{\n\t\tkind: 'handler',\n\t\thandler: openIntegriqConnections,\n/,
		)
		expect(iconsSource).toContain(`\n\t${add.icon},`)
	})

	it('translates the new action label', () => {
		expect(en['Add integration']).toBe('Add integration')
		expect(nl['Add integration']).toBeTruthy()
		expect(nl['Add integration']).not.toBe('Add integration')
	})
})

describe('the Integrations menu entry', () => {
	it('sits in the gear foldout and nowhere else', () => {
		expect(menuEntry).toBeDefined()
		expect(menuEntry.section).toBe('settings')
		expect(menuEntry.permission).toBe('admin')
		expect(menuLayout.settingsSection).toContain('IntegrationsMenu')
		expect(menuLayout.relocations.IntegrationsMenu).toBeUndefined()
		expect(menuLayout.removals).not.toContain('IntegrationsMenu')
	})

	// THE PRESET. integriq's schema holds every app's rows. The query is what
	// makes this dossiq's page, and a bare key is the spelling the objects
	// endpoint reads as a filter.
	it("presets the list to dossiq's own rows", () => {
		expect(menuEntry.query).toEqual({ app: 'dossiq' })
	})

	it('only renders when integriq is installed', () => {
		expect(menuEntry.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('names an icon src/icons.js registers (gate-60)', () => {
		expect(menuEntry.icon).toBe('PowerPlugOutline')
		expect(iconsSource).toContain(`\n\t${menuEntry.icon},`)
	})

	it('points at a page that exists', () => {
		expect(manifest.pages.some((p) => p.id === menuEntry.route)).toBe(true)
	})
})

describe('the connection declaration', () => {
	it('names this app', () => {
		expect(declaration.app).toBe('dossiq')
	})

	it('keeps the twelve keys the page used, in placement order', () => {
		expect(connections.map((c) => c.key)).toEqual([
			'zgw',
			'stuf',
			'kcc',
			'dmn',
			'mailbox',
			'store',
			'financial',
			'brp',
			'kvk',
			'pdok',
			'berichtenbox',
			'templates',
		])
		const orders = connections.map((c) => c.order)
		expect([...orders].sort((a, b) => a - b)).toEqual(orders)
	})

	it('gives every connection a title', () => {
		for (const connection of connections) {
			expect(String(connection.title ?? '').trim()).not.toBe('')
		}
	})

	// BRP and KvK were both once marked "Specified, not built yet", which was
	// false for both. What separates them is who calls them: nothing injects
	// the KvK adapter.
	it('declares only KvK unavailable, and says why', () => {
		const unavailable = connections.filter((c) => c.available === false)
		expect(unavailable.map((c) => c.key)).toEqual(['kvk'])
		expect(byKey.kvk.unavailableMessage).toMatch(/built and bound/i)
		expect(byKey.kvk.unavailableMessage).not.toMatch(/not built/i)
		expect(byKey.kvk.settingsUrl).toBeUndefined()
	})

	// The rows this page exists for. A mock adapter WORKS and delivers
	// nothing, so integriq shows Simulated while the adapter key is empty, and
	// the message has to say the word.
	// BRP is built and called, and reaches nothing until its tier key moves.
	// No admin section writes that key, so the row's message has to name it.
	it('names the key that wakes BRP, and offers no settings link', () => {
		expect(byKey.brp.unconfiguredMessage).toMatch(/integration\.brp\.mode/)
		expect(byKey.brp.unconfiguredMessage).not.toMatch(/not built/i)
		expect(byKey.brp.settingsUrl).toBeUndefined()
	})

	it('names the adapter key of the two mock-backed seams', () => {
		expect(byKey.berichtenbox.adapter.configKey).toBe('berichtenbox_adapter')
		expect(byKey.templates.adapter.configKey).toBe(
			'beschikking_template_adapter',
		)
		for (const key of ['berichtenbox', 'templates']) {
			expect(byKey[key].adapter.simulatedMessage).toMatch(/mock/i)
			expect(byKey[key].settingsUrl).toBeUndefined()
		}
	})

	// Contract D4 rule 3 (hydra#673) matches the key's value against
	// `simulatedValues`, default only the empty string. Naming the mock class
	// in the key binds the mock too, so the list has to carry that class or
	// the row reads Configured while the mock answers.
	it('reads Simulated when the key is empty or names the mock class', () => {
		expect(byKey.berichtenbox.adapter.simulatedValues).toEqual([
			'',
			'OCA\\Dossiq\\Service\\BerichtenboxAdapter\\MockAdapter',
		])
		expect(byKey.templates.adapter.simulatedValues).toEqual([
			'',
			'OCA\\Dossiq\\Service\\Beschikking\\MockTemplateEngineAdapter',
		])
		expect(connections.some((c) => 'reportedOnly' in c)).toBe(false)
	})

	it('lets the probed connections arrive as reports', () => {
		for (const key of ['stuf', 'mailbox', 'store']) {
			expect(byKey[key].requiredConfig).toBeUndefined()
			expect(byKey[key].adapter).toBeUndefined()
		}
	})

	it('links only to an anchor AdminRoot.vue actually carries', () => {
		const linked = connections.filter((c) => c.settingsUrl !== undefined)
		expect(linked).toHaveLength(7)
		for (const connection of linked) {
			const anchor = connection.settingsUrl.split('#')[1]
			expect(
				connection.settingsUrl.startsWith('/settings/admin/dossiq#'),
			).toBe(true)
			expect(adminRoot).toContain(`id="${anchor}"`)
		}
	})

	it('leaves PDOK unlinked, because it has no admin section at all', () => {
		expect(byKey.pdok.settingsUrl).toBeUndefined()
		expect(adminRoot).not.toContain('id="section-pdok"')
	})
})

describe('the old dossiqIntegration schema', () => {
	it('stays in the register for one release, with a note naming the change', () => {
		expect(oldSchema).toBeDefined()
		expect(register.components.registers.dossiq.schemas).toContain(
			'dossiqIntegration',
		)
		expect(oldSchema._meta.change).toBe(
			'openspec/changes/adopt-connection-registry',
		)
	})

	it('is seeded by no register.d fragment', () => {
		const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
		for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.json'))) {
			const fragment = JSON.parse(
				fs.readFileSync(path.join(dir, file), 'utf8'),
			)
			const objects = fragment?.components?.objects ?? []
			expect(
				objects.filter((o) => o?.['@self']?.schema === 'dossiqIntegration'),
				`${file} still seeds dossiqIntegration rows`,
			).toEqual([])
		}
	})

	// Kept while the schema is: a revert brings the old page back onto it.
	it('still restricts every action to admins', () => {
		expect(oldSchema.authorization).toEqual({
			read: ['admin'],
			create: ['admin'],
			update: ['admin'],
			delete: ['admin'],
		})
	})
})

describe('the admin page anchors', () => {
	it('are unique, because a duplicate id breaks the anchor it duplicates', () => {
		const ids = [...adminRoot.matchAll(/id="(section-[a-z]+)"/g)].map(
			(m) => m[1],
		)
		expect(new Set(ids).size).toBe(ids.length)
	})
})

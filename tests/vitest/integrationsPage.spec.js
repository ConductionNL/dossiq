/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page, its seed, and the promise the seed makes.
 *
 * Everything asserted here fails SILENTLY in the browser. An icon that is not
 * in `src/icons.js` renders nothing rather than a fallback glyph; a menu id
 * missing from `settingsSection` in menu-layout.json lands in the MAIN nav
 * instead of the gear, where every user sees it; a seed row that claims
 * `configured` makes the page lie on a fresh instance and nothing complains;
 * and a `settingsUrl` pointing at an anchor no section carries scrolls
 * nowhere and logs nothing. The page exists to stop exactly this class of
 * quiet untruth, so the guard has to be here rather than in a reviewer's eye.
 *
 * @spec openspec/specs/admin-settings/spec.md
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
const seed = readJson('lib', 'Settings', 'register.d', '96-integrations.json')
const iconsSource = read('src', 'icons.js')
const adminRoot = read('src', 'views', 'settings', 'AdminRoot.vue')

const page = manifest.pages.find((p) => p.id === 'Integrations')
const menuEntry = manifest.menu.find((m) => m.id === 'IntegrationsMenu')
const schema = register.components.schemas.dossiqIntegration
const rows = seed.components.objects

describe('the Integrations page', () => {
	it('is declared, admin only, and reads the integration schema', () => {
		expect(page).toBeDefined()
		expect(page.permission).toBe('admin')
		expect(page.route).toBe('/settings/integrations')
		expect(page.config.register).toBe('dossiq')
		expect(page.config.schema).toBe('dossiqIntegration')
	})

	it('is not a custom page — the ADR-100 ratchet is the point of task 2.2', () => {
		expect(page.type).toBe('index')
		expect(page.component).toBeUndefined()
	})

	it('carries no configuration fields of its own (ADR-079, REQ-ADMIN-018)', () => {
		expect(page.config.fields).toBeUndefined()
		expect(page.config.sections).toBeUndefined()
	})

	it('shows the four things a card has to show', () => {
		const keys = page.config.columns.map((c) => c.key)
		expect(keys).toContain('title')
		expect(keys).toContain('status')
		expect(keys).toContain('statusMessage')
		expect(keys).toContain('checkedAt')
	})

	it('renders the status through the formatter, never the raw enum', () => {
		const status = page.config.columns.find((c) => c.key === 'status')
		expect(status.formatter).toBe('integrationStatus')
	})

	it('offers Open settings as a per-row link, which is the only shape that can hide itself', () => {
		const settings = page.config.columns.find((c) => c.key === 'settingsUrl')
		expect(settings.widget).toBe('link')
		expect(settings.widgetProps.href).toBe('{settingsUrl}')
		expect(settings.formatter).toBe('integrationSettingsLabel')
		// A row action could not do this: CnRowActions' `visible` predicate is
		// a function, and JSON cannot carry one.
		expect(page.config.actions ?? []).toEqual([])
	})

	it('sorts on the seeded order so ZGW is first and PDOK is last', () => {
		expect(page.config.defaultSort).toEqual({
			field: 'order',
			direction: 'asc',
		})
	})

	it('groups the sidebar on a facetable field with a source CnFolderSidebar accepts', () => {
		expect(['custom', 'field', 'files']).toContain(
			page.config.folderSidebar.source,
		)
		expect(page.config.folderSidebar.field).toBe('status')
		expect(schema.properties.status.facetable).toBe(true)
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

	it('names an icon src/icons.js registers (gate-60)', () => {
		expect(menuEntry.icon).toBe('PowerPlugOutline')
		expect(iconsSource).toContain(`\n\t${menuEntry.icon},`)
	})

	it('points at a page that exists', () => {
		expect(manifest.pages.some((p) => p.id === menuEntry.route)).toBe(true)
	})
})

describe('the dossiqIntegration schema', () => {
	it('declares the five states and nothing else', () => {
		expect(schema.properties.status.enum).toEqual([
			'configured',
			'unconfigured',
			'unavailable',
			'simulated',
			'error',
		])
	})

	// Simulated is the state that lets an adapter seam tell the truth. Without
	// it the two mock-backed seams have to be filed under `unconfigured`, which
	// understates a channel that succeeds and delivers nothing, or under
	// `configured`, which is the lie this page was built to remove.
	it('gives Simulated a label, because an unlabelled enum renders raw', () => {
		expect(schema.properties.status['x-enum-labels'].simulated).toBe('Simulated')
	})

	it('is listed on the register, so the import creates it', () => {
		expect(register.components.registers.dossiq.schemas).toContain(
			'dossiqIntegration',
		)
	})

	it('leaves settingsUrl free of format: uri, which rejects a relative path', () => {
		expect(schema.properties.settingsUrl.format).toBeUndefined()
	})

	it('hides nothing and marks nothing read-only — both drop a property silently', () => {
		for (const property of Object.values(schema.properties)) {
			expect(property.visible).not.toBe(false)
			expect(property.readOnly).not.toBe(true)
		}
	})
})

describe('the seeded connections', () => {
	it('are the ten of row A34 plus the two adapter seams, in placement order', () => {
		expect(rows).toHaveLength(12)
		expect(rows.map((r) => r.key)).toEqual([
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
		const orders = rows.map((r) => r.order)
		expect([...orders].sort((a, b) => a - b)).toEqual(orders)
	})

	it('claim nothing: no seeded row reads Configured', () => {
		expect(rows.some((r) => r.status === 'configured')).toBe(false)
	})

	// BRP and KvK were both seeded Not available with "Specified, not built
	// yet", and that sentence was false for both of them. Each ships a Log
	// adapter, a real HTTP adapter, a DI registrar bound from
	// ExternalRegisterRegistrar, and unit tests. What separates them is who
	// calls them: ConflictOfInterestService injects the BRP adapter for the
	// belangenconflict check, and nothing anywhere injects the KvK one.
	it('say Not available only where nothing calls the adapter', () => {
		const unavailable = rows.filter((r) => r.status === 'unavailable')
		expect(unavailable.map((r) => r.key)).toEqual(['kvk'])
		for (const row of unavailable) {
			expect(row.statusMessage).not.toMatch(/not built/i)
			expect(row.statusMessage).toMatch(/built and bound/i)
			expect(row.settingsUrl).toBe('')
		}
	})

	// The row that was wrong. BRP is built, bound and called, and it reaches
	// nothing because its tier defaults to `log`. That is Not configured, and
	// the message has to name the key an integrator sets, because no admin
	// section writes it.
	it('say Not configured for BRP, and name the key that wakes it', () => {
		const brp = rows.find((r) => r.key === 'brp')
		expect(brp.status).toBe('unconfigured')
		expect(brp.statusMessage).not.toMatch(/not built/i)
		expect(brp.statusMessage).toMatch(/integration\.brp\.mode/)
		expect(brp.settingsUrl).toBe('')
	})

	// The row this change exists for. Berichtenbox and the template engine both
	// resolve to a mock adapter until an integrator names a real one, and the
	// mock WORKS: it returns a message id, it returns a rendered document, and
	// nothing leaves the instance. `unconfigured` would understate that and
	// `configured` would be the page's own lie, so both read Simulated and both
	// say the word mock in a sentence a reader sees on the row itself.
	it('say Simulated where a mock adapter is what answers', () => {
		const simulated = rows.filter((r) => r.status === 'simulated')
		expect(simulated.map((r) => r.key)).toEqual(['berichtenbox', 'templates'])
		for (const row of simulated) {
			expect(row.statusMessage).toMatch(/mock/i)
			expect(row.settingsUrl).toBe('')
		}
	})

	it('say Not checked yet everywhere else', () => {
		const rest = rows.filter(
			(r) =>
				r.status !== 'unavailable'
				&& r.status !== 'simulated'
				&& r.key !== 'brp',
		)
		expect(rest).toHaveLength(8)
		for (const row of rest) {
			expect(row.status).toBe('unconfigured')
			expect(row.statusMessage).toBe('Not checked yet')
		}
	})

	it('link only to an anchor AdminRoot.vue actually carries', () => {
		for (const row of rows) {
			if (row.settingsUrl === '') {
				continue
			}
			const anchor = row.settingsUrl.split('#')[1]
			expect(row.settingsUrl.startsWith('/settings/admin/dossiq#')).toBe(true)
			expect(adminRoot).toContain(`id="${anchor}"`)
		}
	})

	it('leave PDOK unlinked, because it has no admin section at all', () => {
		const pdok = rows.find((r) => r.key === 'pdok')
		expect(pdok.settingsUrl).toBe('')
		expect(adminRoot).not.toContain('id="section-pdok"')
	})

	it('write every row against the schema the page reads', () => {
		for (const row of rows) {
			expect(row['@self'].register).toBe('dossiq')
			expect(row['@self'].schema).toBe('dossiqIntegration')
			expect(schema.properties.key.enum).toContain(row.key)
		}
	})
})

describe('the admin page anchors', () => {
	it('are unique, because a duplicate id breaks the anchor it duplicates', () => {
		const ids = [...adminRoot.matchAll(/id="(section-[a-z]+)"/g)].map(
			(m) => m[1],
		)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('cover every section a seeded row links to', () => {
		const linked = rows
			.filter((r) => r.settingsUrl !== '')
			.map((r) => r.settingsUrl.split('#')[1])
		expect(linked).toHaveLength(7)
		for (const anchor of linked) {
			expect(adminRoot).toContain(`id="${anchor}"`)
		}
	})
})

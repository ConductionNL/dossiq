/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The structure profiles: one manifest, a simple menu and the full one.
 *
 * Every assertion here builds the menu with the library's REAL
 * `buildManifest`, reached by its subpath because the bare package name is
 * aliased to a stub. The profile leans on three of its behaviours (first
 * definition of a key wins, a missing `relocations` leaves the menu alone, a
 * removal drops a leaf), and a fake would only prove that the fake agrees
 * with this file.
 *
 * What has to stay true:
 *   - the full profile is byte for byte what it was before profiles existed;
 *   - the simple menu is the eight fixed entries of the design, in order,
 *     under three captions, the first group having none, the third (My case
 *     types) holding what each user chose (case-types-in-my-menu);
 *   - nothing is lost: every entry the full menu offers is in the simple menu
 *     or its settings, or a page the simple menu opens links to it.
 *
 * The gates read `src/menu-layout.json` only (gate-53), so the no-loss rule
 * for the simple file is held here and nowhere else.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { saveMenuStructure } from '../../src/services/menuStructureSetting.js'
import {
	applyPageOverlay,
	buildProfiledManifest,
	overlayItemName,
	resolveStructureProfile,
	STRUCTURE_FULL,
	STRUCTURE_SETTING,
	STRUCTURE_SIMPLE,
} from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const fullFile = readJson('src', 'menu-layout.json')
const simpleFile = readJson('src', 'menu-layout.simple.json')
const iconsSource = read('src', 'icons.js')
const mainSource = read('src', 'main.js')

/** A fresh manifest each time: buildManifest merges into what it is given. */
const manifest = () => readJson('src', 'manifest.json')
function build(file) {
	return buildProfiledManifest(buildManifest, manifest(), fragments, file)
}

/**
 * Every entry of a built menu, children included.
 *
 * @param {Array<object>} menu The built menu.
 * @return {Array<object>} The flat list.
 */
function flat(menu) {
	return menu.flatMap((entry) => [entry, ...flat(entry.children || [])])
}

/**
 * The entries of one section, in the order the navigation draws them.
 *
 * @param {Array<object>} menu The built menu.
 * @param {string} section `main`, `footer`, `settings` or `integrations`.
 * @return {Array<object>} The entries, by `order`.
 */
function section(menu, section) {
	return menu
		.filter((entry) => (entry.section || 'main') === section)
		.sort((a, b) => (a.order ?? Infinity) - (b.order ?? Infinity))
}

describe('the full profile', () => {
	it('is exactly what buildManifest made before profiles existed', () => {
		const before = buildManifest(manifest(), fragments, fullFile)
		expect(build(fullFile)).toEqual(before)
	})

	it('still counts 35 entries: 13 main, 4 footer, 15 settings, 3 integrations', () => {
		const menu = build(fullFile).menu
		const count = (name) =>
			flat(menu.filter((entry) => (entry.section || 'main') === name)).length
		expect(flat(menu)).toHaveLength(35)
		expect(count('main')).toBe(13)
		expect(count('footer')).toBe(4)
		expect(count('settings')).toBe(15)
		expect(count('integrations')).toBe(3)
	})
})

describe('the simple profile', () => {
	const built = build(simpleFile)
	const main = section(built.menu, 'main')

	it('shows eight fixed entries, the first group without a caption as on DqZijbalk, in the order of the design', () => {
		expect(main.map((entry) => entry.id)).toEqual([
			'Dashboard',
			'WorkGroup',
			'Queue',
			'CasesCaption',
			'Cases',
			'WorkflowBoard',
			'Tasks',
			'MyCaseTypesCaption',
			'RelationsCaption',
			'Contacts',
			'OrganisationsMenu',
		])
		const captions = main.filter((entry) => entry.type === 'caption')
		expect(captions.map((entry) => entry.label)).toEqual([
			'Cases',
			'My case types',
			'Relations',
		])
		expect(main.filter((entry) => entry.type !== 'caption')).toHaveLength(8)
	})

	it('is flat: no entry holds another', () => {
		for (const entry of built.menu) {
			expect(entry.children ?? [], entry.id).toEqual([])
		}
	})

	it('keeps relocations out of the file, because any relocation step drops the captions', () => {
		// The library's relocation step ends by filtering out every entry with
		// no route, href, action or children. That is a caption. `{}` is enough
		// to run it, so the key has to be absent.
		expect(Object.hasOwn(simpleFile, 'relocations')).toBe(false)
		const withRelocations = build({ ...simpleFile, relocations: {} })
		expect(
			withRelocations.menu.filter((entry) => entry.type === 'caption'),
			'the library now keeps captions through relocations; the note in the profile file is stale',
		).toEqual([])
	})

	it('gives every entry a label, an icon the app registers and a page that exists', () => {
		const pageIds = new Set(built.pages.map((page) => page.id))
		for (const entry of main.filter((item) => item.type !== 'caption')) {
			expect(entry.label, entry.id).toBeTruthy()
			expect(entry.icon, entry.id).toBeTruthy()
			expect(
				iconsSource,
				`${entry.id} names an icon src/icons.js lacks`,
			).toContain(`\n\t${entry.icon},\n`)
			expect(pageIds.has(entry.route), `${entry.id} -> ${entry.route}`).toBe(
				true,
			)
		}
	})

	it('takes labels, icons and routes from the manifest and only the order from the profile', () => {
		const source = manifest().menu
		for (const id of [
			'Dashboard',
			'WorkGroup',
			'Cases',
			'Tasks',
			'Contacts',
			'OrganisationsMenu',
		]) {
			const original = source.find((entry) => entry.id === id)
			const shown = main.find((entry) => entry.id === id)
			expect(shown.label).toBe(original.label)
			expect(shown.icon).toBe(original.icon)
			expect(shown.route).toBe(original.route)
		}
		// Two entries are worded for the simple menu. Their page is the same.
		expect(main.find((entry) => entry.id === 'Queue')).toMatchObject({
			label: 'Team queue',
			route: 'Queue',
		})
		expect(main.find((entry) => entry.id === 'WorkflowBoard')).toMatchObject({
			label: 'Board',
			route: 'WorkflowBoard',
		})
	})

	it('names no case type itself: My case types holds what each user chose', () => {
		// The fixed Woo requests entry is gone. The entries under My case types
		// come from the /api/manifest delta at orders 31 to 60, so the caption
		// sits at 30 and Relations starts at 80, leaving room for all thirty.
		expect(main.some((entry) => entry.query?.caseType)).toBe(false)
		const caption = main.find((entry) => entry.id === 'MyCaseTypesCaption')
		expect(caption).toMatchObject({ type: 'caption', order: 30 })
		const relations = main.find((entry) => entry.id === 'RelationsCaption')
		expect(relations.order).toBeGreaterThan(60)

		// And the list reads `?caseType=` from the address: the library merges
		// the query into the fetch (useSelfFetchList.resolveQueryFilters), with
		// or without a pane. The full profile's folder pane filters on the same
		// key; the simple profile has no pane (the design has none) and the
		// case type stays a column of the list.
		const fullCases = build(fullFile).pages.find((page) => page.id === 'Cases')
		expect(fullCases.config.folderSidebar.filterField).toBe('caseType')
		const cases = built.pages.find((page) => page.id === 'Cases')
		expect(cases.config.folderSidebar).toBeUndefined()
		expect(
			cases.config.columns.some(
				(column) => overlayItemName(column) === 'caseType',
			),
		).toBe(true)
	})

	it('shows the instance as the brand of the navigation, without naming one', () => {
		// The profile asks the instance's theming for the name and the logo;
		// the app only puts its own name there.
		expect(simpleFile.nav.brand).toEqual({
			name: 'dossiq',
			caption: '@theming.name',
			logo: '@theming.emblem|@theming.logo',
		})
		// The set's emblem (thematiq `nldesign.logos.emblem`, the shield on
		// DqZijbalk) wins; the wordmark stands in when a set ships none.
		const withEmblem = buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			simpleFile,
			{
				theming: {
					name: 'Gemeente Voorbeeld',
					logo: '/core/img/logo.svg',
					emblem: '/apps/thematiq/img/logos/x-emblem.svg',
				},
			},
		)
		expect(withEmblem.nav.brand.logo).toBe(
			'/apps/thematiq/img/logos/x-emblem.svg',
		)
		const theming = { name: 'Gemeente Voorbeeld', logo: '/core/img/logo.svg' }
		const withTheming = buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			simpleFile,
			{ theming },
		)
		expect(withTheming.nav.brand).toEqual({
			name: 'dossiq',
			caption: 'Gemeente Voorbeeld',
			logo: '/core/img/logo.svg',
		})
		// An instance that answers nothing gets no caption and no logo: the
		// profile invents no municipality.
		expect(built.nav.brand).toEqual({ name: 'dossiq', caption: '', logo: '' })
		// The full profile declares no brand and gets none.
		expect(build(fullFile).nav).toBeUndefined()
		// main.js hands the theming capabilities over.
		expect(mainSource).toContain(
			"import { getCapabilities } from '@nextcloud/capabilities'",
		)
		expect(mainSource).toContain('theming: navTheming(getCapabilities())')
		expect(mainSource).toContain('capabilities?.nldesign?.logos?.emblem')
	})

	it('draws the sidebar of the design: New case, two counts, the day close card and help', () => {
		// DqZijbalk: a solid "Nieuwe zaak" button under the brand, a count
		// beside My work and the team queue, a "Dag afsluiten" card above the
		// footer and "Hulp en uitleg" in the footer. All are nextcloud-vue
		// 2.64.0 opt-ins; the full profile declares none of them.
		const manifestNewCase = manifest()
			.pages.find((page) => page.id === 'MyWorkHome')
			.config.headerActions.find((action) => action.id === 'new-case')
		// The schema wants the action's own id and label as well.
		expect(built.nav.primaryAction).toEqual({
			label: 'New case',
			icon: 'Plus',
			action: { ...manifestNewCase, id: 'nav-new-case' },
		})
		expect(built.nav.card.link.route).toBe('EndOfDay')
		expect(built.pages.some((page) => page.id === 'EndOfDay')).toBe(true)

		// The counts use the filters of the pages they open, so the number
		// beside an entry is the number of rows behind it.
		const entry = (entryId) =>
			flat(built.menu).find((item) => item.id === entryId)
		const queuePage = built.pages.find((page) => page.id === 'Queue')
		expect(entry('Queue').count).toEqual({
			register: 'dossiq',
			schema: 'case',
			filter: queuePage.config.filter,
		})
		expect(entry('WorkGroup').count).toEqual({
			register: 'dossiq',
			schema: 'case',
			filter: {
				assignee: '@me',
				isFinalStatus: false,
				statusHiddenInLists: false,
				isDraft: false,
			},
		})

		// Help is the documentation link already in the footer, renamed, so
		// the footer does not list the same page twice.
		expect(entry('Documentation')).toMatchObject({
			label: 'Help and explanation',
			icon: 'HelpCircleOutline',
			href: 'https://dossiq.conduction.nl',
			section: 'footer',
		})
		expect(built.nav.help).toBeUndefined()
		expect(iconsSource).toContain('\n\tHelpCircleOutline,\n')

		// DqZijbalk's footer is the settings foldout over "Hulp en uitleg" and
		// nothing else (nextcloud-vue 2.65.0 `nav.footer`): Store, Reports and
		// the roadmap move into the foldout, not out of reach. The foldout
		// keeps the library's own label, "Advanced" ("Geavanceerd").
		expect(built.nav.footer).toEqual(['settings', 'Documentation'])
		expect(built.nav.settingsLabel).toBeUndefined()

		const full = build(fullFile)
		expect(full.nav).toBeUndefined()
		expect(flat(full.menu).filter((item) => item.count !== undefined)).toEqual(
			[],
		)
		expect(
			flat(full.menu).find((item) => item.id === 'Documentation').label,
		).toBe('Documentation')
	})

	it('moves the recycle bin, the object register and the mail intake log to settings', () => {
		const settings = section(built.menu, 'settings').map((entry) => entry.id)
		expect(settings).toEqual(
			expect.arrayContaining([
				'CasesDeletedMenu',
				'CaseObjectsMenu',
				'MailIntakeLogMenu',
			]),
		)
		// Everything the full profile has in settings is still there.
		const fullSettings = section(build(fullFile).menu, 'settings').map(
			(entry) => entry.id,
		)
		expect(settings).toEqual(expect.arrayContaining(fullSettings))
	})

	it('keeps the footer links and the integrations where they were', () => {
		const full = build(fullFile).menu
		const ids = (menu, name) => section(menu, name).map((entry) => entry.id)
		expect(ids(built.menu, 'integrations')).toEqual(ids(full, 'integrations'))
		expect(ids(built.menu, 'footer')).toEqual(
			ids(full, 'footer').filter((id) => id !== 'MailIntakeLogMenu'),
		)
	})

	it('repeats the removals of the full profile, so an entry retired there does not come back here', () => {
		expect(simpleFile.removals).toEqual(
			expect.arrayContaining(fullFile.removals),
		)
		expect(simpleFile.settingsSection).toEqual(
			expect.arrayContaining(fullFile.settingsSection),
		)
		expect(simpleFile.integrationsSection).toEqual(fullFile.integrationsSection)
		expect(simpleFile.removalsReplacedBy).toEqual(fullFile.removalsReplacedBy)
	})

	it('loses nothing: every entry of the full menu is shown, or a page the simple menu opens links to it', () => {
		const shown = new Set(flat(built.menu).map((entry) => entry.id))
		const openedPages = new Set(
			flat(built.menu)
				.map((entry) => entry.route)
				.filter(Boolean),
		)
		// The pages a reader reaches from an opened page by one header link.
		const linked = new Set()
		for (const page of built.pages) {
			if (!openedPages.has(page.id)) {
				continue
			}
			for (const action of page.config?.headerActions ?? []) {
				if (action.type === 'open-page') {
					linked.add(action.target)
				}
			}
		}
		const lost = flat(build(fullFile).menu)
			.filter((entry) => !shown.has(entry.id))
			.filter((entry) => !linked.has(entry.route))
			.map((entry) => entry.id)
		expect(lost).toEqual([])

		// The control: the three entries this profile takes out are really out,
		// so the check above is passing on the links and not on the menu.
		for (const id of ['PersonalQueueMenu', 'MyWork', 'EndOfDayMenu']) {
			expect(shown.has(id), id).toBe(false)
		}
		expect([...linked].sort()).toEqual(['EndOfDay', 'MyWork', 'PersonalQueue'])
	})

	it('adds the links to My work and the dashboard without changing the manifest or the full profile', () => {
		const actionIds = (source, pageId) =>
			source.pages
				.find((page) => page.id === pageId)
				.config.headerActions.map((action) => action.id)

		expect(actionIds(built, 'MyWorkHome')).toEqual([
			'new-case',
			'open-your-queue',
			'open-assigned-to-me',
			'open-end-of-day',
		])
		expect(actionIds(built, 'Dashboard')).toEqual([
			'new-case',
			'open-end-of-day',
		])
		expect(actionIds(build(fullFile), 'MyWorkHome')).toEqual(['new-case'])
		expect(actionIds(build(fullFile), 'Dashboard')).toEqual(['new-case'])

		// Each link names a page that exists and an icon the app registers.
		const pageIds = new Set(built.pages.map((page) => page.id))
		for (const overlay of simpleFile.pages) {
			for (const action of overlay.configAppend?.headerActions ?? []) {
				expect(pageIds.has(action.target), action.id).toBe(true)
				expect(iconsSource).toContain(`\n\t${action.icon},\n`)
			}
		}
	})

	// 68 -> 66: tenancy step 5 retires the Tenants and TenantDetail pages.
	it('builds the same 66 pages as the full profile, so every route stays', () => {
		const ids = (source) => source.pages.map((page) => page.id)
		expect(ids(built)).toEqual(ids(build(fullFile)))
		expect(built.pages).toHaveLength(66)
	})
})

describe('a page overlay', () => {
	it('replaces config keys, appends to lists and leaves the original alone', () => {
		const page = { id: 'P', title: 'P', config: { a: 1, list: [{ id: 'x' }] } }
		const out = applyPageOverlay(page, {
			id: 'P',
			config: { a: 2, b: 3 },
			configAppend: { list: [{ id: 'y' }], fresh: [{ id: 'z' }] },
		})
		expect(out).toEqual({
			id: 'P',
			title: 'P',
			config: {
				a: 2,
				b: 3,
				list: [{ id: 'x' }, { id: 'y' }],
				fresh: [{ id: 'z' }],
			},
		})
		expect(page).toEqual({
			id: 'P',
			title: 'P',
			config: { a: 1, list: [{ id: 'x' }] },
		})
	})

	it('takes a config key out when the overlay sets it to null', () => {
		// A pane the page opts into by declaring it (the cases list's folder
		// pane) has no "off" value in the schema; null in the overlay leaves
		// the key out of the built page and the original alone.
		const page = { id: 'P', config: { pane: { source: 'register' }, keep: 1 } }
		const out = applyPageOverlay(page, { id: 'P', config: { pane: null } })
		expect(out.config).toEqual({ keep: 1 })
		expect('pane' in out.config).toBe(false)
		expect(page.config.pane).toEqual({ source: 'register' })
	})

	it('patches list items by id, takes out the ones set to null, and patches before it appends', () => {
		const page = {
			id: 'P',
			config: {
				list: [
					{ id: 'x', a: 1 },
					{ id: 'y', a: 1 },
					{ id: 'z', a: 1 },
				],
			},
		}
		const out = applyPageOverlay(page, {
			id: 'P',
			configPatch: {
				list: { x: { a: 2, b: 3 }, y: null, appended: { a: 9 } },
			},
			configAppend: { list: [{ id: 'appended', a: 1 }] },
		})
		// `appended` is not patched: it joins after the patch step.
		expect(out.config.list).toEqual([
			{ id: 'x', a: 2, b: 3 },
			{ id: 'z', a: 1 },
			{ id: 'appended', a: 1 },
		])
		expect(page.config.list).toHaveLength(3)
	})

	it('names a list item by id, then key, then label, and a bare string by itself', () => {
		const page = {
			id: 'P',
			config: {
				columns: ['number', { key: 'status', label: 'Status' }],
				lenses: [{ label: 'All' }, { label: 'Mine' }, { label: 'Closed' }],
			},
		}
		const out = applyPageOverlay(page, {
			id: 'P',
			configPatch: {
				columns: { number: null, status: { widget: 'badge' } },
				lenses: { Mine: { showCount: true } },
			},
			configAppend: { lenses: [{ label: 'Woo' }] },
			configOrder: { lenses: ['Mine', 'Woo', 'No such lens'] },
		})
		expect(out.config.columns).toEqual([
			{ key: 'status', label: 'Status', widget: 'badge' },
		])
		// Order runs last, so it can lead with an appended item. A name that
		// matches nothing is ignored, and the rest keep their order.
		expect(out.config.lenses).toEqual([
			{ label: 'Mine', showCount: true },
			{ label: 'Woo' },
			{ label: 'All' },
			{ label: 'Closed' },
		])
	})

	it('adds slots to the page and leaves a page without the key as it was', () => {
		const page = { id: 'P', slots: { a: 'A' }, config: {} }
		expect(applyPageOverlay(page, { id: 'P', slots: { b: 'B' } }).slots).toEqual(
			{
				a: 'A',
				b: 'B',
			},
		)
		expect(
			Object.hasOwn(
				applyPageOverlay({ id: 'Q', config: {} }, { id: 'Q' }),
				'slots',
			),
		).toBe(false)
	})

	it('is skipped and reported when it names a page the manifest does not have', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const out = build({
			...simpleFile,
			pages: [{ id: 'NoSuchPage', config: { a: 1 } }],
		})
		expect(out.pages.some((page) => page.id === 'NoSuchPage')).toBe(false)
		expect(warn).toHaveBeenCalledTimes(1)
		warn.mockRestore()
	})
})

describe('the structure setting', () => {
	it('reads anything that is not the word full as simple', () => {
		for (const raw of [
			undefined,
			null,
			'',
			'simple',
			'Full',
			'uitgebreid',
			1,
			true,
		]) {
			expect(resolveStructureProfile(raw)).toBe(STRUCTURE_SIMPLE)
		}
		expect(resolveStructureProfile('full')).toBe(STRUCTURE_FULL)
	})

	it('is what main.js picks the layout file by', () => {
		expect(mainSource).toContain(
			"import menuLayoutFull from './menu-layout.json'",
		)
		expect(mainSource).toContain(
			"import menuLayoutSimple from './menu-layout.simple.json'",
		)
		expect(mainSource).toContain("loadState('dossiq', STRUCTURE_SETTING, '')")
		expect(mainSource).toContain(
			'structureProfile === STRUCTURE_FULL ? menuLayoutFull : menuLayoutSimple',
		)
		expect(mainSource).toContain(
			'buildProfiledManifest(buildManifest, bundledManifest, fragments, menuLayout, {',
		)
	})

	it('is saved under its own key, through the settings write', async () => {
		const calls = []
		const fetchImpl = async (url, init) => {
			calls.push({ url, init })
			return {
				ok: true,
				status: 200,
				json: async () => ({
					success: true,
					config: { [STRUCTURE_SETTING]: 'full' },
				}),
			}
		}
		const stored = await saveMenuStructure('full', {
			url: '/apps/dossiq/api/settings',
			requestToken: 'token',
			fetchImpl,
		})
		expect(stored).toBe('full')
		expect(calls).toHaveLength(1)
		expect(calls[0].init.method).toBe('POST')
		expect(calls[0].init.headers.requesttoken).toBe('token')
		expect(JSON.parse(calls[0].init.body)).toEqual({ menu_structure: 'full' })
	})

	it('does not report a refused save as saved', async () => {
		const refused = async () => ({
			ok: false,
			status: 403,
			json: async () => ({}),
		})
		await expect(
			saveMenuStructure('full', {
				url: '/x',
				requestToken: 't',
				fetchImpl: refused,
			}),
		).rejects.toThrow('403')
	})

	it('does not report a save the server dropped as saved', async () => {
		// The settings write answers success for a key it does not know.
		const dropped = async () => ({
			ok: true,
			status: 200,
			json: async () => ({ success: true, config: {} }),
		})
		await expect(
			saveMenuStructure('full', {
				url: '/x',
				requestToken: 't',
				fetchImpl: dropped,
			}),
		).rejects.toThrow('did not store')
		// Simple is the default, so a dropped save of it would read back as
		// simple by accident. The stored word itself has to come back.
		await expect(
			saveMenuStructure('simple', {
				url: '/x',
				requestToken: 't',
				fetchImpl: dropped,
			}),
		).rejects.toThrow('did not store')
	})
})

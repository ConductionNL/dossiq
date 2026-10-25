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
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import {
	applyPageOverlay,
	buildProfiledManifest,
	overlayItemName,
	resolveStructureProfile,
	STRUCTURE_FULL,
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
	it('is exactly what buildManifest made before profiles existed, but for the team queue view', () => {
		const before = buildManifest(manifest(), fragments, fullFile)
		const built = build(fullFile)
		const others = (result) => ({ ...result, pages: result.pages.filter((page) => page.id !== 'Cases') })
		expect(others(built)).toEqual(others(before))
		const views = (result) => result.pages.find((page) => page.id === 'Cases').config.defaultSavedViews
		expect(views(built).filter((view) => view.slug !== 'team-queue')).toEqual(views(before).filter((view) => view.slug !== 'team-queue'))
	})

	it('keeps the Queue page it had as the team queue view: title and priority columns, case type folders', () => {
		const views = build(fullFile).pages.find((page) => page.id === 'Cases').config.defaultSavedViews
		const overrides = views.find((view) => view.slug === 'team-queue').overrides
		expect(overrides.title).toBe('Queue')
		expect(overrides.columns.map((column) => (typeof column === 'string' ? column : column.key))).toEqual(
			['identifier', 'title', 'caseType', 'status', 'priorityOrder', 'deadline'],
		)
		expect(overrides.folderSidebar).toMatchObject({ schema: 'caseType', filterField: 'caseType' })
		// The deep merge keeps what the patch does not name.
		expect(overrides.statRow).toHaveLength(3)
		expect(overrides.sidePanel).toHaveLength(2)
	})

	it('counts 39 entries: 12 main, 5 footer, 19 settings, 3 integrations (Case objects is settings-only; re-integration pathways is a Cases view, not a menu entry)', () => {
		const menu = build(fullFile).menu
		const count = (name) =>
			flat(menu.filter((entry) => (entry.section || 'main') === name)).length
		expect(flat(menu)).toHaveLength(39)
		expect(count('main')).toBe(12)
		expect(count('footer')).toBe(5)
		expect(count('settings')).toBe(19)
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
			'RelationsCaption',
			'Contacts',
			'OrganisationsMenu',
		])
		const captions = main.filter((entry) => entry.type === 'caption')
		expect(captions.map((entry) => entry.label)).toEqual([
			'Cases',
			'Relations',
		])
		expect(main.filter((entry) => entry.type !== 'caption')).toHaveLength(8)
	})

	it('is flat: no entry holds another', () => {
		for (const entry of built.menu) {
			expect(entry.children ?? [], entry.id).toEqual([])
		}
	})

	it('keeps its captions through the relocation step', () => {
		// The library's relocation step drops entries with no route, href,
		// action or children, but keeps a caption unless the relocations
		// emptied it. So a `relocations` key would not cost the captions.
		const withRelocations = build({ ...simpleFile, relocations: {} })
		expect(
			withRelocations.menu
				.filter((entry) => entry.type === 'caption')
				.map((entry) => entry.id),
		).toEqual(['CasesCaption', 'RelationsCaption'])
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
		// The team queue is worded for the simple menu; it opens its view of Cases.
		expect(main.find((entry) => entry.id === 'Queue')).toMatchObject({
			label: 'Team queue',
			route: 'Cases',
			query: { savedView: 'default:team-queue' },
		})
		// The Werkbord is the Cases list in its board view (board DqWerkbord).
		expect(main.find((entry) => entry.id === 'WorkflowBoard')).toMatchObject({
			label: 'Board',
			route: 'Cases',
			query: { _view: 'board' },
		})
	})

	it('names no case type itself: a pinned case type is a view, listed under My views', () => {
		// No Woo requests entry and no My case types heading: the case types a
		// user pins are views of the case list (case-type-pins-are-views), which
		// the library's My views section lists at the bottom of the menu.
		expect(main.some((entry) => entry.query?.caseType)).toBe(false)
		expect(main.some((entry) => entry.id === 'MyCaseTypesCaption')).toBe(false)

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
			name: '@theming.staffAppName|Dossiq',
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
			name: 'Dossiq',
			caption: 'Gemeente Voorbeeld',
			logo: '/core/img/logo.svg',
		})
		// An instance that answers nothing gets no caption and no logo: the
		// profile invents no municipality.
		expect(built.nav.brand).toEqual({ name: 'Dossiq', caption: '', logo: '' })
		// The name an administrator gives the app for staff replaces Dossiq.
		const named = buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			simpleFile,
			{ theming: { staffAppName: 'Zaken' } },
		)
		expect(named.nav.brand.name).toBe('Zaken')
		// The full profile declares no brand and gets none.
		expect(build(fullFile).nav).toBeUndefined()
		// main.js hands the theming capabilities over.
		expect(mainSource).toContain(
			"import { getCapabilities } from '@nextcloud/capabilities'",
		)
		expect(mainSource).toContain('theming: navTheming(getCapabilities())')
		expect(mainSource).toContain('capabilities?.nldesign?.logos?.emblem')
	})

	it('draws the sidebar of the design: New case, two counts and help, no day close card', () => {
		// DqZijbalk: a solid "Nieuwe zaak" button under the brand, a count
		// beside My work and the team queue and "Hulp en uitleg" in the
		// footer. All are nextcloud-vue 2.64.0 opt-ins; the full profile
		// declares none of them. The "Dag afsluiten" card the board draws
		// above the footer is gone from the menu (Ruben, 11 Oct 2026): the
		// day close page stays, reached from the Mijn werk header link.
		const manifestNewCase = manifest()
			.pages.find((page) => page.id === 'MyWorkHome')
			.config.headerActions.find((action) => action.id === 'new-case')
		// The schema wants the action's own id and label as well.
		expect(built.nav.primaryAction).toEqual({
			label: 'New case',
			icon: 'Plus',
			action: { ...manifestNewCase, id: 'nav-new-case' },
		})
		expect(built.nav.card).toBeUndefined()
		expect(built.pages.some((page) => page.id === 'EndOfDay')).toBe(true)
		const myWorkHome = built.pages.find((page) => page.id === 'MyWorkHome')
		expect(
			myWorkHome.config.viewLinks.some((link) => link.route === 'EndOfDay'),
		).toBe(true)

		// The counts use the filters of the pages they open, so the number
		// beside an entry is the number of rows behind it.
		const entry = (entryId) =>
			flat(built.menu).find((item) => item.id === entryId)
		const teamQueue = built.pages.find((page) => page.id === 'Cases').config.defaultSavedViews.find((view) => view.slug === 'team-queue')
		expect(entry('Queue').count).toEqual({
			register: 'dossiq',
			schema: 'case',
			filter: { ...teamQueue.filters, ...teamQueue.overrides.lockedFilters },
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
				'DocumentReviewReportsMenu',
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
			ids(full, 'footer').filter(
				(id) => id !== 'MailIntakeLogMenu' && id !== 'DocumentReviewReportsMenu',
			),
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
			// The My work page draws its links as pills on the view-switch
			// row (`viewLinks`, nextcloud-vue 2.79.0), as DqMijnWerk does.
			for (const link of page.config?.viewLinks ?? []) {
				linked.add(typeof link.route === 'string' ? link.route : link.route?.name)
			}
		}
		const lost = flat(build(fullFile).menu)
			.filter((entry) => !shown.has(entry.id))
			.filter((entry) => !linked.has(entry.route))
			.map((entry) => entry.id)
		expect(lost).toEqual([])

		// The control: the three entries this profile takes out are really out,
		// so the check above is passing on the links and not on the menu.
		for (const id of ['PersonalQueueMenu', 'EndOfDayMenu']) {
			expect(shown.has(id), id).toBe(false)
		}
		expect([...linked].sort()).toEqual(['Cases', 'EndOfDay', 'PersonalQueue'])
	})

	it('adds the links to My work and the dashboard without changing the manifest or the full profile', () => {
		const actionIds = (source, pageId) =>
			source.pages
				.find((page) => page.id === pageId)
				.config.headerActions.map((action) => action.id)

		// DqMijnWerk: the three links are pills on the switch row, not
		// buttons in the header.
		expect(actionIds(built, 'MyWorkHome')).toEqual(['new-case'])
		expect(
			built.pages
				.find((page) => page.id === 'MyWorkHome')
				.config.viewLinks.map((link) => link.route),
		).toEqual(['PersonalQueue', { name: 'Cases', query: { savedView: 'default:my-work' } }, 'EndOfDay'])
		// DqDashboard: Close out your day leads, the primary New case ends the row.
		expect(actionIds(built, 'Dashboard')).toEqual([
			'open-end-of-day',
			'new-case',
		])
		expect(actionIds(build(fullFile), 'MyWorkHome')).toEqual(['new-case'])
		expect(actionIds(build(fullFile), 'Dashboard')).toEqual(['new-case'])

		// Each link names a page that exists and an icon the app registers.
		const pageIds = new Set(built.pages.map((page) => page.id))
		for (const overlay of simpleFile.pages) {
			const links = (overlay.configAppend?.headerActions ?? []).filter(
				(action) => action.type === 'open-page',
			)
			for (const action of links) {
				expect(pageIds.has(action.target), action.id).toBe(true)
				expect(iconsSource).toContain(`\n\t${action.icon},\n`)
			}
		}
	})

	// 69 -> 67: tenancy step 5 retires the Tenants and TenantDetail pages.
	// 67 -> 68: woo-delivered-set-is-a-record adds one page.
	// 68 -> 67: the Workflow board page retires; `/workflow-board` redirects to the Cases board.
	// 67 -> 70: data subject requests, contracts and suppliers get their lists (DqPrivacy, DqContracten).
	// 70 -> 71: the Postvak (board DqPostvak) adds one page.
	// 71 -> 72: the catalogue gets a page (DqCatalogus).
	// 72 -> 73: the field inspection view (DqInspectie).
	// 73 -> 74: search results over cases, objects and documents (DqZoekresultaten).
	// 74 -> 76: the organisations on this environment get a list and a page (DqTenant).
	// 76 -> 77: the departments and staff get a page (DqAfdelingen).
	// 77 -> 78: the redaction page of a case (DqLakken).
	// 78 -> 82: the parts of a case type without an editor of their own (result, role, document and decision type) get a page each.
	// 82 -> 81: the CaseMap page retires; `/map` redirects to the Cases map view.
	// 81 -> 78: Queue, MyWork and PrivacyRequests became views of Cases.
	// 78 -> 77: Subsidies became a view of Cases.
	// 77 -> 75: DeelzaakList and DeelzaakDetail retire; their addresses redirect to CaseDetail.
	// 75 -> 74: SubstitutionAdmin retires; `/substitution-admin` goes to the admin settings.
	// 75 -> 74: ReIntegratieTrajecten retires; re-integration pathways are cases of their own case type.
	it('builds the same 74 pages as the full profile, so every route stays', () => {
		const ids = (source) => source.pages.map((page) => page.id)
		expect(ids(built)).toEqual(ids(build(fullFile)))
		expect(built.pages).toHaveLength(74)
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

	it('names a list item by id, then key, then slug, then label, and a bare string by itself', () => {
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

	it('names a default view by its slug and merges a patch into it deeply, arrays replaced', () => {
		const page = {
			id: 'P',
			config: {
				defaultSavedViews: [
					{ slug: 'queue', name: 'Queue', filters: { a: 1 }, overrides: { title: 'Team', columns: ['x', 'y'], header: { meta: 'm', notice: 'n' } } },
					{ slug: 'other', name: 'Other', filters: {} },
				],
			},
		}
		const out = applyPageOverlay(page, {
			id: 'P',
			configPatch: {
				defaultSavedViews: { queue: { overrides: { title: 'Queue', columns: ['z'], header: { meta: 'M' } } } },
			},
		})
		expect(out.config.defaultSavedViews).toEqual([
			{ slug: 'queue', name: 'Queue', filters: { a: 1 }, overrides: { title: 'Queue', columns: ['z'], header: { meta: 'M', notice: 'n' } } },
			{ slug: 'other', name: 'Other', filters: {} },
		])
		expect(page.config.defaultSavedViews[0].overrides.title).toBe('Team')
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
})

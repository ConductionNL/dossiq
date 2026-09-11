/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What identifies a case: its number, its tags and its statutory fields.
 *
 * Every assertion here guards a rule that fails SILENTLY. A `readOnly` schema
 * property is dropped from a data widget outright rather than rendered
 * greyed-out, a `visible: false` property is dropped from every surface at
 * once, an icon that is not in `src/icons.js` renders nothing rather than a
 * fallback glyph, and a sidebar tab whose widget type is not a registry type
 * renders an empty panel. None of those raise, so the page just quietly holds
 * less than the manifest says it does.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)
const caseSchema = register.components.schemas.case

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
function caseDetail() {
	return manifest.pages.find((page) => page.id === 'CaseDetail')
}

/** Every sidebar tab of the CaseDetail page. @return {Array} The tabs. */
function sidebarTabs() {
	return caseDetail().config.sidebar.tabs
}

/**
 * Whether an icon name is registered, and so will actually render.
 *
 * @param {string} name The PascalCase icon name.
 * @return {boolean} True when both the import and the export are present.
 */
function iconIsRegistered(name) {
	return (
		iconsSource.includes(
			`import ${name} from 'vue-material-design-icons/${name}.vue'`,
		) && new RegExp(`^\\t${name},$`, 'm').test(iconsSource)
	)
}

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The widget entry.
 */
function widget(id) {
	return caseDetail().config.widgets.find((entry) => entry.id === id)
}

describe('CaseDetail: the case number', () => {
	it('shows the number and refuses to let anyone type it', () => {
		const core = widget('case-core')
		expect(core.content.include).toContain('identifier')

		const override = core.content.overrides.identifier
		// `readOnly: false` is not a licence to edit — it re-admits a
		// schema-readOnly property to the grid, which fieldsFromSchema
		// otherwise filters out before any override is read. `editable: false`
		// is what keeps it read-only. Drop either half and the field is wrong
		// in a different direction: missing, or typeable.
		expect(override.readOnly).toBe(false)
		expect(override.editable).toBe(false)
	})

	it('never offers a number field on the New case form', () => {
		const dashboard = manifest.pages.find((page) => page.id === 'Dashboard')
		const newCase = dashboard.config.headerActions.find(
			(action) => action.id === 'new-case',
		)
		expect(newCase.includeFields).not.toContain('identifier')
		expect(Object.keys(newCase.props ?? {})).not.toContain('identifier')
	})
})

describe('CaseDetail: tags', () => {
	it('puts the tags in a sidebar tab, rendered by a registry type', () => {
		const tab = sidebarTabs().find((entry) => entry.id === 'tags')
		expect(tab).toBeTruthy()
		expect(tab.label).toBe('Tags')

		// CnObjectSidebar resolves a tab widget by TYPE against its built-ins
		// (data, metadata, audit, audit-trail, object-table) and then against
		// the app's custom registry. `custom` is in neither: it resolves to
		// null, warns to the console and renders an empty panel.
		expect(tab.widgets).toHaveLength(1)
		expect(tab.widgets[0].type).toBe('data')
		expect(tab.widgets[0].props.include).toEqual(['tags'])
		expect(tab.widgets[0].props.overrides.tags.widget).toBe('tags')
	})

	it('registers the tab icon, which otherwise renders nothing at all', () => {
		const tab = sidebarTabs().find((entry) => entry.id === 'tags')
		expect(iconIsRegistered(tab.icon)).toBe(true)
	})

	it('offers a Tags filter on the Cases index', () => {
		// The filter is not a manifest entry: `filtersFromSchema` builds the
		// index sidebar from the schema's `facetable` properties. So the
		// assertion belongs on the schema, and on the page having a sidebar to
		// put it in.
		expect(caseSchema.properties.tags.facetable).toBe(true)

		const cases = manifest.pages.find((page) => page.id === 'Cases')
		expect(cases.config.sidebar.enabled).toBe(true)
	})
})

describe('CaseDetail: terms and archive', () => {
	it('shows the lead time, the legal basis and the payment data', () => {
		// The ARCHIVAL half is not here. A data widget builds its fields from
		// the schema's own properties and reads them off the top level of the
		// record, so it cannot bind `@self._retention`, which is where the
		// archival answer now lives for every app, not just this one.
		const terms = widget('case-terms')
		expect(terms.type).toBe('data')
		expect(terms.content.include).toEqual([
			'statutoryTerm',
			'legalBasis',
			'paymentIndication',
			'lastPaymentDate',
		])
	})

	it('has no archiving card of its own any more', () => {
		// The card duplicated a category the object metadata panel already
		// groups. It also had to name the `@self._retention` keys in an
		// `include` list, which meant every rename in OpenRegister's resolver
		// silently blanked the card here: it did exactly that when #3584 moved
		// the keys to MDTO concepts. Reading the panel instead removes the
		// second copy of the list, so there is nothing left to fall behind.
		expect(widget('case-archival')).toBeUndefined()
	})

	it('keeps the metadata panel, which is where archiving is read now', () => {
		// The sidebar's metadata panel is CnObjectMetadataWidget over the whole
		// `@self` block, Archiving among its categories. Turning it off would
		// take the archival answer off the page entirely.
		expect(caseDetail().config.sidebar.showMetadata).toBe(true)
	})

	it('leaves both columns packed and ending on the same row', () => {
		// ADR-062: the two columns end level. Dropping a five-row card without
		// reclaiming its rows is the half of the change a manifest edit
		// forgets, and it shows up two ways: a hole where the card was, or a
		// short column beside a long one.
		//
		// The bottom edge alone does not catch the hole — a gap in the middle
		// still ends where it ended. So walk each column: every widget has to
		// start exactly where the one above it finished.
		const layout = caseDetail().config.layout
		const column = (x) =>
			layout
				.filter((w) => w.gridX === x && w.gridWidth < 12)
				.sort((a, b) => a.gridY - b.gridY)

		const walk = (x) => {
			let cursor = Math.min(...column(x).map((w) => w.gridY))
			for (const w of column(x)) {
				expect(
					[w.widgetId, w.gridY],
					`${w.widgetId} should start at row ${cursor}`,
				).toEqual([w.widgetId, cursor])
				cursor = w.gridY + w.gridHeight
			}
			return cursor
		}

		expect(walk(8)).toBe(walk(0))
	})

	it('keeps an empty row visible instead of hiding it', () => {
		// An absent destruction date is exactly what a records officer is
		// looking for, so the row has to be there and empty. `hideEmpty` is
		// off by default; it is written out so a later edit has to mean it.
		expect(widget('case-terms').content.hideEmpty).toBe(false)
	})

	it('re-admits the generated lead time and keeps it un-typeable', () => {
		const overrides = widget('case-terms').content.overrides
		expect(overrides.statutoryTerm.readOnly).toBe(false)
		expect(overrides.statutoryTerm.editable).toBe(false)
		// `archiveActionDate` no longer needs an un-editable override here: it
		// left this widget, and the archival card is read-only by construction.
		expect(overrides.archiveActionDate).toBeUndefined()
	})

	it('reads properties the case actually has, not a path through its type', () => {
		// A dotted include (`caseType.processingDeadline`) matches no entry of
		// `schema.properties`, so the row never renders and nothing says so.
		for (const key of widget('case-terms').content.include) {
			expect(key).not.toContain('.')
			expect(Object.keys(caseSchema.properties)).toContain(key)
		}
	})

	it('registers its icon, and takes a cell of its own', () => {
		expect(iconIsRegistered(widget('case-terms').icon)).toBe(true)

		const cell = caseDetail().config.layout.find(
			(entry) => entry.widgetId === 'case-terms',
		)
		expect(cell).toBeTruthy()
		expect(cell.gridX + cell.gridWidth).toBeLessThanOrEqual(12)
	})

	it('renders every statutory field, because none is hidden schema-side', () => {
		for (const key of widget('case-terms').content.include) {
			expect(caseSchema.properties[key].visible).not.toBe(false)
		}
	})
})

describe('the English demo seed', () => {
	const seed = JSON.parse(
		fs.readFileSync(
			path.join(
				ROOT,
				'lib',
				'Settings',
				'register.d',
				'46-demo-cases-english.json',
			),
			'utf8',
		),
	)
	const demoCases = seed.components.objects.filter(
		(entry) => entry['@self'].schema === 'case',
	)
	const caseTypes = Object.fromEntries(
		seed.components.objects
			.filter((entry) => entry['@self'].schema === 'caseType')
			.map((entry) => [entry['@self'].slug, entry]),
	)

	it('numbers every demo case the way the register will number the next one', () => {
		expect(demoCases.length).toBeGreaterThan(0)
		for (const demo of demoCases) {
			expect(demo.identifier, demo['@self'].slug).toMatch(/^\d{4}-\d{4}$/)
			// The year half is the start year, as `year(startDate)` gives it.
			expect(demo.identifier.slice(0, 4)).toBe(demo.startDate.slice(0, 4))
		}
	})

	it('leaves exactly two cases carrying wijk-noord, so filtering means something', () => {
		const tagged = demoCases.filter((demo) =>
			(demo.tags ?? []).includes('wijk-noord'),
		)
		expect(tagged).toHaveLength(2)
		expect(demoCases.filter((demo) => !demo.tags).length).toBeGreaterThan(2)
	})

	it('seeds a lead time that agrees with the case type it came from', () => {
		// `statutoryTerm` is materialised from `@ref.caseType.processingDeadline`
		// on save. Seeding a value that disagreed with the type would make the
		// page tell the truth only until the next save.
		for (const demo of demoCases.filter((entry) => entry.statutoryTerm)) {
			expect(demo.statutoryTerm, demo['@self'].slug).toBe(
				caseTypes[demo.caseType].processingDeadline,
			)
		}
	})

	it('leaves an archive action date empty on a case that shows the block', () => {
		const withNomination = demoCases.filter((demo) => demo.archiveNomination)
		expect(withNomination.length).toBeGreaterThan(0)
		expect(
			withNomination.some((demo) => demo.archiveActionDate === undefined),
		).toBe(true)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What case-type-authoring-extras declares across four files that no build
 * step compares: the register schema, the manifest, the icon registry and the
 * cell-widget registry.
 *
 * Each of these has a failure mode that is green on every gate and invisible
 * in the browser. A cell `widget` naming an id that is not in
 * `src/services/cellWidgets.js` renders the raw value. An `icon` that is not
 * in `src/icons.js` renders NO icon rather than a fallback glyph (gate-60). A
 * `filter` key that is not a property of the schema is dropped by
 * OpenRegister, so a default filter silently lists everything. A widget with
 * no layout cell is declared and never placed, and a tab naming a widget id
 * that does not exist renders a panel saying so.
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/property-definition-management/spec.md
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const cellWidgetsSource = fs.readFileSync(
	path.join(ROOT, 'src', 'services', 'cellWidgets.js'),
	'utf8',
)

/**
 * One page of the manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
const page = (id) => manifest.pages.find((entry) => entry.id === id)

/**
 * One schema of the dossiq register.
 *
 * @param {string} slug The schema slug.
 * @return {object} The schema.
 */
const schema = (slug) => register.components.schemas[slug]

/**
 * The Cases index column for a key.
 *
 * @param {string} key The column key.
 * @return {object|undefined} The column entry.
 */
function casesColumn(key) {
	return page('Cases').config.columns.find(
		(column) => typeof column === 'object' && column.key === key,
	)
}

/**
 * A quick-filter chip of the Cases index.
 *
 * @param {string} label The chip's label.
 * @return {object|undefined} The chip.
 */
function chip(label) {
	return page('Cases').config.quickFilters.find((entry) => entry.label === label)
}

describe('statusType carries a colour and a list visibility', () => {
	it('enumerates twelve colours rather than accepting a hex value', () => {
		const colour = schema('statusType').properties.colour
		expect(colour.type).toBe('string')
		expect(colour.enum).toHaveLength(12)
		for (const value of colour.enum) {
			expect(value).not.toMatch(/^#/)
		}
	})

	it('declares hiddenInLists as a boolean defaulting to false', () => {
		const hidden = schema('statusType').properties.hiddenInLists
		expect(hidden.type).toBe('boolean')
		expect(hidden.default).toBe(false)
	})

	it('leaves both properties readable and editable', () => {
		// `visible: false` hides a property on EVERY surface, and a schema
		// `readOnly` is dropped by the form builder before any override is
		// read. Either would ship a property an author cannot set.
		for (const name of ['colour', 'hiddenInLists']) {
			const property = schema('statusType').properties[name]
			expect(property.visible).toBeUndefined()
			expect(property.readOnly).toBeUndefined()
		}
	})

	it('moves the schema version, or OpenRegister fast-skips the import', () => {
		// A property added to a register JSON is inert until the register is
		// re-imported, and OpenRegister skips a schema whose version did not
		// change. 1.1.0 was the version that shipped the checklist.
		expect(schema('statusType').version).toBe('1.2.0')
	})
})

describe('the case mirrors the hidden flag so the list can filter on it', () => {
	it('calculates statusHiddenInLists off the linked statusType', () => {
		const calc =
			schema('case').configuration['x-openregister-calculations']
				.statusHiddenInLists
		expect(calc.materialise).toBe(true)
		expect(JSON.stringify(calc.expression)).toContain(
			'@ref.statusType.hiddenInLists',
		)
	})

	it('declares the mirrored property, and makes it facetable', () => {
		// Materialised and facetable is what lets the index narrow on it
		// SERVER-side; a client-side filter over server-paged rows drops the
		// rows it never fetched.
		const property = schema('case').properties.statusHiddenInLists
		expect(property.type).toBe('boolean')
		expect(property.facetable).toBe(true)
	})

	it('moves the case schema version too', () => {
		// AT LEAST 1.18.0, not exactly. A property on an unbumped version never
		// reaches an existing install, which is what this guards. Pinning the
		// exact number instead makes every LATER schema change fail here, on a
		// test that has nothing to say about it: 1.19.0 added a coalesce guard
		// to the identifier calculation and reddened this line.
		const [major, minor] = schema('case').version.split('.').map(Number)
		expect(major).toBeGreaterThanOrEqual(1)
		expect(major > 1 || minor >= 18).toBe(true)
	})
})

describe('the Cases index', () => {
	it('leaves hidden statuses out on its DEFAULT chip', () => {
		const all = chip('All')
		expect(all.default).toBe(true)
		expect(all.filter.statusHiddenInLists).toBe(false)
	})

	it('filters on the case property, never on a path into the $ref', () => {
		// `status` is a $ref, so a filter key `status.hiddenInLists` dot-paths
		// into a referenced object. OpenRegister answers no such filter and
		// drops it — a default filter that silently lists everything.
		for (const entry of page('Cases').config.quickFilters) {
			for (const key of Object.keys(entry.filter || {})) {
				expect(key).not.toContain('.')
			}
		}
	})

	it('lets the Closed chip list them again', () => {
		expect(chip('Closed').filter.statusHiddenInLists).toBeUndefined()
		expect(chip('Closed').filter.isFinalStatus).toBe(true)
	})

	it('draws the Status column through a registered cell widget', () => {
		expect(casesColumn('status').widget).toBe('statusBadge')
		expect(cellWidgetsSource).toContain('statusBadge:')
	})

	it('keeps the name formatter as the widget’s fallback label', () => {
		expect(casesColumn('status').formatter).toBe('statusTypeName')
	})
})

describe('the CaseTypeDetail page', () => {
	/**
	 * One widget of the page.
	 *
	 * @param {string} id The widget id.
	 * @return {object|undefined} The widget entry.
	 */
	const widget = (id) =>
		page('CaseTypeDetail').config.widgets.find((entry) => entry.id === id)

	/**
	 * The layout cells that place one widget.
	 *
	 * @param {string} id The widget id.
	 * @return {Array<object>} The cells.
	 */
	const cells = (id) =>
		page('CaseTypeDetail').config.layout.filter((cell) => cell.widgetId === id)

	it('shows the parent and the category on the core widget', () => {
		expect(widget('case-type-core').content.include).toContain('parentCaseType')
		expect(widget('case-type-core').content.include).toContain('category')
	})

	it('places every declared widget in exactly one layout cell', () => {
		// A widget with no cell is declared and never drawn; two cells draw it
		// twice. Both are green on the manifest validator.
		for (const entry of page('CaseTypeDetail').config.widgets) {
			expect(cells(entry.id), `a cell for ${entry.id}`).toHaveLength(1)
		}
	})

	it('places no cell that names a widget the page does not declare', () => {
		const declared = page('CaseTypeDetail').config.widgets.map((w) => w.id)
		for (const cell of page('CaseTypeDetail').config.layout) {
			expect(declared).toContain(cell.widgetId)
		}
	})

	it('resolves the custom widget through a slot to a registry entry', () => {
		// Three declarations no build step compares: the widget, its layout
		// cell, and the `widget-<id>` slot naming a registry key. Miss the
		// slot and the cell renders empty.
		const blueprint = widget('case-type-blueprint')
		expect(blueprint.type).toBe('custom')
		expect(page('CaseTypeDetail').slots['widget-case-type-blueprint']).toBe(
			'CaseTypeBlueprintWidget',
		)
		expect(registrySource).toContain('CaseTypeBlueprintWidget: {')
	})

	it('keeps the custom widget OUT of any tab strip', () => {
		// A `type: "custom"` widget named as a tab CHILD resolves by registry
		// TYPE, finds nothing, and renders an empty panel without logging.
		const tabs = page('CaseTypeDetail').config.widgets.filter(
			(entry) => entry.type === 'tabs',
		)
		for (const strip of tabs) {
			const named = (strip.content?.tabs || []).map((tab) => tab.widgetId)
			for (const id of named) {
				const child = widget(id)
				expect(child?.type, `${id} is a tab child`).not.toBe('custom')
			}
		}
	})
})

describe('the Case types index groups by category', () => {
	it('derives its folders from the rows’ own category values', () => {
		// `source: "facet"` does not exist. CnIndexPage resolves register,
		// field, custom and files, and an unknown source falls through to
		// `custom` — whose folder list is the absent `folders` array, so the
		// pane renders empty and nothing says why.
		const sidebar = page('CaseTypes').config.folderSidebar
		expect(['register', 'field', 'custom', 'files']).toContain(sidebar.source)
		expect(sidebar.field).toBe('category')
		expect(sidebar.filterField).toBe('category')
	})

	it('makes the category filterable at all', () => {
		// The filter behind the folder comes from `facetable: true` on the
		// property and from nothing else.
		expect(schema('caseType').properties.category.facetable).toBe(true)
	})

	it('names an All folder, so the index can be un-narrowed', () => {
		expect(page('CaseTypes').config.folderSidebar.allLabel).toBeTruthy()
	})

	it('shows the category as a column too', () => {
		expect(page('CaseTypes').config.columns).toContain('category')
	})
})

describe('an attribute without a case type is shared', () => {
	it('leaves caseType out of propertyDefinition’s required list', () => {
		expect(schema('propertyDefinition').required).not.toContain('caseType')
		expect(schema('propertyDefinition').required).toContain('name')
	})

	it('moves the propertyDefinition version, or the loosening is inert', () => {
		expect(schema('propertyDefinition').version).toBe('1.2.0')
	})
})

describe('the personal data block', () => {
	it('shows the four AVG fields the schema carries', () => {
		const widget = page('CaseTypeDetail').config.widgets.find(
			(entry) => entry.id === 'case-type-privacy',
		)
		expect(widget.content.include).toEqual([
			'processesPersonalData',
			'personalDataCategories',
			'legalBasis',
			'verwerkingsactiviteit',
		])
	})

	it('keeps an unanswered field visible, because the gap IS the finding', () => {
		const widget = page('CaseTypeDetail').config.widgets.find(
			(entry) => entry.id === 'case-type-privacy',
		)
		expect(widget.content.hideEmpty).toBe(false)
	})

	it('declares every AVG field on the schema, readable and writable', () => {
		for (const name of [
			'processesPersonalData',
			'personalDataCategories',
			'legalBasis',
			'verwerkingsactiviteit',
		]) {
			const property = schema('caseType').properties[name]
			expect(property, name).toBeTruthy()
			// `visible: false` hides a property on every surface at once, and
			// a schema `readOnly` is dropped by the form builder before any
			// override is read.
			expect(property.visible).toBeUndefined()
			expect(property.readOnly).toBeUndefined()
		}
	})

	it('enumerates the eleven AVG categories', () => {
		expect(
			schema('caseType').properties.personalDataCategories.items.enum,
		).toHaveLength(11)
	})

	it('carries OpenRegister’s article 6 vocabulary verbatim', () => {
		// OR's VerwerkingsactiviteitMapper refuses anything else; the Dutch
		// spellings this fleet used before failed all seven rows on every
		// fresh install. The enum is not dossiq's to translate.
		expect(schema('caseType').properties.legalBasis.enum).toEqual([
			'consent',
			'contract',
			'legal_obligation',
			'vital_interests',
			'public_task',
			'legitimate_interest',
		])
	})
})

describe('the four header actions', () => {
	/**
	 * One header action of the case type page.
	 *
	 * @param {string} id The action id.
	 * @return {object|undefined} The action.
	 */
	const action = (id) =>
		page('CaseTypeDetail').config.headerActions.find((entry) => entry.id === id)

	it('offers Export, Import, Duplicate and Publish', () => {
		expect(
			page('CaseTypeDetail').config.headerActions.map((a) => a.label),
		).toEqual(['Export', 'Import', 'Duplicate', 'Publish'])
	})

	it('declares no action of a type the library cannot dispatch', () => {
		// `run-action` is not a header-action type. The manifest schema
		// enumerates these eleven and CnActionButtons resolves exactly them;
		// anything else is a button that dispatches nothing.
		const dispatchable = [
			'handler',
			'open-modal',
			'open-page',
			'navigate',
			'object-op',
			'export',
			'open-form',
			'refresh',
			'api-call',
			'agent',
			'toggle',
		]
		for (const entry of page('CaseTypeDetail').config.headerActions) {
			expect(dispatchable, entry.id).toContain(entry.type)
		}
	})

	it('asks Export for the response as a download', () => {
		// Without `download: true` the blob is fetched and thrown away, and
		// the button reports success having saved nothing.
		expect(action('case-type-export').type).toBe('api-call')
		expect(action('case-type-export').download).toBe(true)
		expect(action('case-type-export').payload.caseTypeId).toBe('@objectId')
	})

	it('routes the three that need a field or a destination to a modal', () => {
		for (const id of [
			'case-type-import',
			'case-type-duplicate',
			'case-type-publish',
		]) {
			expect(action(id).type).toBe('open-modal')
			expect(registrySource).toContain(`${action(id).target}: {`)
		}
	})

	it('registers every modal target as kind modal', () => {
		// dispatchAction refuses an open-modal target whose kind is not
		// `modal`, and logs a warning nobody reads.
		for (const target of [
			'CaseTypeImportDialog',
			'CaseTypeDuplicateDialog',
			'CaseTypePublishDialog',
		]) {
			const entry = registrySource.slice(
				registrySource.indexOf(`${target}: {`),
			)
			expect(entry.slice(0, 120), target).toContain("kind: 'modal'")
		}
	})

	it('passes no @objectId token to a modal, which forwards props verbatim', () => {
		// `open-modal` does NOT resolve tokens in `props`: a `@objectId` there
		// arrives as that literal string, and the dialog acts on a case type
		// called "@objectId". The dialogs read the route instead.
		for (const entry of page('CaseTypeDetail').config.headerActions) {
			if (entry.type !== 'open-modal') continue
			const props = JSON.stringify(entry.props || {})
			expect(props, entry.id).not.toContain('@objectId')
		}
	})

	it('offers Publish only while the type is still a draft', () => {
		expect(action('case-type-publish').visibleWhen).toEqual({
			field: 'isDraft',
			op: 'eq',
			value: true,
		})
	})
})

describe('the Versions list', () => {
	it('lists the type’s own workflow templates, newest version first', () => {
		const widget = page('CaseTypeDetail').config.widgets.find(
			(entry) => entry.id === 'case-type-versions',
		)
		expect(widget.type).toBe('object-list')
		expect(widget.content.schema).toBe('workflowTemplate')
		expect(widget.content.filter).toEqual({ caseType: '@objectId' })
		expect(widget.content.sort).toEqual({ field: 'version', dir: 'desc' })
	})

	it('shows the version, its lifecycle status and the change note', () => {
		const widget = page('CaseTypeDetail').config.widgets.find(
			(entry) => entry.id === 'case-type-versions',
		)
		expect(widget.content.columns.map((c) => c.key)).toEqual([
			'version',
			'lifecycleStatus',
			'description',
			// `@self.updated`, not `updated`: the timestamp lives on
			// OpenRegister's metadata envelope, and a column bound to a
			// property the schema does not declare renders a dash in every row
			// and says nothing.
			'@self.updated',
		])
	})
})

describe('every icon this change names is registered', () => {
	it('registers each icon the touched pages name', () => {
		// gate-60: an icon that is not in src/icons.js renders NO icon at all.
		const named = new Set()
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
				return
			}
			if (node === null || typeof node !== 'object') return
			if (typeof node.icon === 'string' && node.icon !== '') {
				named.add(node.icon)
			}
			Object.values(node).forEach(walk)
		}
		for (const id of ['Cases', 'CaseTypes', 'CaseTypeDetail']) {
			walk(page(id))
		}
		for (const icon of named) {
			expect(iconsSource, `${icon} in src/icons.js`).toContain(
				`vue-material-design-icons/${icon}.vue`,
			)
		}
	})
})

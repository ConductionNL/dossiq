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
		// change. 1.1.0 shipped the checklist. 1.3.0 was claimed twice, by
		// what-a-status-declares (derivedWhen, waitingOn, maximumDwell) and by
		// phase-terms (phaseTermDays, phaseTermShare), each unaware of the
		// other. The merged schema carries both sets, so it has to move past
		// either: an instance that already imported one lane's 1.3.0 would
		// fast-skip the other's and every property in it would be inert.
		// 1.5.0 adds `fieldRules`, which an instance that stopped at 1.4.0
		// would never import: the status form would offer the rules, the rows
		// would carry them, and the publish would project nothing.
		expect(schema('statusType').version).toBe('1.5.0')
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
		// AT LEAST, not exactly. OpenRegister fast-skips a schema whose version
		// did not move, so what this guards is that the version went UP when
		// `statusHiddenInLists` landed. Pinning the literal made it fail on the
		// next change that legitimately bumps the same schema, which turns a
		// real guard into a merge conflict nobody learns anything from.
		const [major, minor] = schema('case')
			.version.split('.')
			.map((part) => Number(part))
		expect(major > 1 || (major === 1 && minor >= 18)).toBe(true)
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

describe('the case type page shows its version chain', () => {
	/**
	 * One widget of the page.
	 *
	 * @param {string} id The widget id.
	 * @return {object|undefined} The widget entry.
	 */
	const widget = (id) =>
		page('CaseTypeDetail').config.widgets.find((entry) => entry.id === id)

	/**
	 * One header action of the page.
	 *
	 * @param {string} id The action id.
	 * @return {object|undefined} The action.
	 */
	const action = (id) =>
		page('CaseTypeDetail').config.headerActions.find((entry) => entry.id === id)

	it('filters the chain on the shared identifier, not on this row', () => {
		// 🔴 `@objectId` would ask for the versions of THIS row, which is one
		// row, so the panel would always show a chain of one and nothing would
		// say it was the wrong question. Two rows are versions of one zaaktype
		// when they share ZGW's `identificatie`.
		const chain = widget('case-type-chain')
		expect(chain.type).toBe('object-list')
		expect(chain.content.schema).toBe('caseType')
		expect(chain.content.filter).toEqual({ identifier: '@object.identifier' })
		expect(chain.content.sort).toEqual({ field: 'version', dir: 'desc' })
	})

	it('binds every chain column to a real caseType property', () => {
		// A column bound to a property the schema does not declare renders a
		// dash in every row without saying so.
		const properties = schema('caseType').properties
		for (const column of widget('case-type-chain').content.columns) {
			expect(properties, `caseType.${column.key}`).toHaveProperty(column.key)
		}
	})

	it('shows the chain ABOVE the workflow template list', () => {
		// The two are easy to confuse: one lists versions of the case type, the
		// other lists revisions of its workflow. The order is what makes the
		// difference readable.
		const cellFor = (id) =>
			page('CaseTypeDetail').config.layout.find((c) => c.widgetId === id)
		expect(cellFor('case-type-chain').gridY).toBeLessThan(
			cellFor('case-type-versions').gridY,
		)
	})

	it('opens New version through a dialog, so it lands on the draft', () => {
		// An api-call refreshes the page you are already on, so the person who
		// asked for a new version would be left on the old one.
		expect(action('case-type-new-version').type).toBe('open-modal')
		expect(action('case-type-new-version').target).toBe(
			'CaseTypeNewVersionDialog',
		)
		expect(registrySource).toContain('CaseTypeNewVersionDialog: {')
	})

	it('offers New version on a published type, never on a draft', () => {
		expect(action('case-type-new-version').visibleWhen).toEqual({
			field: 'isDraft',
			op: 'neq',
			value: true,
		})
	})

	it('deprecates through the server, never by writing @today into a date', () => {
		// 🔴 An `object-op` merges its `values` into the row VERBATIM: the token
		// is not resolved for that action type, so `validUntil: "@today"` would
		// have stored that literal string in a date field with nothing
		// refusing it.
		const deprecate = action('case-type-deprecate')
		expect(deprecate.type).toBe('api-call')
		expect(deprecate.op).toBeUndefined()
		expect(deprecate.values).toBeUndefined()
		expect(deprecate.url).toBe(
			'/apps/dossiq/api/case-types/@objectId/deprecate',
		)
		expect(deprecate.method).toBe('POST')
		expect(deprecate.confirm).toBe(true)
	})

	it('shows Deprecate only where a published successor exists', () => {
		// The local operator set is eq/neq/gt/gte/lt/lte with no is-set, and
		// `supersededBy neq null` reads TRUE on an unset field, which is the
		// opposite of what it means. Counting the successors asks the same
		// question and can answer it.
		const when = action('case-type-deprecate').visibleWhen
		expect(when.source.schema).toBe('caseType')
		expect(when.source.filter).toEqual({
			previousVersion: '@objectId',
			isDraft: false,
		})
		expect(when.op).toBe('gt')
		expect(when.value).toBe(0)
	})
})

describe('the Case types index shows one row per case type', () => {
	/**
	 * The index's chips.
	 *
	 * @return {Array<object>} The quick filters.
	 */
	const chips = () => page('CaseTypes').config.quickFilters

	it('defaults to the version nothing has superseded', () => {
		const current = chips().find((chip) => chip.default === true)
		expect(current.label).toBe('Current versions')
		expect(current.filter).toEqual({ supersededBy: 'IS NULL' })
	})

	it('spells the empty test as the sentinel the Queue page uses', () => {
		// `assignee: "IS NULL"` is the literal sentinel every OpenRegister
		// condition builder matches by value. A different spelling here would
		// contribute no condition at all and list every version.
		const queue = page('Queue').config.filter.assignee
		expect(chips()[0].filter.supersededBy).toBe(queue)
	})

	it('offers a chip that drops the filter entirely', () => {
		const all = chips().find((chip) => chip.label === 'All versions')
		expect(all).toBeDefined()
		expect(all.filter).toEqual({})
		expect(all.default).toBeUndefined()
	})

	it('filters on a real, facetable caseType property', () => {
		expect(schema('caseType').properties).toHaveProperty('supersededBy')
		expect(schema('caseType').properties.supersededBy.facetable).toBe(true)
	})
})

describe('a running case can move along the chain', () => {
	/**
	 * One header action of the case page.
	 *
	 * @param {string} id The action id.
	 * @return {object|undefined} The action.
	 */
	const action = (id) =>
		page('CaseDetail').config.headerActions.find((entry) => entry.id === id)

	it('opens the move through a dialog that shows the preview', () => {
		// A confirm-gated api-call would be a button that rewrites a case's
		// vocabulary on trust: the preview is the act's substance.
		expect(action('case-version-move').type).toBe('open-modal')
		expect(action('case-version-move').target).toBe('CaseVersionMoveDialog')
		expect(registrySource).toContain('CaseVersionMoveDialog: {')
	})

	it('hides the move on a closed case', () => {
		expect(action('case-version-move').visibleWhen).toEqual({
			field: 'isFinalStatus',
			op: 'neq',
			value: true,
		})
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
		// OpenRegister re-imports a schema when its version moves, so the
		// loosened `required` list only reaches an installed instance if this
		// number is ahead of the one that shipped with `caseType` required.
		// The assertion used to pin the literal `1.2.0`, which made every
		// later edit of the schema red for the wrong reason: the clause is
		// that the version MOVED, not that it stopped at that number.
		const [major, minor] = schema('propertyDefinition')
			.version.split('.')
			.map(Number)
		expect(major * 1000 + minor).toBeGreaterThanOrEqual(1002)
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

describe('the header actions', () => {
	/**
	 * One header action of the case type page.
	 *
	 * @param {string} id The action id.
	 * @return {object|undefined} The action.
	 */
	const action = (id) =>
		page('CaseTypeDetail').config.headerActions.find((entry) => entry.id === id)

	it('offers Export, Import, Duplicate, New version, Deprecate and Publish', () => {
		// Sentence case throughout, and the order is the one an author meets
		// them in: what the type IS, then what to do with it, then the two acts
		// that make the next version and close the last one.
		expect(
			page('CaseTypeDetail').config.headerActions.map((a) => a.label),
		).toEqual([
			'Export',
			'Import',
			'Duplicate',
			'New version',
			'Deprecate',
			'Publish',
		])
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

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Parties tab's manifest-to-schema contract.
 *
 * Everything this change ships is config: a widget, a tab entry, a header
 * action and two index columns. Config fails the way config fails — a column
 * bound to a property nothing declares renders an em-dash forever, a filter on
 * a key the schema does not have returns every row on the instance, and an
 * `open-form` action that does not carry a required property answers 400 on a
 * button no CI run ever presses. None of those raise an error anywhere in the
 * build, so this file is the check that turns them into failing tests.
 *
 * Both files are read from disk rather than imported, so the assertions cover
 * the on-disk config the importer and the manifest renderer actually read.
 *
 * @spec openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')
const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
function page(id) {
	return loadJson(MANIFEST_PATH).pages.find((entry) => entry.id === id)
}

/**
 * One schema of the shipped dossiq register.
 *
 * @param {string} slug The schema slug.
 * @return {object} The schema entry.
 */
function schema(slug) {
	return loadJson(REGISTER_PATH).components.schemas[slug]
}

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The manifest widget id.
 * @return {object} The widget entry.
 */
function widget(id) {
	return page('CaseDetail').config.widgets.find((entry) => entry.id === id)
}

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The manifest action id.
 * @return {object} The action entry.
 */
function action(id) {
	return page('CaseDetail').config.headerActions.find((entry) => entry.id === id)
}

describe('the Parties widget', () => {
	it('lists the roles of the open case, sorted by role type', () => {
		const roles = widget('case-roles')

		expect(roles).toBeTruthy()
		expect(roles.type).toBe('object-list')
		expect(roles.content.register).toBe('dossiq')
		expect(roles.content.schema).toBe('role')
		expect(roles.content.filter).toEqual({ case: '@objectId' })
		expect(roles.content.sort).toEqual({ field: 'roleType', dir: 'asc' })
		expect(roles.content.limit).toBe(50)
		expect(roles.content.emptyText).toBeTruthy()
	})

	it('shows the role type, the participant and the delegation window', () => {
		expect(
			widget('case-roles').content.columns.map((column) => column.key),
		).toEqual(['roleType', 'participant', 'delegate', 'delegateUntil'])
	})

	it('reads only properties the role schema declares', () => {
		const properties = Object.keys(schema('role').properties)
		const roles = widget('case-roles')

		for (const key of Object.keys(roles.content.filter)) {
			expect(properties, `filter key ${key}`).toContain(key)
		}
		for (const column of roles.content.columns) {
			expect(properties, `column ${column.key}`).toContain(column.key)
		}
		expect(properties, 'the sort field').toContain(roles.content.sort.field)
	})

	it('replaces the Contacts tab rather than sitting beside it', () => {
		const detail = page('CaseDetail')
		const tabs = detail.config.widgets.find(
			(entry) => entry.id === 'case-panels',
		).content.tabs

		// `case-contacts` was the Nextcloud contacts integration leaf: it showed
		// the address book, never the people on this case. It is what the
		// Parties tab was standing in for, so it goes.
		expect(tabs.map((tab) => tab.widgetId)).not.toContain('case-contacts')
		expect(detail.config.widgets.map((entry) => entry.id)).not.toContain(
			'case-contacts',
		)
	})

	it('is a tab of the case panels and never a layout cell of its own', () => {
		const detail = page('CaseDetail')
		const tabs = detail.config.widgets.find(
			(entry) => entry.id === 'case-panels',
		).content.tabs

		expect(tabs.map((tab) => tab.widgetId)).toContain('case-roles')
		// A widget rendered by the tabs widget AND placed in `layout` renders
		// twice, which is why its siblings are absent from `layout` too.
		expect(
			(detail.config.layout || []).map((cell) => cell.widgetId),
		).not.toContain('case-roles')
	})
})

describe('the Add party action', () => {
	it('opens a role form from the case header', () => {
		const addParty = action('add-party')

		expect(addParty).toBeTruthy()
		expect(addParty.type).toBe('open-form')
		expect(addParty.register).toBe('dossiq')
		expect(addParty.schema).toBe('role')
		expect(addParty.icon).toBe('AccountPlusOutline')
		expect(addParty.successMessage).toBeTruthy()
	})

	it('seeds the case it was opened from and never asks for it', () => {
		const addParty = action('add-party')

		expect(addParty.props).toEqual({ case: '@objectId' })
		expect(addParty.includeFields).not.toContain('case')
	})

	it('asks the handler for the role, the participant and the delegation', () => {
		expect(action('add-party').includeFields).toEqual([
			'name',
			'roleType',
			'participant',
			'delegate',
			'delegateUntil',
			'description',
		])
	})

	it('seeds or asks every required property of the role schema', () => {
		// An `open-form` action POSTs straight to
		// /apps/openregister/api/objects — no dossiq service runs on that path,
		// so a required property the form does not ask for has to arrive from
		// `props`. Miss one and OpenRegister answers 400 with the required
		// list, at runtime, on a button no CI run presses. `name` is why the
		// form asks for a display name it could arguably derive: the schema
		// requires it and nothing else would supply it.
		const addParty = action('add-party')
		const asked = new Set(addParty.includeFields)
		const seeded = new Set(Object.keys(addParty.props))

		const missing = schema('role').required.filter(
			(key) => asked.has(key) === false && seeded.has(key) === false,
		)
		expect(
			missing,
			'a required property that is neither asked nor seeded makes every save from this button a 400',
		).toEqual([])
	})

	it('asks for or seeds nothing the role schema does not declare', () => {
		const addParty = action('add-party')
		const properties = Object.keys(schema('role').properties)

		for (const key of [
			...addParty.includeFields,
			...Object.keys(addParty.props),
		]) {
			expect(properties, `${key} is not a role property`).toContain(key)
		}
	})

	it('does not pretend to scope the role type picker', () => {
		// REQ-ROLE-002 wants the picker narrowed to the case type's own role
		// types. That needs `roleType.caseType` reached through `role.case`,
		// and CnFormDialog resolves an `x-relation-filter` only against the
		// form's OWN flat fields (`@object.<field>`): `@object.case.caseType`
		// resolves to nothing, and an unresolved entry is DROPPED rather than
		// applied. Declaring it anyway would read as the restriction while
		// listing every role type on the instance — the failure this asserts
		// against is a filter that looks present and does nothing.
		const roleType = schema('role').properties.roleType

		expect(
			roleType['x-relation-filter'],
			'a relation filter here can only resolve against role fields; scoping to the case type needs a platform hop',
		).toBeUndefined()
	})
})

describe('the Team column and the Mine chip', () => {
	const INDEXES = [
		['Cases', 'case', 'assignedGroup'],
		['Tasks', 'caseTask', 'assigneeGroup'],
	]

	it.each(INDEXES)(
		'%s shows a Team column reading the expanded team name',
		(id, slug, property) => {
			const config = page(id).config
			const keys = config.columns.map((column) =>
				typeof column === 'string' ? column : column.key,
			)

			// The nested key, not the bare property: `assignedGroup` is a $ref
			// and CnIndexPage renders a plain key raw, so a bare column would
			// show a uuid in every row and look like data rather than an error.
			expect(keys).toContain(`${property}.roleName`)
			expect(
				config.extend,
				'a nested column key only resolves when OpenRegister expands the reference on the fetch',
			).toContain(property)
			// Beside the personal assignee, never instead of it.
			expect(keys).toContain('assignee')
			expect(keys.indexOf(`${property}.roleName`)).toBeGreaterThan(
				keys.indexOf('assignee'),
			)
		},
	)

	it.each(INDEXES)(
		'%s can be narrowed to a team by facet',
		(id, slug, property) => {
			// The Team quick-filter chip is blocked on the platform resolving the
			// signed-in handler's organisatieRol rows, so the sidebar facet is the
			// only way to pick a team. A facet is opt-in per property.
			expect(page(id).config.sidebar.enabled).toBe(true)
			expect(schema(slug).properties[property].facetable).toBe(true)
		},
	)

	it.each(INDEXES)('%s opens on everything and offers Mine', (id) => {
		const chips = page(id).config.quickFilters

		expect(chips).toBeTruthy()
		const mine = chips.find((chip) => chip.label === 'Mine')
		expect(mine).toBeTruthy()
		expect(mine.filter).toEqual({ assignee: '@me' })

		// The chip bar activates its first tab, or the one marked `default`,
		// on mount. Without an all-rows chip marked default, the index would
		// silently open showing only the signed-in handler's own work —
		// a filter nobody asked for and no empty state explains.
		const initial = chips.find((chip) => chip.default === true) || chips[0]
		expect(initial.filter, 'the index must open unfiltered').toEqual({})
	})
})

describe('the team on the detail forms', () => {
	it('the case core widget offers the team beside the assignee', () => {
		// `case-core` names the fields it renders, so a property the schema
		// gained is invisible on the case page until it is listed here — no
		// error, no gap, just a field nobody can set.
		const include = widget('case-core').content.include

		expect(include).toContain('assignedGroup')
		expect(include.indexOf('assignedGroup')).toBe(
			include.indexOf('assignee') + 1,
		)
	})

	it('the task widget renders the whole schema, so the team comes for free', () => {
		const taskData = page('TaskDetail').config.widgets.find(
			(entry) => entry.id === 'task-data',
		)

		expect(taskData.content.include).toBeUndefined()
	})
})

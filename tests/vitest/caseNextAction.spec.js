// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What happens next on a case, declared across four files nothing compares.
 *
 * Gap register rows 3.27 and 3.28. The behaviour is pinned on the PHP side in
 * `MilestoneScheduleTest`, `PlannedActionChainTest` and
 * `PlannedActionServiceTest`. What those cannot see is the declaration layer,
 * where every failure is silent:
 *
 *  - a column bound to a property the schema does not declare renders a dash
 *    in every row and says nothing;
 *  - a `filter` key OpenRegister does not know is DROPPED, so a section meant
 *    to show the planned actions shows every action ever planned;
 *  - an `icon` that is not in `src/icons.js` renders no glyph at all (gate-60);
 *  - a `rowRoute` naming a page that does not exist pushes vue-router at a
 *    name it cannot resolve;
 *  - a schema added to a fragment and left out of the register's own
 *    `schemas` list is imported by nothing;
 *  - a controller method with no route in `appinfo/routes.php` 404s at
 *    runtime while looking like a working endpoint in the diff.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'register.d', '74-planned-actions.json'),
		'utf8',
	),
)
const milestoneFragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'register.d', '65-milestone-tracking.json'),
		'utf8',
	),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const routesSource = fs.readFileSync(
	path.join(ROOT, 'appinfo', 'routes.php'),
	'utf8',
)
const catalogueSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Queue', 'QueueSourceCatalogue.php'),
	'utf8',
)
const detectorSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Milestone', 'StalledCaseDetector.php'),
	'utf8',
)

/**
 * One widget of the case detail page, found anywhere in its tree.
 *
 * @param {string} id The widget id.
 *
 * @return {object|null} The widget, or null.
 */
function caseWidget(id) {
	const page = manifest.pages.find((entry) => entry.id === 'CaseDetail')
	let found = null
	const walk = (node) => {
		if (found !== null || node === null || typeof node !== 'object') return
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (node.id === id) {
			found = node
			return
		}
		Object.values(node).forEach(walk)
	}
	walk(page)
	return found
}

const plannedActionSchema = fragment.components.schemas.plannedAction
const plannedActionTypeSchema = fragment.components.schemas.plannedActionType

describe('the planned action is a declared record', () => {
	it('declares a type, an owner, a date and a state', () => {
		const properties = Object.keys(plannedActionSchema.properties)
		for (const key of ['actionType', 'owner', 'plannedFor', 'state']) {
			expect(properties, `${key} is declared`).toContain(key)
		}
	})

	it('names the three states, and no boolean stands in for them', () => {
		// Cancelling and completing are different acts: one says the chain
		// stops here, the other says this step happened. A boolean cannot
		// carry that difference, and the chain reads `cancelled` to decide
		// whether to plan anything.
		expect(plannedActionSchema.properties.state.enum).toEqual([
			'planned',
			'completed',
			'cancelled',
		])
		expect(plannedActionSchema.properties.state.default).toBe('planned')
	})

	it('declares the successor on the TYPE, not on the action', () => {
		// D-5: what follows what is a property of how the organisation works,
		// so it is administered once. A successor on the action would be a
		// chain retyped per case.
		expect(plannedActionTypeSchema.properties).toHaveProperty('successor')
		expect(plannedActionTypeSchema.properties).toHaveProperty(
			'successorOffsetWorkingDays',
		)
		expect(plannedActionSchema.properties).not.toHaveProperty('successor')
	})

	it('makes the chain readable backwards', () => {
		expect(plannedActionSchema.properties).toHaveProperty('plannedFrom')
	})

	it('facets the keys the queue and the case filter on', () => {
		// A filter on a property OpenRegister does not know is DROPPED, and the
		// read answers the whole table rather than erroring.
		for (const key of ['case', 'owner', 'state', 'plannedFor']) {
			expect(
				plannedActionSchema.properties[key].facetable,
				`${key} is facetable`,
			).toBe(true)
		}
	})

	it('registers both schemas on the dossiq register', () => {
		// A schema in a fragment but not in the register's own list is
		// imported by nothing and the feature is inert with no error.
		const registered = fragment.components.registers.dossiq.schemas
		expect(registered).toContain('plannedAction')
		expect(registered).toContain('plannedActionType')
	})
})

describe('the milestone carries an owner role', () => {
	it('adds ownerRole to milestoneDefinition', () => {
		expect(fragment.components.schemas.milestoneDefinition.properties).toHaveProperty(
			'ownerRole',
		)
	})

	it('moves the milestoneDefinition version, or the property is inert', () => {
		// OpenRegister re-imports a schema when its version moves. The base
		// fragment declares 1.0.0; this one has to be ahead of it.
		const base = milestoneFragment.components.schemas.milestoneDefinition.version
		const added = fragment.components.schemas.milestoneDefinition.version
		expect(base).toBe('1.0.0')
		expect(added).not.toBe(base)
	})

	it('reads dependsOn somewhere, or the field is still decoration', () => {
		// The whole of row 3.27 is that this field was declared and read by
		// nothing. The detector is the reader.
		expect(detectorSource).toContain('$this->schedule->project(')
	})
})

describe('the case says what happens next', () => {
	const section = () => caseWidget('case-planned-actions')

	it('puts a planned actions section on the case', () => {
		expect(section()).not.toBeNull()
		expect(section().content.schema).toBe('plannedAction')
		expect(section().content.register).toBe('dossiq')
	})

	it('shows what is planned, not what has been done', () => {
		expect(section().content.filter).toEqual({
			case: '@objectId',
			state: 'planned',
		})
	})

	it('puts the nearest action first, so the first row is the answer', () => {
		expect(section().content.sort).toEqual({ field: 'plannedFor', dir: 'asc' })
	})

	it('binds every column to a property the schema declares', () => {
		const properties = Object.keys(plannedActionSchema.properties)
		for (const column of section().content.columns) {
			expect(properties, `${column.key} is a plannedAction property`).toContain(
				column.key,
			)
		}
	})

	it('names no route for a page that does not exist', () => {
		expect(section().content.rowRoute).toBeUndefined()
		expect(
			manifest.pages.find((page) => page.id === 'PlannedActionDetail'),
		).toBeUndefined()
	})

	it('names an icon that is registered', () => {
		expect(iconsSource, `${section().icon} in src/icons.js`).toContain(
			`vue-material-design-icons/${section().icon}.vue`,
		)
	})

	it('says so when nothing is planned', () => {
		// An empty table under a heading called Planned actions reads as a
		// list that failed to load.
		expect(section().content.emptyText).toBeTruthy()
	})
})

describe('the work list says it too', () => {
	it('declares the planned actions as a queue source', () => {
		// A source class that is not in SOURCES is instantiated by nothing and
		// contributes no items, with no error anywhere.
		expect(catalogueSource).toContain('PlannedActionSource::class')
		expect(catalogueSource).toContain(
			'use OCA\\Dossiq\\Service\\Queue\\Source\\PlannedActionSource;',
		)
	})
})

describe('the endpoints are routed', () => {
	it('routes both controller methods', () => {
		// A controller method with no route entry 404s at runtime while
		// looking like a working endpoint in the diff (gate route-reachability).
		expect(routesSource).toContain("'name' => 'plannedAction#next'")
		expect(routesSource).toContain("'name' => 'plannedAction#complete'")
	})

	it('scopes both routes to a case', () => {
		// The action is looked up WITHIN the case named in the path, so the
		// per-case guard answers about the case the write lands on.
		expect(routesSource).toContain(
			"'url' => '/api/cases/{caseId}/planned-actions/next'",
		)
		expect(routesSource).toContain(
			"'url' => '/api/cases/{caseId}/planned-actions/{actionId}/complete'",
		)
	})
})

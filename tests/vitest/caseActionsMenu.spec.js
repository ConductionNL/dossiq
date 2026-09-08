/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a handler can do TO a case: copy it, start an allowed flow for it, and
 * plan a follow-up.
 *
 * Every assertion here guards a rule that fails SILENTLY. An `open-modal`
 * action whose target is not a `kind: "modal"` registry entry opens nothing
 * and logs one console warning; an icon that is not in `src/icons.js` renders
 * no glyph rather than a fallback; a widget named as a TAB child resolves by
 * registry TYPE, so a `type: "custom"` child renders an empty panel and says
 * nothing at all; and the reserved action id `copy` is dropped at render time
 * to avoid shadowing CnActionsBar's built-in, which would remove the entry
 * from the menu with no error anywhere.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	caseActionRefusal,
	earliestFollowUpDate,
	isPlanComplete,
	plannedRows,
	proposedCopyTitle,
} from '../../src/utils/caseActionsHelpers.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
function caseDetail() {
	return manifest.pages.find((page) => page.id === 'CaseDetail')
}

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The action id.
 * @return {object|undefined} The action.
 */
function headerAction(id) {
	return caseDetail().config.headerActions.find((action) => action.id === id)
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
 * Whether the registry declares a key of a given kind.
 *
 * Plain string search, never a regex built from the key. Escaping a caller's
 * string into a pattern is the incomplete-sanitisation shape CodeQL flags, and
 * it buys nothing here: the entry is a fixed two-line shape in a file this
 * test reads whole, so slicing forward from the key and looking for the `kind`
 * line answers the same question with no pattern at all.
 *
 * @param {string} key The registry key.
 * @param {string} kind The expected kind.
 * @return {boolean} True when the entry is declared with that kind.
 */
function registryDeclares(key, kind) {
	const isBareIdentifier = /^[A-Za-z_$][\w$]*$/.test(key)
	const declaration = `\n\t${isBareIdentifier ? key : `'${key}'`}: {`
	const at = registrySource.indexOf(declaration)
	if (at === -1) {
		return false
	}
	// The entry's own body only: stop at the closing brace so a NEIGHBOURING
	// entry's kind can never answer for this one.
	const body = registrySource.slice(at + declaration.length)
	const end = body.indexOf('\n\t},')
	return (end === -1 ? body : body.slice(0, end)).includes(`kind: '${kind}'`)
}

describe('registryDeclares', () => {
	// The helper decides seven assertions below, so it is asserted to be
	// capable of saying no. A predicate that answers true for everything is a
	// predicate that guards nothing.
	it('says no to a key the registry does not carry', () => {
		expect(registryDeclares('NoSuchDialog', 'modal')).toBe(false)
		expect(registryDeclares('no-such-widget', 'widget')).toBe(false)
	})

	it('says no when the kind is wrong', () => {
		expect(registryDeclares('CaseCopyDialog', 'widget')).toBe(false)
		expect(registryDeclares('case-related-planned', 'modal')).toBe(false)
	})
})

describe('Copy case', () => {
	it('is a header action on the case page', () => {
		expect(headerAction('copy-case')).toBeTruthy()
	})

	it('is not called `copy`, which CnActionsBar drops as reserved', () => {
		const ids = caseDetail().config.headerActions.map((a) => a.id)
		expect(ids).not.toContain('copy')
	})

	it('opens a modal the registry declares, so the click does something', () => {
		const action = headerAction('copy-case')
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CaseCopyDialog')
		expect(registryDeclares('CaseCopyDialog', 'modal')).toBe(true)
	})

	it('passes no props, because open-modal forwards them unresolved', () => {
		expect(headerAction('copy-case').props).toBeUndefined()
	})

	it('names an icon that is registered', () => {
		expect(iconIsRegistered(headerAction('copy-case').icon)).toBe(true)
	})

	it('has an endpoint behind it', () => {
		expect(routes).toContain("'caseActions#copy'")
		expect(routes).toContain('/api/case/{caseId}/copy')
	})
})

describe('Start a flow', () => {
	it('is a header action opening a modal the registry declares', () => {
		const action = headerAction('start-flow')
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CaseStartFlowDialog')
		expect(registryDeclares('CaseStartFlowDialog', 'modal')).toBe(true)
	})

	it('names an icon that is registered', () => {
		expect(iconIsRegistered(headerAction('start-flow').icon)).toBe(true)
	})

	it('is hidden on a case type that lists no startable flow', () => {
		const gate = headerAction('start-flow').visibleWhen
		expect(gate).toEqual({ field: 'hasStartableFlows', op: 'eq', value: true })
	})

	it('gates on a field of the CASE, which is all a local visibleWhen can see', () => {
		const register = JSON.parse(
			fs.readFileSync(
				path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
				'utf8',
			),
		)
		const gate = headerAction('start-flow').visibleWhen
		expect(register.components.schemas.case.properties[gate.field]).toBeTruthy()
	})

	it('has an endpoint behind it', () => {
		expect(routes).toContain("'caseActions#startableFlows'")
		expect(routes).toContain('/api/case/{caseId}/startable-flows')
	})
})

describe('Plan a follow-up', () => {
	it('is a header action opening a modal the registry declares', () => {
		const action = headerAction('plan-follow-up')
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CasePlanFollowUpDialog')
		expect(registryDeclares('CasePlanFollowUpDialog', 'modal')).toBe(true)
	})

	it('names an icon that is registered', () => {
		expect(iconIsRegistered(headerAction('plan-follow-up').icon)).toBe(true)
	})

	it('has an endpoint behind it, and a read for the planned rows', () => {
		expect(routes).toContain("'caseActions#plan'")
		expect(routes).toContain('/api/case/{caseId}/plan')
		expect(routes).toContain("'caseActions#planned'")
		expect(routes).toContain('/api/case/{caseId}/planned')
	})

	it('is swept single-shot by a registered background job', () => {
		const info = fs.readFileSync(path.join(ROOT, 'appinfo', 'info.xml'), 'utf8')
		expect(info).toContain('OCA\\Dossiq\\BackgroundJob\\PlannedFollowUpSweepJob')
	})
})

describe('The Related cases tab', () => {
	/** The CaseDetail widget definitions. @return {Array} The widgets. */
	function widgets() {
		return caseDetail().config.widgets
	}

	it('is rendered by a widget whose TYPE the registry answers to', () => {
		const widget = widgets().find((w) => w.id === 'case-related')
		expect(widget.type).toBe('case-related-planned')
		expect(registryDeclares('case-related-planned', 'widget')).toBe(true)
	})

	it('is not type custom, which resolves to nothing inside a tab panel', () => {
		const tabs = widgets().find((w) => w.id === 'case-panels').content.tabs
		const children = tabs.map((tab) => tab.widgetId)
		const byId = Object.fromEntries(widgets().map((w) => [w.id, w]))
		for (const id of children) {
			expect(
				byId[id],
				`tab child "${id}" has no widget definition`,
			).toBeTruthy()
			expect(byId[id].type, `tab child "${id}" is type custom`).not.toBe(
				'custom',
			)
		}
	})

	it('keeps its tab entry, its id and its label', () => {
		const tabs = widgets().find((w) => w.id === 'case-panels').content.tabs
		expect(tabs).toContainEqual({
			widgetId: 'case-related',
			label: 'Related cases',
		})
	})

	it('stays out of the layout, which would render it twice', () => {
		const placed = caseDetail().config.layout.map((item) => item.widgetId)
		expect(placed).not.toContain('case-related')
	})

	it('shares no layout cell with another widget', () => {
		const seen = new Map()
		for (const item of caseDetail().config.layout) {
			for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
				for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
					const cell = `${x},${y}`
					expect(
						seen.has(cell),
						`${item.widgetId} and ${seen.get(cell)} share cell ${cell}`,
					).toBe(false)
					seen.set(cell, item.widgetId)
				}
			}
		}
	})
})

describe('proposedCopyTitle', () => {
	it('proposes "Copy of <title>"', () => {
		expect(proposedCopyTitle('Dakkapel Kerkstraat 12')).toBe(
			'Copy of Dakkapel Kerkstraat 12',
		)
	})

	it('does not stack the prefix when a copy is copied', () => {
		expect(proposedCopyTitle('Copy of Dakkapel')).toBe('Copy of Dakkapel')
	})

	it('answers something usable for a case with no title', () => {
		expect(proposedCopyTitle('')).toBe('Copy')
		expect(proposedCopyTitle(null)).toBe('Copy')
	})
})

describe('caseActionRefusal', () => {
	const t = (s) => s

	it('turns each refusal code into a sentence', () => {
		expect(caseActionRefusal({ code: 'case_not_found' }, t)).toBe(
			'This case could not be read.',
		)
		expect(caseActionRefusal({ code: 'invalid_date' }, t)).toBe(
			'Pick a date for the follow-up.',
		)
		expect(caseActionRefusal({ code: 'plan_failed' }, t)).toBe(
			'The follow-up could not be planned.',
		)
	})

	it('never prints an unknown code at a handler', () => {
		const message = caseActionRefusal({ code: 'wat_is_dit' }, t)
		expect(message).toBe('This did not work. Try again.')
		expect(message).not.toContain('wat_is_dit')
	})

	it('survives a response with no body at all', () => {
		expect(caseActionRefusal(undefined, t)).toBe('This did not work. Try again.')
	})
})

describe('Plan a follow-up', () => {
	it('is not plannable for today, because a cron minute may already be past', () => {
		const earliest = earliestFollowUpDate(new Date('2026-09-08T12:00:00Z'))
		expect(earliest).toBe('2026-09-09')
	})

	it('needs a type, a date and a title', () => {
		const earliest = '2026-09-09'
		expect(
			isPlanComplete(
				{ caseType: 't', date: '2026-10-15', title: 'x' },
				earliest,
			),
		).toBe(true)
		expect(
			isPlanComplete(
				{ caseType: '', date: '2026-10-15', title: 'x' },
				earliest,
			),
		).toBe(false)
		expect(
			isPlanComplete({ caseType: 't', date: '', title: 'x' }, earliest),
		).toBe(false)
		expect(
			isPlanComplete(
				{ caseType: 't', date: '2026-10-15', title: ' ' },
				earliest,
			),
		).toBe(false)
	})

	it('refuses a date before the earliest', () => {
		expect(
			isPlanComplete(
				{ caseType: 't', date: '2026-09-01', title: 'x' },
				'2026-09-09',
			),
		).toBe(false)
	})
})

describe('plannedRows', () => {
	const t = (s) => s

	it('carries the date, which is the only thing a planned row adds', () => {
		const rows = plannedRows(
			[{ id: 'flow-1', title: 'Controle', date: '2026-10-15' }],
			t,
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].key).toBe('flow-1')
		expect(rows[0].label).toContain('Controle')
		expect(rows[0].label).toContain('2026-10-15')
	})

	it('uses no em-dash in the label', () => {
		const rows = plannedRows(
			[{ id: 'f', title: 'Controle', date: '2026-10-15' }],
			t,
		)
		expect(rows[0].label).not.toContain('—')
	})

	it('drops a row with no id rather than rendering a keyless one', () => {
		expect(plannedRows([{ title: 'Controle' }], t)).toEqual([])
		expect(plannedRows(null, t)).toEqual([])
	})
})

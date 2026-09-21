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
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	caseActionRefusal,
	earliestFollowUpDate,
	endOptions,
	isPlanComplete,
	plannedRows,
	proposedCopyTitle,
	recurrenceLabel,
	recurrenceOptions,
} from '../../src/utils/caseActionsHelpers.js'
import {
	buildActsMenu,
	endpointFor,
	inputsFor,
} from '../../src/utils/caseActsMenu.js'
const panels = require('./helpers/casePanels.js')

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

	it('has a stop endpoint, which is the only gesture a series needs extra', () => {
		expect(routes).toContain("'caseActions#stopSeries'")
		expect(routes).toContain('/api/case/{caseId}/planned/{flowId}/stop')
	})
})

describe('Planning a series', () => {
	const t = (s) => s

	it('offers the five recurrences the server accepts, and no others', () => {
		// The ids are the server's tokens. A drifting list here is refused with
		// `invalid_recurrence` rather than silently planned as a one-off, which
		// is why the ids are asserted and not just the count.
		expect(recurrenceOptions(t).map((row) => row.id)).toEqual([
			'none',
			'monthly',
			'quarterly',
			'halfYearly',
			'yearly',
		])
	})

	it('offers three ends: never, a date, a count', () => {
		expect(endOptions(t).map((row) => row.id)).toEqual([
			'open',
			'until',
			'count',
		])
	})

	it('uses sentence case and no em-dash in every option label', () => {
		// Sentence case is checked on the words AFTER the first: a capital
		// mid-label is Title Case, which the voice bans outright (voice.md
		// section 8). The previous form of this assertion compared a string
		// with itself and could not fail.
		for (const row of [...recurrenceOptions(t), ...endOptions(t)]) {
			expect(row.label).not.toContain('—')
			expect(row.label).not.toContain('--')
			const rest = row.label.split(' ').slice(1)
			expect(
				rest.filter((word) => /^[A-Z]/.test(word)),
				`"${row.label}" is Title Case`,
			).toEqual([])
		}
	})

	it('says nothing about repeating for a follow-up that happens once', () => {
		expect(recurrenceLabel('none', t)).toBe('')
		expect(recurrenceLabel('yearly', t)).toBe('every year')
	})

	it('needs an end date on or after the first occurrence', () => {
		const base = {
			caseType: 'type-1',
			date: '2026-10-15',
			title: 'Controle',
			recurrence: 'yearly',
			end: 'until',
		}
		expect(isPlanComplete({ ...base, until: '2029-10-15' }, '2026-09-14')).toBe(
			true,
		)
		expect(isPlanComplete({ ...base, until: '2026-01-01' }, '2026-09-14')).toBe(
			false,
		)
		expect(isPlanComplete({ ...base, until: '' }, '2026-09-14')).toBe(false)
	})

	it('needs at least one case when the series ends on a count', () => {
		const base = {
			caseType: 'type-1',
			date: '2026-10-15',
			title: 'Controle',
			recurrence: 'yearly',
			end: 'count',
		}
		expect(isPlanComplete({ ...base, count: 3 }, '2026-09-14')).toBe(true)
		expect(isPlanComplete({ ...base, count: 0 }, '2026-09-14')).toBe(false)
	})

	it('asks nothing extra of a follow-up that happens once', () => {
		expect(
			isPlanComplete(
				{
					caseType: 'type-1',
					date: '2026-10-15',
					title: 'Controle',
					recurrence: 'none',
				},
				'2026-09-14',
			),
		).toBe(true)
	})

	it('lets a series run with no end at all', () => {
		expect(
			isPlanComplete(
				{
					caseType: 'type-1',
					date: '2026-10-15',
					title: 'Vergunningcontrole',
					recurrence: 'yearly',
					end: 'open',
				},
				'2026-09-14',
			),
		).toBe(true)
	})
})

describe('The Related cases tab', () => {
	/** The CaseDetail widget definitions. @return {Array} The widgets. */
	function widgets() {
		return caseDetail().config.widgets
	}

	it('is rendered by a widget whose TYPE the registry answers to', () => {
		const widget = panels.caseWidget('case-related')
		expect(widget.type).toBe('case-related-planned')
		expect(registryDeclares('case-related-planned', 'widget')).toBe(true)
	})

	it('is not type custom, which resolves to nothing inside a tab panel', () => {
		const tabs = widgets().find((w) => w.id === 'case-panels').content.tabs
		const children = tabs.map((tab) => tab.widgetId)
		const byId = Object.fromEntries(
			panels.allCaseWidgets().map((w) => [w.id, w]),
		)
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

	it('is the first section of the Related tab, labelled for what it lists', () => {
		// It had a tab of its own until the strip came down from fourteen tabs
		// to six. It is the top half of Related now, with the sub-cases under
		// it: both halves are cases connected to this one, upward and downward.
		const where = panels.caseTabOf('case-related')
		expect(where, 'case-related is not reachable from the strip').toBeTruthy()
		expect(where.tab).toBe('Related')
		expect(where.label).toBe('Related cases')

		const sections = panels
			.caseWidget('case-related-panel')
			.content.sections.map((section) => section.widget.id)
		// Objects joined the Related tab on 2026-09-13, from the retired
		// Objects and locations tab, and the Data model link followed it
		// (data-model-link). Related cases stays FIRST, which is what this
		// test is about; the list is exact so a silent reorder reddens.
		expect(sections).toEqual([
			'case-related',
			'case-sub-cases',
			'case-objects',
			'case-object-types-link',
		])
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

	it('says how often a series comes back and how many it has opened', () => {
		const rows = plannedRows(
			[
				{
					id: 'flow-1',
					title: 'Jaarlijkse controle',
					date: '2027-03-01',
					recurrence: 'yearly',
					occurrences: [
						{ id: 'case-a', title: 'Controle 2026' },
						{ id: 'case-b', title: 'Controle 2027' },
					],
				},
			],
			t,
		)
		expect(rows[0].recurrence).toBe('yearly')
		expect(rows[0].label).toContain('every year')
		expect(rows[0].label).toContain('2027-03-01')
		expect(rows[0].label).toContain('2 opened so far')
		expect(rows[0].occurrences).toHaveLength(2)
	})

	it('leaves a single follow-up reading as it did', () => {
		const rows = plannedRows(
			[{ id: 'flow-2', title: 'Controle', date: '2026-10-15' }],
			t,
		)
		expect(rows[0].recurrence).toBe('none')
		expect(rows[0].label).not.toContain('every')
		expect(rows[0].occurrences).toEqual([])
	})

	it('uses no em-dash in a series label either', () => {
		const rows = plannedRows(
			[
				{
					id: 'f',
					title: 'Controle',
					date: '2026-10-15',
					recurrence: 'quarterly',
					occurrences: [{ id: 'c' }],
				},
			],
			t,
		)
		expect(rows[0].label).not.toContain('—')
		expect(rows[0].label).not.toContain('--')
	})
})

describe('The series row on the Related tab', () => {
	const widget = fs.readFileSync(
		path.join(ROOT, 'src', 'components', 'case', 'CasePlannedWidget.vue'),
		'utf8',
	)

	it('offers Stop series, and posts it to the endpoint the routes declare', () => {
		expect(widget).toContain('case-planned-stop-')
		expect(widget).toContain('/planned/${encodeURIComponent(flowId)}/stop')
		expect(routes).toContain('/api/case/{caseId}/planned/{flowId}/stop')
	})

	it('lists the occurrences under the series row', () => {
		expect(widget).toContain('case-planned-occurrence-')
		expect(widget).toContain('row.occurrences')
	})

	it('offers Stop series only on the rows that repeat', () => {
		expect(widget).toContain("row.recurrence !== 'none'")
	})

	it('says out loud when a stop is refused', () => {
		// A stop that silently does nothing and a stop that worked look
		// identical on this tab: the row only disappears on the reload.
		expect(widget).toContain('case-planned-error')
		expect(widget).toContain('caseActionRefusal')
	})
})

describe('Inspect, for an administrator', () => {
	/** The gate both entries carry. */
	const GATE = {
		endpoint: '/index.php/apps/dossiq/api/inspect/availability',
		field: 'isAdmin',
		op: 'eq',
		value: true,
	}

	it('is TWO flat header actions, because a detail page drops children', () => {
		// 🔴 THE ASSERTION THIS BLOCK EXISTS FOR. The design asked for one
		// Inspect action carrying two children. CnDetailPage runs
		// CnActionButtons in `display: "menu"`, and that mode renders no
		// buttons itself: it emits `menuEntries`, which maps the visible
		// actions and never looks at `children`. The chevron the `children`
		// key documents renders only in the inline `barActions` mode, which a
		// detail page never uses. A parent with children would have shown one
		// menu item and neither entry, warning nobody.
		for (const id of ['case-inspect-raw', 'case-inspect-runs']) {
			expect(
				headerAction(id),
				`${id} must be a header action of its own`,
			).toBeTruthy()
			expect(headerAction(id).children).toBeUndefined()
		}
		expect(headerAction('case-inspect')).toBeUndefined()
	})

	it('gates BOTH entries on the READER, which only an endpoint can ask about', () => {
		// The design named `adminOnly`. There is no such key: an action is a
		// CLOSED object in the schema, so the manifest would not have
		// validated with one. Of visibleWhen's three modes only `endpoint`
		// knows who is looking: a local gate dot-paths into the case record
		// and a source gate queries OpenRegister objects.
		for (const id of ['case-inspect-raw', 'case-inspect-runs']) {
			expect(headerAction(id).visibleWhen).toEqual(GATE)
			expect(headerAction(id).adminOnly).toBeUndefined()
		}
	})

	it('has the endpoint behind it', () => {
		expect(routes).toContain("'inspect#availability'")
		expect(routes).toContain('/api/inspect/availability')
	})

	it('names icons that are registered', () => {
		for (const id of ['case-inspect-raw', 'case-inspect-runs']) {
			expect(iconIsRegistered(headerAction(id).icon)).toBe(true)
		}
	})

	it('opens the raw data on a modal the registry declares', () => {
		// An `open-modal` whose target is not a registry entry of kind `modal`
		// opens NOTHING and logs one console warning. The design named
		// `CnObjectMetadataModal`, a library component that is in no registry.
		expect(headerAction('case-inspect-raw').type).toBe('open-modal')
		expect(headerAction('case-inspect-raw').target).toBe('CaseRawDataDialog')
		expect(registryDeclares('CaseRawDataDialog', 'modal')).toBe(true)
	})

	it('sends the flow runs to the OpenRegister runs page, filtered to this case', () => {
		expect(headerAction('case-inspect-runs').type).toBe('navigate')
		expect(headerAction('case-inspect-runs').target).toContain(
			'/apps/openregister/#/flows/runs',
		)
		// `{objectId}`, the spelling the library interpolates. `{id}` resolved to
		// nothing and reached the address bar as those four characters.
		expect(headerAction('case-inspect-runs').target).toContain(
			'subjectUuid={objectId}',
		)
	})

	it('labels both entries in sentence case, with no em-dash', () => {
		for (const id of ['case-inspect-raw', 'case-inspect-runs']) {
			const label = headerAction(id).label
			expect(label).not.toContain('—')
			expect(label.split(' ').slice(1).join(' ')).toBe(
				label.split(' ').slice(1).join(' ').toLowerCase(),
			)
		}
	})
})

describe('The Raw data dialog', () => {
	const dialog = fs.readFileSync(
		path.join(ROOT, 'src', 'dialogs', 'CaseRawDataDialog.vue'),
		'utf8',
	)

	it('reads the case from the route, because open-modal carries no object', () => {
		expect(dialog).toContain('this.$route?.params?.id')
	})

	it('reads the stored object from OpenRegister, not from a dossiq copy', () => {
		expect(dialog).toContain('/apps/openregister/api/objects/dossiq/case/')
	})

	it('prints the whole record, indented', () => {
		expect(dialog).toContain('JSON.stringify(data, null, 2)')
		expect(dialog).toContain('case-raw-data-json')
	})

	it('says out loud when the case cannot be read', () => {
		// A dialog that opens blank on a refusal is the failure this whole
		// change exists to prevent: the reader is already here because
		// something does not add up.
		expect(dialog).toContain('case-raw-data-error')
		expect(dialog).toContain('You may not read this case.')
	})
})

describe('Archive and Restore on the case page', () => {
	const menuSource = fs.readFileSync(
		path.join(ROOT, 'src', 'utils', 'caseActsMenu.js'),
		'utf8',
	)

	/**
	 * One source file with its prose taken out.
	 *
	 * The assertions below are about what the code READS, and both files
	 * explain in a comment why they do not read `archiveStatus`. Matching the
	 * raw text would fail on the sentence that documents the rule, which is
	 * the test punishing the explanation.
	 *
	 * @param {string} source The file contents.
	 * @return {string} The source with block, line and HTML comments removed.
	 */
	const codeOf = (source) =>
		source
			.replace(/<!--[\s\S]*?-->/g, '')
			.replace(/\/\*[\s\S]*?\*\//g, '')
			.replace(/^\s*\/\/.*$/gm, '')

	/** An `/acts` answer where the handler may perform every ending act. */
	const allowed = {
		acts: [
			{ act: 'finish', allowed: true, role: '', reason: '' },
			{ act: 'abort', allowed: true, role: '', reason: '' },
			{ act: 'archive', allowed: true, role: '', reason: '' },
		],
	}

	/** The menu ids for one `/acts` answer. @param {object} acts The answer. @return {Array<string>} The ids. */
	const ids = (acts) => buildActsMenu({ acts }).map((entry) => entry.id)

	it('offers Archive on a case that is not archived', () => {
		expect(ids(allowed)).toContain('archive')
		expect(ids(allowed)).not.toContain('unarchive')
	})

	it('offers Restore instead of Archive once the case is archived', () => {
		// Never both. The platform treats a second archive as a no-op rather
		// than a refusal, so an Archive entry left beside Restore would be a
		// button that changes nothing and says nothing.
		const menu = ids({ ...allowed, archived: true })
		expect(menu).toContain('unarchive')
		expect(menu).not.toContain('archive')
	})

	it('keeps Finish and Abort on an archived case, refused by their own guards', () => {
		const menu = ids({ ...allowed, archived: true })
		expect(menu).toContain('finish')
		expect(menu).toContain('abort')
	})

	it('reads the archived flag the way every JSON boolean in this app is read', () => {
		expect(ids({ ...allowed, archived: 'true' })).toContain('unarchive')
		expect(ids({ ...allowed, archived: 1 })).toContain('unarchive')
		expect(ids({ ...allowed, archived: false })).not.toContain('unarchive')
		expect(ids(allowed)).not.toContain('unarchive')
	})

	it('gives Restore the verdict archiving carries, because it needs the same role', () => {
		const refused = {
			archived: true,
			acts: [
				{
					act: 'archive',
					allowed: false,
					role: 'archivaris',
					reason: 'This act needs the archivaris group.',
				},
			],
		}
		const entry = buildActsMenu({ acts: refused }).find(
			(row) => row.id === 'unarchive',
		)
		expect(entry.disabled).toBe(true)
		expect(entry.reason).toContain('archivaris')
		expect(entry.role).toBe('archivaris')
	})

	it('disables Restore when the acts read failed, rather than offering it', () => {
		const entry = buildActsMenu({ acts: { archived: true } }).find(
			(row) => row.id === 'unarchive',
		)
		expect(entry.disabled).toBe(true)
		expect(entry.reason).not.toBe('')
	})

	it('asks for a reason before it posts, and posts to the route that exists', () => {
		const entry = buildActsMenu({ acts: { ...allowed, archived: true } }).find(
			(row) => row.id === 'unarchive',
		)
		expect(inputsFor(entry).reason).toBe(true)
		expect(endpointFor(entry)).toBe('unarchive')
		expect(routes).toContain('/api/case/{caseId}/unarchive')
	})

	it('reads the platform marker and never the ZGW field', () => {
		// 🔴 `archiveStatus` is the ZGW fact and the lists do not look at it.
		// A menu built on it would offer Restore on a case imported carrying
		// `archiefstatus: gearchiveerd` that is still in every working lens,
		// and the button would take nothing back.
		expect(codeOf(menuSource)).not.toContain('archiveStatus')
	})

	it('renders the strip from the marker on the object the page already holds', () => {
		const strip = fs.readFileSync(
			path.join(ROOT, 'src', 'components', 'case', 'CaseArchivedStrip.vue'),
			'utf8',
		)
		// `objectData` and not `object`: CnDetailWidgetHost binds the former,
		// so a prop called `object` arrives null and the strip is silent on
		// every archived case with nothing reporting it.
		expect(strip).toContain('objectData')
		expect(strip).toContain('self.archived')
		expect(codeOf(strip)).not.toContain('archiveStatus')
		// Silent on a case that is not archived, which is almost every case.
		expect(strip).toContain('v-if="archived"')
	})

	it('leads the banner stack, which is the one row the page mounts the strips in', () => {
		// The strips share ONE grid row (CaseBannerStack): a root v-if strip in
		// a row of its own stays reserved when it renders nothing, and this one
		// is empty on almost every case. It comes FIRST because it changes how
		// everything under it reads: a record to consult, not work to do.
		const stack = fs.readFileSync(
			path.join(ROOT, 'src', 'components', 'case', 'CaseBannerStack.vue'),
			'utf8',
		)
		const archived = stack.indexOf('<CaseArchivedStrip')
		expect(archived, 'the archived strip is missing from the stack').toBeGreaterThan(-1)
		expect(archived).toBeLessThan(stack.indexOf('<CaseFavouriteStrip'))
		// `objectData` and not `object`, for the reason the strip itself gives.
		expect(stack).toContain('<CaseArchivedStrip :objectData="objectData" />')

		const banners = caseDetail().config.layout.find(
			(entry) => entry.widgetId === 'case-banner-stack',
		)
		const panels = caseDetail().config.layout.find(
			(entry) => entry.widgetId === 'case-panels',
		)
		expect(banners, 'the banner row is missing from the layout').toBeTruthy()
		expect(banners.gridY).toBeLessThan(panels.gridY)
	})

	it('registers the strip by widget TYPE, which is the key that has to answer', () => {
		// The type stays registered although CaseDetail mounts it through the
		// stack: it is still a valid placement, and `cnRegistry[widget.type]`
		// is what any grid item naming it would resolve.
		expect(registrySource).toContain("'case-archived': {")
		expect(registrySource).toContain('component: CaseArchivedStrip,')
		expect(registrySource).toContain('@custom-widget-ratchet exclude')
		expect(iconsSource).toContain('\n\tArchiveOutline,\n')
	})

	it('hides the seven write actions on an archived case and keeps the lifecycle menu', () => {
		// REQ-CM-43. The marker is read off the case object the page already
		// holds, so the gate costs no round trip, and `eq null` is exact: an
		// absent marker is null and a present one is an object.
		const gated = caseDetail()
			.config.headerActions.filter(
				(action) => action.visibleWhen?.field === '@self.archived',
			)
			.map((action) => action.id)
		expect(gated).toEqual([
			'add-party',
			'link-object',
			'log-contact',
			'generate-document',
			'case-acknowledgement-met',
			'plan-follow-up',
			// A reminder is work somebody is asked to do on the case, so it is
			// gated with the other writes: a task due next week on a case that
			// closed last month is a notification nobody can act on.
			'case-remind',
		])
		for (const id of gated) {
			expect(headerAction(id).visibleWhen).toEqual({
				field: '@self.archived',
				op: 'eq',
				value: null,
			})
		}
		// Restore lives inside the Lifecycle menu, so gating that entry would
		// hide the one act that undoes the archive.
		expect(headerAction('case-lifecycle-menu').visibleWhen).toBeUndefined()
	})
})

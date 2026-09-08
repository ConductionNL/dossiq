/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What survives the round trip between a stored status type and its form.
 *
 * The Statuses tab used to edit four properties, two of which (notifyInitiator,
 * notificationText) no schema declares and no code reads, while `role`,
 * `colour`, `hiddenInLists` and `checklist` all reach the running product and
 * could only be set by hand-editing register JSON. Opening one of those older
 * rows is therefore the case that matters: it carries properties the schema
 * does not have and lacks properties it does, and it has to open without the
 * form inventing a colour the author never chose or carrying the dead
 * properties back into the store.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { describe, expect, it } from 'vitest'
import { STATUS_COLOURS } from '../../src/utils/statusColour.js'
import {
	checklistItem,
	emptyStatusTypeForm,
	formToStatusType,
	isStatusRole,
	pruneChecklist,
	statusTypeToForm,
	STATUS_ROLES,
} from '../../src/utils/statusTypeForm.js'

describe('the roles', () => {
	it('are the six the schema enumerates', () => {
		expect(STATUS_ROLES).toEqual([
			'intake',
			'pending-info',
			'in-progress',
			'review',
			'closed',
			'stranded',
		])
	})

	it.each([
		['an empty string', ''],
		['undefined', undefined],
		['null', null],
		['a name outside the list', 'triage'],
		['a number', 2],
	])('rejects %s', (_label, value) => {
		expect(isStatusRole(value)).toBe(false)
	})
})

describe('emptyStatusTypeForm', () => {
	it('carries every editable property, so no control is missing on add', () => {
		expect(Object.keys(emptyStatusTypeForm(3)).sort()).toEqual([
			'checklist',
			'colour',
			'description',
			'hiddenInLists',
			'isFinal',
			'name',
			'order',
			'role',
		])
	})

	it('opens on the order it is given', () => {
		expect(emptyStatusTypeForm(4).order).toBe(4)
	})
})

describe('pruneChecklist', () => {
	it('drops an item with no title, because that would be a task with no title', () => {
		expect(pruneChecklist([checklistItem(''), checklistItem('Check id')])).toEqual([
			{ title: 'Check id', required: false },
		])
	})

	it('drops an item that is only whitespace', () => {
		expect(pruneChecklist([{ title: '   ' }])).toEqual([])
	})

	it('trims the title it keeps', () => {
		expect(pruneChecklist([{ title: '  Check id  ' }])[0].title).toBe('Check id')
	})

	it('coerces required to a real boolean', () => {
		expect(pruneChecklist([{ title: 'a', required: 'true' }])[0].required).toBe(
			false,
		)
		expect(pruneChecklist([{ title: 'a', required: true }])[0].required).toBe(true)
	})

	it.each([
		['undefined', undefined],
		['null', null],
		['a string', 'Check id'],
		['an object', { title: 'Check id' }],
	])('answers an empty list for %s', (_label, value) => {
		expect(pruneChecklist(value)).toEqual([])
	})
})

describe('statusTypeToForm', () => {
	it('opens a row saved before these properties existed without inventing values', () => {
		const form = statusTypeToForm({
			id: 'st-1',
			caseType: 'ct-1',
			name: 'Ontvangen',
			order: 1,
		})

		expect(form.colour).toBe('')
		expect(form.role).toBe('')
		expect(form.hiddenInLists).toBe(false)
		expect(form.checklist).toEqual([])
		expect(form.description).toBe('')
	})

	it('does not guess grey for a status whose colour was never chosen', () => {
		// A form that opened on grey would save grey, turning "unset" into a
		// deliberate choice the author never made. Rendering falls back to grey;
		// authoring must not.
		expect(statusTypeToForm({ name: 'a' }).colour).toBe('')
	})

	it('drops a colour that is not in the palette', () => {
		expect(statusTypeToForm({ colour: 'teal' }).colour).toBe('')
	})

	it('keeps a colour that is', () => {
		for (const colour of STATUS_COLOURS) {
			expect(statusTypeToForm({ colour }).colour).toBe(colour)
		}
	})

	it('drops a role that is not one the schema enumerates', () => {
		expect(statusTypeToForm({ role: 'triage' }).role).toBe('')
	})

	it('reads a boolean stored as the string true', () => {
		const form = statusTypeToForm({ isFinal: 'true', hiddenInLists: 'true' })
		expect(form.isFinal).toBe(true)
		expect(form.hiddenInLists).toBe(true)
	})

	it('survives being handed nothing at all', () => {
		expect(statusTypeToForm(null).name).toBe('')
	})
})

describe('formToStatusType', () => {
	it('writes back only the properties the schema declares', () => {
		const payload = formToStatusType({
			...emptyStatusTypeForm(1),
			name: 'Ontvangen',
			notifyInitiator: true,
			notificationText: 'Uw aanvraag is ontvangen',
		})

		expect(payload).not.toHaveProperty('notifyInitiator')
		expect(payload).not.toHaveProperty('notificationText')
	})

	it('refuses to save a colour outside the palette', () => {
		// The form cannot produce one, but a caller can, and a colour the
		// schema does not enumerate renders as no colour at all rather than as
		// an error.
		expect(
			formToStatusType({ ...emptyStatusTypeForm(1), colour: 'teal' }).colour,
		).toBe('')
	})

	it('refuses to save a role outside the list', () => {
		expect(
			formToStatusType({ ...emptyStatusTypeForm(1), role: 'triage' }).role,
		).toBe('')
	})

	it('keeps the id and case type when the form has them', () => {
		const payload = formToStatusType({
			...emptyStatusTypeForm(1),
			id: 'st-1',
			caseType: 'ct-1',
			name: 'Ontvangen',
		})

		expect(payload.id).toBe('st-1')
		expect(payload.caseType).toBe('ct-1')
	})

	it('omits the id on a form that has none, so the save creates rather than updates', () => {
		expect(formToStatusType(emptyStatusTypeForm(1))).not.toHaveProperty('id')
	})

	it('round-trips a fully configured status unchanged', () => {
		const stored = {
			id: 'st-1',
			caseType: 'ct-1',
			name: 'In behandeling',
			description: 'De zaak wordt behandeld',
			order: 2,
			isFinal: false,
			role: 'in-progress',
			colour: 'orange',
			hiddenInLists: false,
			checklist: [{ title: 'Check id', required: true }],
		}

		expect(formToStatusType(statusTypeToForm(stored))).toEqual(stored)
	})

	it('carries the reorder path through the same mapping', () => {
		// Dragging a status writes the WHOLE row back. Before this mapping the
		// reorder wrote the row it held, which on an older row meant writing
		// back the dead properties it arrived with.
		const reordered = { ...{ name: 'a', colour: 'blue', order: 1 }, order: 3 }
		expect(formToStatusType(statusTypeToForm(reordered))).toMatchObject({
			order: 3,
			colour: 'blue',
		})
	})
})

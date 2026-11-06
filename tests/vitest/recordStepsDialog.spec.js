/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The record written through steps (DqHandhaving "Handhaving starten"), and
 * the enforcement action and card that configure it on the case page.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	defaultValues,
	firstMissingField,
	followUpTask,
	prefillValues,
	recordFromValues,
	stepFields,
} from '../../src/utils/recordSteps.js'

const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const casePage = layout.pages.find((page) => page.id === 'CaseDetail')
const action = casePage.configAppend.headerActions.find((entry) => entry.id === 'start-enforcement')
const card = casePage.config.sideColumn.find((entry) => entry.id === 'side-enforcement')

const STEPS = [
	{ id: 'a', fields: [{ key: 'type', label: 'Type', type: 'select', required: true, options: [{ value: 'x' }, { value: 'y' }] }] },
	{ id: 'b', fields: [
		{ key: 'amount', label: 'Amount', type: 'number', required: true, when: { field: 'type', in: ['x'] } },
		{ key: 'date', label: 'Date', when: { field: 'type', in: ['y'] } },
	] },
	{ id: 'c', fields: [{ key: 'days', label: 'Days', type: 'number', transient: true, default: 14 }] },
]

describe('the steps of a record', () => {
	it('shows a field only for the values it applies to', () => {
		expect(stepFields(STEPS[1], { type: 'x' }).map((field) => field.key)).toEqual(['amount'])
		expect(stepFields(STEPS[1], {}).map((field) => field.key)).toEqual([])
	})

	it('finds a required field left empty, and passes a field that does not apply', () => {
		expect(firstMissingField(STEPS[1], { type: 'x' }).key).toBe('amount')
		expect(firstMissingField(STEPS[1], { type: 'y' })).toBeNull()
		expect(firstMissingField(STEPS[1], { type: 'x', amount: 0 })).toBeNull()
	})

	it('starts from the source record, without a value the select does not offer', () => {
		expect(prefillValues({ final: 'y' }, { final: 'type' }, STEPS)).toEqual({ type: 'y' })
		expect(prefillValues({ final: 'z' }, { final: 'type' }, STEPS)).toEqual({})
		expect(prefillValues(undefined, { final: 'type' }, STEPS)).toEqual({})
	})

	it('takes the defaults from the fields', () => {
		expect(defaultValues(STEPS)).toEqual({ days: 14 })
	})

	it('writes the case and the fields that apply, not a transient one or a stale one', () => {
		expect(recordFromValues({ type: 'x', amount: '500', date: '2026-12-01', days: 14 }, STEPS, 'c-1'))
			.toEqual({ case: 'c-1', type: 'x', amount: 500 })
	})

	it('dates the task at the end of the term, and makes none when the action asks for none', () => {
		const now = Date.UTC(2026, 10, 6)
		expect(followUpTask({ title: 'Assess', dueInDaysField: 'days' }, { days: 14 }, 'c-1', now))
			.toEqual({ case: 'c-1', title: 'Assess', dueDate: '2026-11-20T23:59:59+00:00' })
		expect(followUpTask(null, {}, 'c-1', now)).toBeNull()
	})
})

describe('enforcement on the case page', () => {
	it('opens the generic dialog from config, only on supervision and enforcement cases', () => {
		expect(action.target).toBe('RecordStepsDialog')
		expect(action.visibleWhen.all).toContainEqual({ field: '@context.caseType.category', op: 'in', value: ['Toezicht en handhaving'] })
		expect(action.props.steps.map((step) => step.id)).toEqual(['classification', 'details', 'announcement'])
		expect(casePage.configOrder.headerActions).toContain('start-enforcement')
	})

	it('asks the fields of the intervention drawn on the board', () => {
		const details = action.props.steps[1]
		expect(stepFields(details, { type: 'last_under_penaltypayment' }).map((field) => field.key))
			.toEqual(['penaltyPaymentAmount', 'penaltyPaymentMaximum', 'compliancePeriod'])
		expect(stepFields(details, { type: 'bestuursdwang' }).map((field) => field.key))
			.toEqual(['effectuationDate', 'estimatedCost'])
	})

	it('runs only the lifecycle transitions a state allows', () => {
		const [forfeit, execute, withdraw] = card.content.rowActions
		expect(forfeit.endpoint).toContain('action=forfeit')
		expect(forfeit.visibleWhen).toEqual({ field: 'status', value: 'opgelegd' })
		expect(execute.visibleWhen).toEqual({ field: 'status', value: 'verbeurd' })
		expect(withdraw.visibleWhen.value).toEqual(['opgelegd', 'verbeurd'])
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The money on the case, as the manifest and the schema declare it.
 *
 * Three of these assertions exist because the failure they catch is silent.
 *
 * A LEAF PLACEMENT IS DARK WHEN NOTHING PLACES IT. A widget declared and never
 * laid out resolves to nothing and leaves a hole in the grid, with no error
 * anywhere. Both money surfaces are shillinq leaves, so the assertion is that
 * each one is declared AND has a cell.
 *
 * A QUERY AGAINST ANOTHER APP'S REGISTER LOOKS LIKE A WORKING WIDGET AND IS
 * NOT. On an install without shillinq that endpoint answers 404 and the panel
 * renders empty, which is exactly what a case with no fee renders. A leaf
 * whose app is absent is never registered, so the surface goes missing rather
 * than lying. This is the same rule `hoursLeafManifest.spec.js` holds for
 * humaniq, and it is asserted here for the same reason.
 *
 * THE PROJECTION MUST CARRY NO MONEY AND MUST NOT BE EDITABLE. An amount on
 * the case would be a second set of books; a writable state would be a
 * financial fact any handler could guess at. Both are schema declarations, so
 * both are asserted against the schema rather than remembered.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
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

const page = (id) => manifest.pages.find((p) => p.id === id)
const caseDetail = page('CaseDetail')
const widgets = caseDetail.config?.widgets ?? caseDetail.widgets ?? []
const layout = caseDetail.config?.layout ?? []
const widgetById = (id) => widgets.find((w) => w.id === id)
const caseProps = register.components.schemas.case.properties
const caseTypeProps = register.components.schemas.caseType.properties

describe("the money is shillinq's, and the case page places its leaves", () => {
	it.each([
		['case-payment-requests', 'shillinq-payment-requests'],
		['case-contract', 'shillinq-contracts'],
	])('%s is a placement of the %s leaf', (widgetId, integrationId) => {
		const widget = widgetById(widgetId)
		expect(widget, `${widgetId} is not declared`).toBeTruthy()
		expect(widget.type).toBe('integration')
		expect(widget.integrationId).toBe(integrationId)
		// No register, schema or filter: a leaf placement reads nothing of
		// another app's register itself. Those keys are what a cross-app
		// QUERY carries, and a query is what this must not be (ADR-113).
		expect(widget.register).toBeUndefined()
		expect(widget.schema).toBeUndefined()
	})

	it.each(['case-payment-requests', 'case-contract'])(
		'%s has a cell on the page',
		(widgetId) => {
			expect(
				layout.some((cell) => cell.widgetId === widgetId),
				`${widgetId} is declared but placed nowhere`,
			).toBe(true)
		},
	)

	it('gates neither on requiredApp, so an absent shillinq hides the surface rather than emptying it', () => {
		for (const widgetId of ['case-payment-requests', 'case-contract']) {
			expect(widgetById(widgetId).requiredApp).toBeUndefined()
		}
	})
})

describe('the case carries a word about the money and nothing more', () => {
	it('declares the five states, with stale among them', () => {
		expect(caseProps.paymentState.enum).toEqual([
			'notRequired',
			'outstanding',
			'paid',
			'waived',
			'stale',
		])
	})

	it('holds no amount, no ledger line and no payment date of its own', () => {
		// `lastPaymentDate` is ZGW's `laatsteBetaaldatum` and predates this
		// change; what this change must not do is add a SECOND money field.
		const money = Object.keys(caseProps).filter((key) =>
			/amountReceived|ledger|paidAmount|feeAmount/i.test(key),
		)
		expect(money).toEqual([])
	})

	it('makes the projection read-only, so the state is never a field a handler guesses at', () => {
		expect(caseProps.paymentState.readOnly).toBe(true)
		expect(caseProps.paymentStateCheckedAt.readOnly).toBe(true)
	})

	it('stamps the projection with the moment it was read', () => {
		expect(caseProps.paymentStateCheckedAt.format).toBe('date-time')
	})

	it('references the contract without copying its term, cost or renewal date', () => {
		expect(caseProps.contract.type).toBe('string')
		const copied = Object.keys(caseProps).filter((key) =>
			/contract(Term|Cost|Value|End|Renewal|Counterparty)/i.test(key),
		)
		expect(copied).toEqual([])
	})
})

describe('the case type decides whether an unpaid case waits', () => {
	it('declares the rule, defaulting to no', () => {
		expect(caseTypeProps.paymentRequiredBeforeHandling.type).toBe('boolean')
		// Absent means no. A rule that defaulted the other way would stop
		// every melding on every install the hour it shipped.
		expect(caseTypeProps.paymentRequiredBeforeHandling.default).toBe(false)
	})
})

describe('the case list can be filtered on the money', () => {
	const cases = page('Cases')

	it('carries a payment column reading the projection', () => {
		const column = cases.config.columns.find((c) => c.key === 'paymentState')
		expect(column, 'the Cases list has no payment column').toBeTruthy()
	})

	it('offers an Awaiting payment lens that narrows on outstanding alone', () => {
		const chip = cases.config.quickFilters.find(
			(q) => q.label === 'Awaiting payment',
		)
		expect(chip).toBeTruthy()
		// Deliberately NOT stale: a case whose state could not be read does
		// not owe money, and folding the two would put the whole instance in
		// this chip the hour shillinq restarts.
		expect(chip.filter.paymentState).toBe('outstanding')
	})

	it("leaves the lens off by default, so nobody's first paint is narrowed to the money", () => {
		const chip = cases.config.quickFilters.find(
			(q) => q.label === 'Awaiting payment',
		)
		expect(chip.default).toBeUndefined()
	})
})

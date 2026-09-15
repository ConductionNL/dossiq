// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The create form asks for what the case type declared, and nothing else.
 *
 * Two failure modes are guarded here, and they point in opposite directions.
 *
 *  - ASKING TOO MUCH. A case type that declared nothing must keep the one-click
 *    start it has always had. The widget reads the declaration first, and an
 *    undeclared case type has to go straight to the save with no dialog, or
 *    every existing instance gets a modal in front of a button that used to
 *    open a case;
 *  - ASKING TOO LITTLE. A case type that made its classification the access
 *    rule must be asked for one even when the classification is absent from the
 *    before-creation list, because the server adds it for the same reason and
 *    a form that did not ask would be refused after the click.
 *
 * The refusal sentence is asserted on the ADR-050 envelope rather than on the
 * axios message: `message` is the prose and `error` is the rule slug, and a
 * surface that showed the slug would put kebab-case in front of a handler.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	asksAnything,
	fieldsToAsk,
	missingFrom,
	normaliseDeclaration,
	optionsFor,
	refusalRule,
	refusalSentence,
} from '../../src/utils/intakeRequirements.js'

/** The declaration a new case type is created with. */
const DEFAULT_DECLARATION = {
	caseType: 'ct-1',
	intakeRequirements: {
		requiredBeforeCreation: ['communicationChannel', 'confidentiality'],
		requiredBeforeComplete: [],
	},
	classification: { scheme: '', classificationIsAccessRule: false, facets: [] },
	classificationValues: [],
	schemeResolves: true,
	assigneeNarrowing: { allowedGroups: [], allowedUsers: [] },
	narrowsNothing: true,
	refusalDestination: { department: '', role: '' },
	canRefuse: false,
}

describe('what the create form asks for', () => {
	it('asks for the two fields a case type with the default declaration wants', () => {
		expect(fieldsToAsk(DEFAULT_DECLARATION)).toEqual([
			'communicationChannel',
			'confidentiality',
		])
		expect(asksAnything(DEFAULT_DECLARATION)).toBe(true)
	})

	it('asks for nothing when the case type declared nothing', () => {
		// 🔴 THE BLAST-RADIUS GUARD. An undeclared case type is every case type
		// on every existing instance the day this ships, and the widget must
		// still open a case on one click.
		const undeclared = { caseType: 'ct-1' }

		expect(fieldsToAsk(undeclared)).toEqual([])
		expect(asksAnything(undeclared)).toBe(false)
		expect(missingFrom({ title: 'Melding' }, undeclared)).toEqual([])
	})

	it('asks for the classification when it is the access rule, even off the list', () => {
		const accessRule = {
			intakeRequirements: { requiredBeforeCreation: [] },
			classification: { classificationIsAccessRule: true, facets: [] },
		}

		expect(fieldsToAsk(accessRule)).toEqual(['classification'])
	})

	it('does not ask twice for a classification that is also on the list', () => {
		const both = {
			intakeRequirements: { requiredBeforeCreation: ['classification'] },
			classification: { classificationIsAccessRule: true, facets: [] },
		}

		expect(fieldsToAsk(both)).toEqual(['classification'])
	})

	it('never asks for a field required only before the case is complete', () => {
		const laterOnly = {
			intakeRequirements: {
				requiredBeforeCreation: [],
				requiredBeforeComplete: ['requesterAddress'],
			},
		}

		expect(fieldsToAsk(laterOnly)).toEqual([])
		expect(normaliseDeclaration(laterOnly).requiredBeforeComplete).toEqual([
			'requesterAddress',
		])
	})
})

describe('what still blocks the save', () => {
	it('reports each unanswered field, in the declared order', () => {
		expect(missingFrom({}, DEFAULT_DECLARATION)).toEqual([
			'communicationChannel',
			'confidentiality',
		])
	})

	it('reads a blank string as unanswered and a false as an answer', () => {
		const declaration = {
			intakeRequirements: { requiredBeforeCreation: ['confidentiality', 'urgent'] },
		}

		expect(
			missingFrom({ confidentiality: '   ', urgent: false }, declaration),
		).toEqual(['confidentiality'])
	})

	it('reports nothing once every declared field carries a value', () => {
		const answered = {
			communicationChannel: 'https://example.gemeente.nl/portaal',
			confidentiality: 'openbaar',
		}

		expect(missingFrom(answered, DEFAULT_DECLARATION)).toEqual([])
	})
})

describe('the values a field offers', () => {
	it('offers the eight confidentiality levels the case schema carries', () => {
		const values = optionsFor('confidentiality', DEFAULT_DECLARATION)

		expect(values).toContain('zaakvertrouwelijk')
		expect(values).toHaveLength(8)
	})

	it('offers the classification values the scheme resolved to', () => {
		const declaration = {
			classification: { scheme: 'tmlo-2019', classificationIsAccessRule: true },
			classificationValues: ['openbaar', 'beperkt'],
		}

		expect(optionsFor('classification', declaration)).toEqual([
			'openbaar',
			'beperkt',
		])
	})

	it('offers no values for a field with no vocabulary, so it renders free text', () => {
		expect(optionsFor('communicationChannel', DEFAULT_DECLARATION)).toEqual([])
	})
})

describe('an unresolvable scheme', () => {
	it('is reported on the declaration rather than being read as resolved', () => {
		const unresolved = normaliseDeclaration({
			classification: { scheme: 'tmlo-2019', classificationIsAccessRule: true },
			schemeResolves: false,
		})

		expect(unresolved.schemeResolves).toBe(false)
		expect(unresolved.classificationIsAccessRule).toBe(true)
	})

	it('is not what an answer that simply omitted the key means', () => {
		// The absent key has to read as "resolved", or every declaration from a
		// server that has not been upgraded would look broken.
		expect(normaliseDeclaration({}).schemeResolves).toBe(true)
	})
})

describe('the refusal a save comes back with', () => {
	const refusal = {
		response: {
			data: {
				message:
					'This case type asks for communicationChannel before the case exists, '
					+ 'and the case does not carry it yet.',
				error: 'intake-requirement-not-answered',
				code: 'intake_requirement_not_answered',
			},
		},
	}

	it('reads the sentence out of the ADR-050 envelope', () => {
		expect(refusalSentence(refusal)).toContain('communicationChannel')
	})

	it('reads the rule slug separately, so a surface never shows it as prose', () => {
		expect(refusalRule(refusal)).toBe('intake-requirement-not-answered')
		expect(refusalSentence(refusal)).not.toBe(refusalRule(refusal))
	})

	it('answers an empty sentence for an error that carries no envelope', () => {
		expect(refusalSentence(new Error('Network Error'))).toBe('')
		expect(refusalSentence(undefined)).toBe('')
	})
})

describe('a declaration the client could not read', () => {
	it('is marked, so a caller can say so instead of pretending nothing is asked', () => {
		const unreadable = normaliseDeclaration({ unreadable: true })

		expect(unreadable.unreadable).toBe(true)
		expect(unreadable.requiredBeforeCreation).toEqual([])
	})

	it('is not what a declaration that was read cleanly looks like', () => {
		expect(normaliseDeclaration(DEFAULT_DECLARATION).unreadable).toBe(false)
	})
})

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `caseAnswerText` (src/services/caseAnswer.js):
 * every shape of options, answers and value it reads.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */

import { describe, expect, it } from 'vitest'
import { caseAnswerText } from '../../src/services/caseAnswer.js'

const ANSWERS = [
	null,
	{ name: 'plain', value: 'Tekst' },
	{ name: 'words', value: '["a","b",3,true]' },
	{ name: 'records', value: '[{"a":1}]' },
	{ name: 'nulls', value: '["a",null]' },
	{ name: 'broken', value: '[not json' },
	{ name: 'object', value: '{"a":1}' },
	{ name: 'number', value: 7 },
	{ name: 'empty', value: '[]' },
]

describe('case answer text golden', () => {
	it.each([
		['plain', 'Tekst'],
		['words', 'a, b, 3, true'],
		['records', ''],
		['nulls', ''],
		['broken', '[not json'],
		['object', '{"a":1}'],
		['number', ''],
		['empty', ''],
		['missing', ''],
	])('reads %s as %j', (name, text) => {
		expect(caseAnswerText(ANSWERS, { name })).toBe(text)
	})

	it.each([
		[ANSWERS, undefined],
		[ANSWERS, null],
		[ANSWERS, {}],
		[ANSWERS, { name: 3 }],
		[ANSWERS, { name: '' }],
		[null, { name: 'plain' }],
		['x', { name: 'plain' }],
	])('reads nothing for answers %j and options %j', (answers, options) => {
		expect(caseAnswerText(answers, options)).toBe('')
	})

	it('reads the first answer under the name', () => {
		expect(caseAnswerText([{ name: 'a', value: 'one' }, { name: 'a', value: 'two' }], { name: 'a' })).toBe('one')
	})
})

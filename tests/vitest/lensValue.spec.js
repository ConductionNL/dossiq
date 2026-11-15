/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reading a lens value, and the shapes that are not one.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	CASE_OBJECT_LENSES,
	isWithheld,
	withheldReason,
	withoutLenses,
} from '../../src/utils/lensValue.js'

describe('isWithheld', () => {
	it('recognises the marker OpenRegister sends', () => {
		expect(isWithheld({ '@withheld': true, reason: 'access' })).toBe(true)
	})

	it('is false for a value that merely exists', () => {
		// The marker is truthy and stringifies to `[object Object]`, so the two
		// defaults a surface reaches for, a truthiness check and interpolation,
		// both get it wrong without complaining.
		expect(isWithheld('Pand 1234')).toBe(false)
		expect(isWithheld(0)).toBe(false)
		expect(isWithheld(false)).toBe(false)
	})

	it('is false for nothing at all', () => {
		expect(isWithheld(null)).toBe(false)
		expect(isWithheld(undefined)).toBe(false)
		expect(isWithheld('')).toBe(false)
	})

	it('is false for an object that is not the marker', () => {
		expect(isWithheld({ name: 'Pand 1234' })).toBe(false)
		expect(isWithheld({ '@withheld': false })).toBe(false)
		expect(isWithheld({ '@withheld': 'true' })).toBe(false)
	})

	it('is false for an array, which is also an object', () => {
		expect(isWithheld([])).toBe(false)
		expect(isWithheld(['@withheld'])).toBe(false)
	})
})

describe('withheldReason', () => {
	it('gives the reason the marker carries', () => {
		expect(withheldReason({ '@withheld': true, reason: 'access' })).toBe(
			'access',
		)
	})

	it('gives nothing for a value that is not withheld', () => {
		expect(withheldReason('Pand 1234')).toBe('')
		expect(withheldReason(null)).toBe('')
	})

	it('gives nothing for a marker with no reason on it', () => {
		expect(withheldReason({ '@withheld': true })).toBe('')
	})
})

describe('withoutLenses', () => {
	it('drops the lens properties before a write', () => {
		// A lens is stored nowhere, so OpenRegister refuses a write naming one
		// with a 400 that names the property. A client that reads a record and
		// puts it back has to drop them first.
		const read = {
			objectType: 'pand',
			objectTitle: 'Pand 1234',
			objectStatus: 'in gebruik',
		}
		expect(withoutLenses(read, CASE_OBJECT_LENSES)).toEqual({
			objectType: 'pand',
		})
	})

	it('leaves the record it was given alone', () => {
		const read = { objectType: 'pand', objectTitle: 'Pand 1234' }
		withoutLenses(read, CASE_OBJECT_LENSES)
		expect(read.objectTitle).toBe('Pand 1234')
	})

	it('drops a withheld lens too, which is still not a value', () => {
		const read = { objectType: 'pand', objectTitle: { '@withheld': true } }
		expect(withoutLenses(read, CASE_OBJECT_LENSES)).toEqual({
			objectType: 'pand',
		})
	})

	it('copes with nothing to drop and nothing to drop it from', () => {
		expect(withoutLenses({ a: 1 }, [])).toEqual({ a: 1 })
		expect(withoutLenses(null, CASE_OBJECT_LENSES)).toEqual({})
		expect(withoutLenses({ a: 1 }, null)).toEqual({ a: 1 })
	})
})

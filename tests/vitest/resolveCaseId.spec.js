/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
import { describe, expect, it } from 'vitest'
import { resolveCaseId } from '../../src/utils/resolveCaseId.js'

const route = { params: { id: 'from-route', objectId: 'from-object' } }

describe('resolveCaseId', () => {
	it('takes a real prop over the route', () => {
		expect(resolveCaseId('case-1', route)).toBe('case-1')
	})

	it('reads the route when the prop is an unresolved token or empty', () => {
		expect(resolveCaseId('@objectId', route)).toBe('from-route')
		expect(resolveCaseId('', route)).toBe('from-route')
		expect(resolveCaseId(undefined, route)).toBe('from-route')
	})

	it('tries the route params in the order given', () => {
		expect(resolveCaseId('@objectId', { params: { objectId: 'from-object' } }, ['id', 'objectId'])).toBe('from-object')
	})

	it('answers an empty string without a case anywhere', () => {
		expect(resolveCaseId('@objectId', undefined)).toBe('')
		expect(resolveCaseId('', { params: {} })).toBe('')
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app logger: every level writes one prefixed console line, with the
 * context as a second argument only when one is given.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import logger from '../../src/logger.js'

describe('logger', () => {
	afterEach(() => vi.restoreAllMocks())

	for (const level of ['debug', 'info', 'warn', 'error']) {
		it(`writes ${level} lines with the dossiq prefix`, () => {
			const spy = vi.spyOn(console, level).mockImplementation(() => {})

			logger[level]('case saved')
			logger[level]('case refused', { caseId: 'c-1' })

			expect(spy.mock.calls).toEqual([
				['[dossiq] case saved'],
				['[dossiq] case refused', { caseId: 'c-1' }],
			])
		})
	}

	it('passes a falsy context on rather than dropping it', () => {
		const spy = vi.spyOn(console, 'warn').mockImplementation(() => {})

		logger.warn('empty', null)
		logger.warn('zero', 0)

		expect(spy.mock.calls).toEqual([['[dossiq] empty', null], ['[dossiq] zero', 0]])
	})
})

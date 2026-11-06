// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The outcome of a checksum's last check, in words.
 *
 * @spec openspec/specs/document-integrity-check/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ collections: {} }) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({ subCaseCounts: {} }) }))

const { default: formatters } = await import('../../src/services/formatters.js')

describe('the integrityStatus formatter', () => {
	it('names each outcome and leaves an unknown one as it is', () => {
		expect(formatters.integrityStatus('unchanged')).toBe('Unchanged')
		expect(formatters.integrityStatus('changed')).toBe('Changed since it was stored')
		expect(formatters.integrityStatus('missing')).toBe('File not found')
		expect(formatters.integrityStatus('unchecked')).toBe('Too large to check')
		expect(formatters.integrityStatus('')).toBe('')
	})
})

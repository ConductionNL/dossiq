/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `resolveTranslatable` (src/utils/i18nResolver.js):
 * plain values, the locale fallback chain, the first-available last resort,
 * and the user locale read from Nextcloud.
 */

import { afterEach, describe, expect, it } from 'vitest'
import { resolveTranslatable } from '../../src/utils/i18nResolver.js'

describe('resolveTranslatable golden', () => {
	afterEach(() => {
		delete globalThis.OC
	})

	it.each([
		[null, { text: '', lang: null, isFallback: false }],
		[undefined, { text: '', lang: null, isFallback: false }],
		['Plain', { text: 'Plain', lang: null, isFallback: false }],
		[42, { text: '42', lang: null, isFallback: false }],
		[['a', 'b'], { text: 'a,b', lang: null, isFallback: false }],
		[{}, { text: '', lang: null, isFallback: true }],
	])('passes %j through', (value, expected) => {
		expect(resolveTranslatable(value, 'en')).toEqual(expected)
	})

	it('walks the requested, fallback, app default and English locales in order', () => {
		const value = { de: 'Antrag', en: 'Request', nl: 'Aanvraag', fr: 'Demande' }
		expect(resolveTranslatable(value, 'de')).toEqual({ text: 'Antrag', lang: 'de', isFallback: false })
		expect(resolveTranslatable(value, 'es', 'fr')).toEqual({ text: 'Demande', lang: 'fr', isFallback: true })
		expect(resolveTranslatable(value, 'es')).toEqual({ text: 'Aanvraag', lang: 'nl', isFallback: true })
		expect(resolveTranslatable({ en: 'Request', fr: 'Demande' }, 'es')).toEqual({ text: 'Request', lang: 'en', isFallback: true })
	})

	it('skips empty, null and undefined values in the chain', () => {
		expect(resolveTranslatable({ nl: '', en: null, de: 0 }, 'nl')).toEqual({ text: '', lang: 'nl', isFallback: true })
		expect(resolveTranslatable({ nl: '', de: 0 }, 'de')).toEqual({ text: '0', lang: 'de', isFallback: false })
		expect(resolveTranslatable({ nl: undefined, fr: 'Demande' }, 'nl')).toEqual({ text: 'undefined', lang: 'nl', isFallback: true })
	})

	it('falls back to the first available language', () => {
		expect(resolveTranslatable({ fr: 'Demande', de: 'Antrag' }, 'es', 'it')).toEqual({ text: 'Demande', lang: 'fr', isFallback: true })
		expect(resolveTranslatable({ fr: null }, 'es')).toEqual({ text: 'null', lang: 'fr', isFallback: true })
	})

	it('reads the user locale from Nextcloud when none is given', () => {
		globalThis.OC = { getLanguage: () => 'en_GB' }
		expect(resolveTranslatable({ nl: 'Aanvraag', en: 'Request' })).toEqual({ text: 'Request', lang: 'en', isFallback: false })
		globalThis.OC = { getLanguage: () => '' }
		expect(resolveTranslatable({ nl: 'Aanvraag', en: 'Request' })).toEqual({ text: 'Aanvraag', lang: 'nl', isFallback: false })
		delete globalThis.OC
		expect(resolveTranslatable({ nl: 'Aanvraag', en: 'Request' })).toEqual({ text: 'Aanvraag', lang: 'nl', isFallback: false })
	})
})

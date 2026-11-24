// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A transition label is a string or a language map: read in the reader's
 * language, and edited in the reader's language without losing the others.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { labelText, withLabelText } from '../../src/utils/translatableLabel.js'

describe('translatableLabel', () => {
	beforeEach(() => {
		globalThis.OC = { getLanguage: () => 'en_GB' }
	})

	afterEach(() => {
		delete globalThis.OC
	})

	it('reads a language map in the reader\'s language, and a string as it is', () => {
		expect(labelText({ nl: 'In behandeling nemen', en: 'Take on' })).toBe('Take on')
		expect(labelText({ nl: 'In behandeling nemen' })).toBe('In behandeling nemen')
		expect(labelText('Goedkeuren')).toBe('Goedkeuren')
		expect(labelText(null)).toBe('')
	})

	it('writes the reader\'s language into a map and keeps the other languages', () => {
		expect(withLabelText({ nl: 'In behandeling nemen', en: 'Take on' }, 'Start handling')).toEqual({ nl: 'In behandeling nemen', en: 'Start handling' })
		expect(withLabelText({ nl: 'Afwijzen' }, 'Reject', 'en')).toEqual({ nl: 'Afwijzen', en: 'Reject' })
	})

	it('replaces a plain string label with the new text', () => {
		expect(withLabelText('Goedkeuren', 'Approve')).toBe('Approve')
		expect(withLabelText(undefined, 'Approve')).toBe('Approve')
	})
})

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The notification section of the personal settings reads "Meldingen" in
 * Dutch, as board DqPersoonlijkeInstellingen draws it.
 *
 * @spec openspec/specs/personal-settings-surface/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const nl = JSON.parse(
	fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
).translations

describe('the notification section label', () => {
	it('reads Meldingen in Dutch', () => {
		expect(nl.Notifications).toBe('Meldingen')
	})
})

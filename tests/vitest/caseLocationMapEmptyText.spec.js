// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The location map's empty text reads in the user's language, also when the
 * manifest configures it. Rehearsal R2 (15 Oct) showed "No locations linked
 * to this case yet" on a Dutch instance: the configured text skipped t().
 *
 * @spec openspec/changes/case-page-reads-in-dutch/specs/case-management/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnMapWidget: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: {} }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `[${app}] ${text}`,
	translatePlural: (app, one) => `[${app}] ${one}`,
}))

const CaseLocationMap = (
	await import('../../src/components/case/CaseLocationMap.vue')
).default

/**
 * The empty text for a given widget content.
 *
 * @param {object} content The widget content.
 * @return {string} The text.
 */
function emptyTextFor(content) {
	return CaseLocationMap.computed.emptyText.call({ content })
}

describe('the location map empty text', () => {
	it('reads a configured text through the app catalogue', () => {
		expect(
			emptyTextFor({ emptyText: 'No locations linked to this case yet' }),
		).toBe('[dossiq] No locations linked to this case yet')
	})

	it('control: without a configured text it reads its own default', () => {
		expect(emptyTextFor({})).toBe(
			'[dossiq] No locations linked to this case yet',
		)
	})
})

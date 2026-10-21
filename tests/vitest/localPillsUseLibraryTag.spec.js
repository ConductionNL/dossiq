import fs from 'node:fs'
import path from 'node:path'
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * dossiq draws no tag shape of its own: a status, priority or label pill is
 * nextcloud-vue's tag (`cn-status-badge`), so it takes the library's shape in
 * every look (a full pill in the Nextcloud look, the boards' 21px rounded
 * rectangle in the board look). A local rule may still colour a tag. Two
 * pills are not tags and keep their own shape: the timeline's filter chips
 * and the duration picker's preset buttons.
 *
 * @spec openspec/changes/dossiq-pills-library-tag/specs/dossiq-pills-library-tag/spec.md
 */
import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '..', '..', 'src')
const NOT_TAGS = ['views/cases/components/CaseTimelineTab.vue', 'views/settings/components/DurationPicker.vue']

/**
 * Every .vue and .css file under src, relative to it.
 *
 * @param {string} dir Directory under src.
 * @return {string[]} Relative paths.
 */
function files (dir = '') {
  return fs.readdirSync(path.join(SRC, dir), { withFileTypes: true }).flatMap((entry) => {
	const rel = dir ? `${dir}/${entry.name}` : entry.name
	if (entry.isDirectory()) {
		return files(rel)
	}
	return /\.(vue|css)$/.test(entry.name) ? [rel] : []
})
}

describe('dossiq pills are the library tag', () => {
	it('declares no full-pill corner outside the two non-tag pills', () => {
		const offenders = files()
			.filter((rel) => !NOT_TAGS.includes(rel))
			.filter((rel) => /border-radius:\s*(var\(--border-radius-pill|999|9999|100px)/.test(fs.readFileSync(path.join(SRC, rel), 'utf8')))
		expect(offenders).toEqual([])
	})

	it.each([
		'components/cells/StatusBadgeCell.vue',
		'components/cells/PriorityBadgeCell.vue',
	])('%s renders its tag as cn-status-badge', (rel) => {
		expect(fs.readFileSync(path.join(SRC, rel), 'utf8')).toContain('cn-status-badge cn-status-badge--')
	})
})

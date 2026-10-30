// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A chart on a detail page names its kind as `type`. The detail host hands a
 * widget only the content keys it declares as props, and the chart's kind
 * prop is `type`; `chartKind` is the dashboard dispatcher's name for it. A
 * detail-page chart with `chartKind` alone draws an empty area chart, which
 * is how the LHS matrix on the advice page went blank.
 *
 * @spec openspec/specs/enforcement-advice/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

/**
 * Every chart widget definition under a value, with where it sits.
 *
 * @param {unknown} value The value to walk.
 * @param {string} where Where the value sits, for the message.
 * @param {Array<{where: string, chart: object}>} found The charts so far.
 * @return {Array<{where: string, chart: object}>} The charts.
 */
function chartsIn(value, where, found = []) {
	if (Array.isArray(value)) {
		value.forEach((item, index) => chartsIn(item, `${where}[${index}]`, found))
	} else if (value && typeof value === 'object') {
		if (value.type === 'chart' && value.content && typeof value.content === 'object') {
			found.push({ where: `${where} ${value.id || ''}`, chart: value })
		}
		for (const [key, child] of Object.entries(value)) {
			chartsIn(child, `${where}.${key}`, found)
		}
	}
	return found
}

describe('charts on detail pages', () => {
	for (const profile of ['menu-layout.json', 'menu-layout.simple.json']) {
		it(`name their kind as type (${profile})`, () => {
			const manifest = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', profile))
			const detailCharts = manifest.pages
				.filter((page) => page.type === 'detail')
				.flatMap((page) => chartsIn(page.config, page.id))
			expect(detailCharts.length).toBeGreaterThan(0)
			const missing = detailCharts.filter(({ chart }) => !chart.content.type).map(({ where }) => where)
			expect(missing).toEqual([])
		})
	}
})

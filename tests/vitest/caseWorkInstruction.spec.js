// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The work instruction for this KIND of case, on the case.
 *
 * knowledge-base-on-the-case REQ-CKB-01, parity row 11.26. The case type
 * names the page once and every case of that type shows it, with nobody
 * linking anything per case. That is the half of the requirement a
 * declaration could not close: `CnDetailPage` reads no `extend`, so the
 * case's `caseType` is a bare uuid on the page and a widget over a dotted
 * `caseType.knowledgeBasePage` path renders blank while looking configured.
 *
 * 🔴 THE THREE BLANK STATES ARE DRIVEN APART, because a reader cannot tell
 * them apart from the panel and only one of them is nobody's problem:
 *
 *  - a case type that names NO page draws nothing, on purpose;
 *  - a lookup that FAILED says so and offers a retry;
 *  - a page that IS named draws a link, and never the article body.
 *
 * 🔴 A `javascript:` VALUE IS NOT OPENED. `knowledgeBasePage` is free text an
 * administrator types, carries no `format` on purpose, and is rendered as an
 * `href`. The scheme whitelist is asserted here rather than trusted, because
 * a blacklist that missed one would look exactly like this test passing.
 *
 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseWorkInstructionPanel from '../../src/components/case/CaseWorkInstructionPanel.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const ROOT = path.resolve(__dirname, '../..')

/**
 * Mount the panel on a case and let its lookup settle.
 *
 * @param {object} objectData The case object the detail host hands the widget.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPanel(objectData) {
	const wrapper = mount(CaseWorkInstructionPanel, {
		props: { objectData },
		global: {
			stubs: {
				NcButton: {
					name: 'NcButton',
					template: '<button><slot /></button>',
				},
				AlertCircleOutline: true,
				BookOpenPageVariant: true,
			},
		},
	})
	await wrapper.vm.$nextTick()
	await Promise.resolve()
	await wrapper.vm.$nextTick()

	return wrapper
}

/**
 * The link the panel drew, or null.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {object|null} The anchor wrapper.
 */
function link(wrapper) {
	const found = wrapper.find('[data-testid="case-work-instruction-link"]')

	return found.exists() ? found : null
}

describe('the work instruction the case type names', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('draws nothing at all when the case type names no page', async () => {
		axios.get.mockResolvedValue({ data: { title: 'Bezwaar' } })
		const wrapper = await mountPanel({ caseType: 'ct-1' })

		expect(
			link(wrapper),
			'a case type with no instruction drew a link anyway',
		).toBeNull()
		expect(wrapper.text().trim()).toBe('')
	})

	it('links the page the case type names, following the reference once', async () => {
		axios.get.mockResolvedValue({
			data: { knowledgeBasePage: 'https://wiki.example.org/bezwaar' },
		})
		const wrapper = await mountPanel({ caseType: 'ct-1' })

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain('ct-1')
		expect(link(wrapper).attributes('href')).toBe(
			'https://wiki.example.org/bezwaar',
		)
	})

	it('reads the instruction off an inlined case type without a round trip', async () => {
		const wrapper = await mountPanel({
			caseType: {
				id: 'ct-1',
				knowledgeBasePage: 'https://wiki.example.org/inlined',
			},
		})

		expect(
			axios.get,
			'the page was already on the case and was fetched again anyway',
		).not.toHaveBeenCalled()
		expect(link(wrapper).attributes('href')).toBe(
			'https://wiki.example.org/inlined',
		)
	})

	it('reads a bare page path as a Collectives page', async () => {
		axios.get.mockResolvedValue({
			data: { knowledgeBasePage: '/Handleidingen/Bezwaar' },
		})
		const wrapper = await mountPanel({ caseType: 'ct-1' })

		expect(link(wrapper).attributes('href')).toContain(
			'/apps/collectives/Handleidingen/Bezwaar',
		)
		expect(link(wrapper).attributes('href')).not.toContain('//Handleidingen')
	})

	it('refuses a scheme nobody asked for', async () => {
		axios.get.mockResolvedValue({
			// eslint-disable-next-line no-script-url
			data: { knowledgeBasePage: 'javascript:alert(1)' },
		})
		const wrapper = await mountPanel({ caseType: 'ct-1' })

		expect(
			link(wrapper),
			'a javascript: value was turned into an href on a page an administrator does not own',
		).toBeNull()
	})

	it('says a lookup failed rather than reading as a type with no instruction', async () => {
		axios.get.mockRejectedValue(new Error('gateway'))
		const wrapper = await mountPanel({ caseType: 'ct-1' })

		expect(link(wrapper)).toBeNull()
		expect(wrapper.text()).toContain('could not be looked up')
		expect(wrapper.find('button').exists()).toBe(true)
	})

	it('asks nothing of a case that has no type yet', async () => {
		const wrapper = await mountPanel({})

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.text().trim()).toBe('')
	})

	it('never fetches an article body', async () => {
		axios.get.mockResolvedValue({
			data: { knowledgeBasePage: 'https://wiki.example.org/bezwaar' },
		})
		await mountPanel({ caseType: 'ct-1' })

		const urls = axios.get.mock.calls.map((call) => String(call[0]))
		expect(
			urls.some((url) => url.includes('collectives')),
			'the panel read a page out of Collectives, which is a copy dossiq must not hold',
		).toBe(false)
	})
})

describe('the panel is placed where a reader meets it', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
	)

	it('is the first section of the Knowledge tab on the case', () => {
		const detail = (manifest.pages || []).find((p) => p.id === 'CaseDetail')
		const panel = (detail.config.widgets || []).find(
			(w) => w.id === 'case-knowledge-panel',
		)
		const sections = panel.content.sections || []

		expect(sections[0].widget.type).toBe('case-work-instruction')
		expect(sections[0].widget.id).toBe('case-work-instruction')
	})

	it('resolves from the registry, or the section renders nothing and logs nothing', () => {
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)

		expect(registry).toContain("'case-work-instruction': {")
		expect(registry).toContain('CaseWorkInstructionPanel')
	})

	it('is placed exactly once, so a second placement cannot drift from this one', () => {
		// `JSON.stringify` puts no space after a colon, and the manifest file
		// does. Counting the file's spelling here found nothing and read as a
		// pass on the opposite claim, which is why the walk is over the parsed
		// tree and not over either string.
		let placements = 0
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
				return
			}
			if (node === null || typeof node !== 'object') {
				return
			}
			if (node.type === 'case-work-instruction') {
				placements += 1
			}
			Object.values(node).forEach(walk)
		}
		walk(manifest)

		expect(placements).toBe(1)
	})
})

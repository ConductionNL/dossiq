// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Assistant tab on the case (board DqZaakAssistent).
 *
 * The AI features (where each runs, a run on a case document, a refusal in
 * words naming hermiq's gate, the prompts, the oversight decisions) are the
 * library built-in `ai-features`, pinned by its own specs. What is pinned
 * here is the dossiq half: the tab points the widget at the case type's
 * declaration and dossiq's endpoints, and the report group renders its count
 * with the near-duplicates beside it.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseReportGroupWidget from '../../src/components/case/CaseReportGroupWidget.vue'
import { fetchReportGroup } from '../../src/services/caseAiFeaturesApi.js'

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const fill = (text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match)
	return {
		...(await importOriginal()),
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) =>
			fill(count === 1 ? one : many, vars),
	}
})
vi.mock('../../src/services/caseAiFeaturesApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	fetchReportGroup: vi.fn(),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

/**
 * The Assistant tab on the case page.
 *
 * @return {object} The tab.
 */
function assistantTab() {
	const page = manifest.pages.find((entry) => entry.id === 'CaseDetail')
	return page.config.sidebar.tabs.find((tab) => tab.id === 'assistant')
}

/**
 * Mount the report group widget.
 *
 * @return {object} The wrapper.
 */
function mountGroup() {
	return mount(CaseReportGroupWidget, {
		props: { objectId: 'c-1' },
		global: {
			stubs: {
				RouterLink: {
					props: ['to'],
					template: '<a class="case-link"><slot /></a>',
				},
			},
		},
	})
}

describe('the Assistant tab', () => {
	it('renders the library ai-features widget, then the report group', () => {
		const tab = assistantTab()
		expect(tab.component).toBeUndefined()
		expect(tab.widgets.map((widget) => widget.type)).toEqual([
			'ai-features',
			'CaseReportGroupWidget',
		])
		expect(registrySource).toContain('CaseReportGroupWidget: {')
		expect(registrySource).not.toContain('CaseAiFeaturesPanel')
	})

	it("reads the case type's declaration and asks dossiq's endpoints", () => {
		expect(assistantTab().widgets[0].props).toEqual({
			declaration: {
				typeField: 'caseType',
				typeSchema: 'caseType',
				field: 'aiFeatures',
				surface: 'case',
			},
			featuresEndpoint: '/apps/dossiq/api/assistant/ai-features',
			featuresQueryKey: 'caseType[aiFeatures]',
			promptsEndpoint: '/apps/dossiq/api/assistant/prompts',
			askEndpoint: '/apps/dossiq/api/assistant/converse',
			decisionEndpoint: '/apps/dossiq/api/ai/record-action',
			objectKey: 'caseId',
		})
	})
})

describe('CaseReportGroupWidget', () => {
	beforeEach(() => {
		vi.mocked(fetchReportGroup).mockReset()
	})

	it('shows the group count with the near-duplicates beside it', async () => {
		vi.mocked(fetchReportGroup).mockResolvedValue({
			declared: true,
			available: true,
			group: {
				groupId: 'g-7',
				count: 200,
				nearDuplicates: [{ reportId: 'case-9', score: 0.61 }],
			},
		})
		const wrapper = mountGroup()
		await flushPromises()

		expect(wrapper.find('[data-testid="case-ai-group-count"]').text()).toBe(
			'200 reports in this group',
		)
		const near = wrapper.findAll('[data-testid="case-ai-group-near-item"]')
		expect(near).toHaveLength(1)
		expect(near[0].text()).toContain('case-9')
		expect(near[0].text()).toContain('61% alike')
	})

	it('renders no group section for a case type that does not group reports', async () => {
		vi.mocked(fetchReportGroup).mockResolvedValue({
			declared: false,
			available: false,
			group: null,
		})
		const wrapper = mountGroup()
		await flushPromises()

		expect(wrapper.find('[data-testid="case-ai-group"]').exists()).toBe(false)
	})

	it('says the group could not be read, with the gate, rather than hiding it', async () => {
		vi.mocked(fetchReportGroup).mockResolvedValue({
			declared: true,
			available: false,
			group: null,
			reason: 'Refused.',
			gate: 'consent',
		})
		const wrapper = mountGroup()
		await flushPromises()

		const note = wrapper.find('[data-testid="case-ai-group-unavailable"]')
		expect(note.text()).toContain('Refused.')
		expect(note.text()).toContain('Gate: consent')
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type page edits a label in a second language, and says what is
 * still missing.
 *
 * IT MOCKS AXIOS AND NOT THE CLIENT, so the client's own reading is under
 * test with the widget. Mocking `caseTypeTranslationApi.js` would have let a
 * test pass against a shape the real client never produces, which is the
 * failure this file is here to prevent one level up.
 *
 * TWO THINGS ARE PINNED THAT NOTHING ELSE WOULD CATCH.
 *
 * The completeness count is dossiq's own, not OpenRegister's.
 * `TranslationMapper::getCompletenessByObject()` counts every row with a
 * non-empty value and never reads its status, so a translation the Dutch
 * source moved out from under still counts as done. REQ-CFI-04 says a stale
 * label is a wrong label. Without dossiq's rule the chip reads full on a case
 * type whose English labels are all out of date, and that is the number a
 * reader acts on.
 *
 * The write carries the WHOLE language map. With
 * `X-Translation-Target-Language` the engine builds a fresh single-key map
 * (`[$targetLanguage => $value]`), so whether the Dutch title survives would
 * depend on merge behaviour the browser cannot see. The assertion below reads
 * the PATCH body and fails if Dutch is not in it.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import CaseTypeTranslationsWidget from '../../src/components/caseType/CaseTypeTranslationsWidget.vue'
import { completenessOf } from '../../src/services/caseTypeTranslationApi.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/**
 * A click-forwarding stand-in for a Nextcloud component.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub component.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		props: ['disabled', 'label', 'value', 'type', 'size'],
		emits: ['click', 'update:value'],
		render() {
			return h(
				tag,
				{ onClick: () => this.$emit('click'), disabled: this.disabled },
				this.$slots.default?.(),
			)
		},
	})
}

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: stub('NcButton', 'button'),
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: stub('NcLoadingIcon'),
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: stub('NcTextField', 'input'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn(), unsubscribe: vi.fn() }))

/** The case type as OpenRegister serves it. Replaced per test. */
let caseType = {}

/** The sidecar rows. Replaced per test. */
let rows = []

/**
 * Mount the widget on a case type and let it read.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWidget() {
	const wrapper = mount(CaseTypeTranslationsWidget, {
		global: { mocks: { $route: { params: { id: 'ct-1' } } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	rows = []
	caseType = {
		'@self': { uuid: 'ct-1' },
		title: { nl: 'Omgevingsvergunning' },
		description: { nl: 'Een vergunning voor bouwen' },
		_meta: {
			languageMeta: {
				title: {
					sourceLanguage: 'nl',
					served: 'nl',
					isSource: true,
					status: 'source',
				},
				description: {
					sourceLanguage: 'nl',
					served: 'nl',
					isSource: true,
					status: 'source',
				},
			},
		},
	}

	axios.get.mockImplementation((url) => {
		const path = String(url)
		if (path.endsWith('/registers/dossiq')) {
			return Promise.resolve({ data: { languages: ['nl', 'en'] } })
		}
		if (path.endsWith('/registers/dossiq/schemas')) {
			return Promise.resolve({
				data: {
					results: [
						{
							slug: 'caseType',
							properties: {
								title: { title: 'Title' },
								description: { title: 'Description' },
							},
						},
					],
				},
			})
		}
		if (path.includes('/translations/object/')) {
			return Promise.resolve({ data: { translations: rows } })
		}
		return Promise.resolve({ data: { object: caseType } })
	})
	axios.patch.mockResolvedValue({ data: { object: caseType } })
})

describe('a case type says what is still missing', () => {
	it('draws one chip per declared language, not per language present', async () => {
		// English has no value at all here. Derived from the data the chip
		// would not exist, and a language nobody has started is exactly the
		// one the reader needs to see.
		const wrapper = await mountWidget()

		const chips = wrapper.findAll('.case-type-translations__chip')
		expect(chips).toHaveLength(2)
		expect(chips[1].attributes('data-language')).toBe('en')
		expect(chips[1].text()).toContain('0 of 2')
	})

	it('counts a stale translation as missing', () => {
		// The rule OpenRegister's own completeness does not apply.
		const values = {
			title: { nl: 'A', en: 'A!' },
			description: { nl: 'B', en: 'B!' },
		}

		expect(
			completenessOf({
				properties: ['title', 'description'],
				values,
				statuses: {
					title: { en: 'human_reviewed' },
					description: { en: 'human_reviewed' },
				},
				language: 'en',
				sourceLanguage: 'nl',
			}),
		).toEqual({ translated: 2, total: 2 })

		expect(
			completenessOf({
				properties: ['title', 'description'],
				values,
				statuses: {
					title: { en: 'outdated' },
					description: { en: 'human_reviewed' },
				},
				language: 'en',
				sourceLanguage: 'nl',
			}),
			'an outdated translation is a wrong label, not a present one: a chip that counts it '
				+ 'reads full on a case type whose English labels are all out of date',
		).toEqual({ translated: 1, total: 2 })
	})

	it('reports one of two when one of two is translated', async () => {
		caseType.title.en = 'Environmental permit'
		rows = [{ property: 'title', language: 'en', status: 'human_reviewed' }]

		const wrapper = await mountWidget()

		const english = wrapper.find(
			'.case-type-translations__chip[data-language="en"]',
		)
		expect(english.text()).toContain('1 of 2')
	})

	it('drops a translation back out of the count when the source moves', async () => {
		caseType.title.en = 'Environmental permit'
		rows = [{ property: 'title', language: 'en', status: 'outdated' }]

		const wrapper = await mountWidget()

		expect(
			wrapper.find('.case-type-translations__chip[data-language="en"]').text(),
			'OpenRegister counts this row as translated; a stale label is a wrong label',
		).toContain('0 of 2')
		expect(wrapper.find('.case-type-translations__stale').exists()).toBe(true)
	})
})

describe('an admin adds an English title', () => {
	it('writes the whole language map, so the Dutch title survives', async () => {
		const wrapper = await mountWidget()

		// The page opens on the first language that is not the source, so the
		// reader lands where there is something to do.
		expect(wrapper.vm.activeLanguage).toBe('en')

		wrapper.vm.setDraft('title', 'Environmental permit')
		await wrapper.vm.save()

		expect(axios.patch).toHaveBeenCalledTimes(1)
		const [, body] = axios.patch.mock.calls[0]
		expect(
			body,
			'the PATCH must carry every language: a body holding only the edited one leaves the '
				+ 'Dutch title to whatever merge behaviour happens to be below it',
		).toEqual({
			title: { nl: 'Omgevingsvergunning', en: 'Environmental permit' },
		})
	})

	it('sends nothing for a label nobody touched', async () => {
		const wrapper = await mountWidget()

		wrapper.vm.setDraft('title', 'Environmental permit')
		await wrapper.vm.save()

		expect(axios.patch).toHaveBeenCalledTimes(1)
	})

	it('offers only the labels OpenRegister holds as translatable', async () => {
		// The property list comes from `_meta.languageMeta`, which OpenRegister
		// builds from the schema it imported. A label declared here but never
		// read by the engine is absent, and the page says so by not offering a
		// field that would save into nothing.
		delete caseType._meta.languageMeta.description

		const wrapper = await mountWidget()

		expect(wrapper.vm.properties).toEqual(['title'])
		expect(wrapper.findAll('.case-type-translations__field')).toHaveLength(1)
	})

	it('says so rather than drawing an empty page when the read fails', async () => {
		axios.get.mockRejectedValue(new Error('503'))

		const wrapper = await mountWidget()

		expect(wrapper.text()).toContain('could not be read')
		expect(wrapper.findAll('.case-type-translations__chip')).toHaveLength(0)
	})
})

describe('the page is wired to the registry', () => {
	it('resolves the widget through a slot, an icon and a registry entry', () => {
		// Four declarations no build step compares. Miss the slot and the
		// layout cell renders empty; miss the icon and the header renders no
		// glyph at all rather than a fallback.
		const page = manifest.pages.find((entry) => entry.id === 'CaseTypeDetail')
		const widget = page.config.widgets.find(
			(entry) => entry.id === 'case-type-translations',
		)

		expect(widget.type).toBe('custom')
		expect(widget.icon).toBe('Translate')
		expect(iconsSource).toContain(
			"import Translate from 'vue-material-design-icons/Translate.vue'",
		)
		expect(page.slots['widget-case-type-translations']).toBe(
			'CaseTypeTranslationsWidget',
		)
		expect(registrySource).toContain('CaseTypeTranslationsWidget: {')
		expect(
			page.config.layout.some(
				(cell) => cell.widgetId === 'case-type-translations',
			),
			'a widget with no layout cell is declared and never placed',
		).toBe(true)
	})
})

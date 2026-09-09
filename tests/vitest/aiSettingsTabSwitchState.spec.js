// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The AI settings tab shows what is STORED, or shows nothing.
 *
 * THE ASSERTION THAT CARRIES THE REQUIREMENT is "a switch stored as off renders
 * as off". A test that only checked the on case would have passed against the
 * defect, because the defect rendered everything as on.
 *
 * MEASURED CAUSE. `GET /api/ai/settings` answered the settings FLAT while this
 * component read `response.settings` and merged the result over hard-coded
 * defaults:
 *
 *     this.settings = { ...this.settings, ...(response.settings || {}) }
 *
 * `response.settings` was `undefined` on every response, so the merge was always
 * a merge of `{}` and the seeded defaults were what got drawn — `true` for all
 * six feature toggles AND for `ai_pii_stripping`. An administrator who turned
 * PII stripping off, or who never turned it on, was shown a switch saying it was
 * on. That is worse than the control being absent: a control that misreports its
 * own state stops anyone looking further.
 *
 * The `catch` made it worse in the same direction — a settings request that
 * failed outright left the same seeded `true`s on screen, so an unreachable
 * endpoint and a fully-enabled instance looked identical.
 *
 * TRUE-POSITIVE CONTROL, actually run rather than predicted, and the first run
 * corrected the prediction. With the component stashed back to its pre-fix state
 * (seeded `true` defaults, the merge above, and a `catch` that did nothing),
 * 4 of these 7 fail:
 *
 *   a switch the server did not report renders off  → expected 2 on, got 9 on
 *   a failed load draws no switches at all          → expected 0 switches, got 1
 *   a failed load says so                           → error note card not found
 *   a flat response is a failure, not empty         → expected 0 switches, got 1
 *
 * The first of those is the defect itself, reproduced: nine switches, every one
 * of them drawn ON, against a server that reported two.
 *
 * The two stored-off/stored-on tests pass in BOTH states, and that is worth
 * stating rather than hiding. They hand the component the corrected envelope,
 * which the old component also read correctly — the pre-fix defect was never in
 * the component alone, it was the MISMATCH between a server answering flat and a
 * client reading `.settings`. Neither half is wrong on its own, which is exactly
 * why neither half's own tests caught it. The server's half of that contract is
 * pinned in `tests/Unit/Controller/AiSettingsControllerShapeTest.php`; the tests
 * here pin the client's, and the discriminating ones are the four above.
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A switch stub that renders a REAL checkbox carrying its modelValue.
 *
 * Deliberately a live `<input type="checkbox">` rather than a bare div: the
 * defect's entire signature is the checked state of a switch, and a stub that
 * dropped `modelValue` would hide exactly the thing under test.
 *
 * @return {object} The stub component.
 */
function switchStub() {
	return {
		name: 'NcCheckboxRadioSwitch',
		props: {
			modelValue: { type: [Boolean, String, Number], default: false },
			type: { type: String, default: 'switch' },
		},
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				// Only the on/off switches carry `nc-switch`. The model-type
				// pair are RADIOS rendered by the same component, and a
				// selector that swept them in would report an unchecked
				// "Cloud" radio as a switch that is off.
				class: this.type === 'radio' ? 'nc-radio' : 'nc-switch',
				type: 'checkbox',
				checked: this.modelValue === true,
				'data-model-value': String(this.modelValue),
				onChange: (event) =>
					this.$emit('update:modelValue', event.target.checked),
			})
		},
	}
}

/**
 * A field stub carrying its modelValue as an input value.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function fieldStub(name) {
	return {
		name,
		props: { modelValue: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				class: name,
				value: String(this.modelValue ?? ''),
			})
		},
	}
}

/**
 * A stub that renders its slot inside a tagged element.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub component.
 */
function boxStub(name, tag = 'div') {
	return {
		name,
		props: { type: { type: String, default: '' } },
		render() {
			return h(
				tag,
				{ class: name, 'data-type': this.type },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: boxStub('NcButton', 'button'),
	NcCheckboxRadioSwitch: switchStub(),
	NcLoadingIcon: boxStub('NcLoadingIcon', 'span'),
	NcNoteCard: boxStub('NcNoteCard'),
	NcPasswordField: fieldStub('NcPasswordField'),
	NcTextField: fieldStub('NcTextField'),
}))

const AiSettingsTab = (
	await import('../../src/views/settings/tabs/AiSettingsTab.vue')
).default

/** Every on/off key the tab draws as a switch. */
const FEATURE_KEYS = [
	'ai_feature_classification',
	'ai_feature_extraction',
	'ai_feature_qa',
	'ai_feature_summary',
	'ai_feature_routing',
	'ai_feature_decision_support',
]

/**
 * A settings payload in the shape the endpoint now answers with.
 *
 * @param {object} overrides Values to override on the defaults.
 * @return {object} The `{ settings: {...} }` body.
 */
function body(overrides = {}) {
	const settings = {
		ai_enabled: true,
		ai_model_type: 'local',
		ai_model_url: 'http://ollama:11434',
		ai_model_name: 'llama3.1',
		ai_api_key_set: false,
		ai_pii_stripping: true,
		ai_dpia_acknowledged: true,
	}
	for (const key of FEATURE_KEYS) {
		settings[key] = true
	}
	return { settings: { ...settings, ...overrides } }
}

/**
 * Mount the tab against a given settings response and let it settle.
 *
 * @param {object|Error} response The body to answer with, or an error to throw.
 * @return {Promise<object>} The mounted wrapper.
 */
async function open(response) {
	if (response instanceof Error) {
		axios.get.mockRejectedValue(response)
	} else {
		axios.get.mockResolvedValue({ data: response })
	}
	const wrapper = mount(AiSettingsTab)
	await flushPromises()
	return wrapper
}

/**
 * The checked state of every rendered switch, keyed by nothing — order only.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {Array<boolean>} One entry per switch.
 */
function switchStates(wrapper) {
	return wrapper.findAll('.nc-switch').map((node) => node.element.checked)
}

describe('AiSettingsTab reports stored state', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('renders a switch stored as OFF as off', async () => {
		const off = {}
		for (const key of FEATURE_KEYS) {
			off[key] = false
		}
		off.ai_pii_stripping = false
		off.ai_dpia_acknowledged = false

		const wrapper = await open(body(off))

		// ai_enabled stays true so the rest of the form renders at all; every
		// other switch is stored off and must draw off.
		const states = switchStates(wrapper)
		expect(states.length).toBeGreaterThan(1)
		expect(states.filter((checked) => checked === true)).toHaveLength(1)
	})

	it('renders PII stripping stored as OFF as off', async () => {
		const wrapper = await open(body({ ai_pii_stripping: false }))

		const pii = wrapper
			.findAll('.nc-switch')
			.find((node) => node.attributes('data-model-value') === 'false')

		expect(pii).toBeDefined()
		expect(pii.element.checked).toBe(false)
	})

	it('renders a switch the server did NOT report as off, never as on', async () => {
		// The component must hold no opinion of its own about a switch it was
		// not told about. It used to seed `true` for all six features and for
		// PII stripping and merge the response over the top, so a key the
		// server omitted — or a whole response it failed to read — came out ON.
		const withoutPii = body()
		delete withoutPii.settings.ai_pii_stripping
		for (const key of FEATURE_KEYS) {
			delete withoutPii.settings[key]
		}

		const wrapper = await open(withoutPii)

		const states = switchStates(wrapper)
		expect(states.length).toBeGreaterThan(1)
		// ai_enabled and ai_dpia_acknowledged are the only two reported on.
		expect(states.filter((checked) => checked === true)).toHaveLength(2)
	})

	it('renders a switch stored as ON as on', async () => {
		const wrapper = await open(body())

		expect(switchStates(wrapper).every((checked) => checked === true)).toBe(true)
	})

	it('draws NO switches when the settings could not be loaded', async () => {
		const wrapper = await open(new Error('network down'))

		expect(wrapper.findAll('.nc-switch')).toHaveLength(0)
	})

	it('says so when the settings could not be loaded', async () => {
		const wrapper = await open(new Error('network down'))

		const note = wrapper.find('.NcNoteCard')
		expect(note.exists()).toBe(true)
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toContain('could not load the AI settings')
	})

	it('treats a response without a settings object as a failure, not as empty', async () => {
		// The exact shape the endpoint used to answer with: the settings, flat.
		const wrapper = await open({ ai_enabled: true, ai_pii_stripping: false })

		expect(wrapper.findAll('.nc-switch')).toHaveLength(0)
		expect(wrapper.find('.NcNoteCard').attributes('data-type')).toBe('error')
	})
})

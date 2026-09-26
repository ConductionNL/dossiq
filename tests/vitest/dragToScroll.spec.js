// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Grab-to-pan on the workflow board's row of columns. jsdom lays nothing out,
 * so the container's scroll position and size are defined by hand and the
 * pointer events are dispatched as plain events carrying the fields the
 * helper reads.
 *
 * The clock and the frame queue are driven by hand as well, so both what the
 * release speed is measured over and how far each glide frame carries the row
 * are exact rather than whatever the machine happened to do.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { attachDragToScroll } from '../../src/utils/dragToScroll.js'

let clock

/**
 * Take over `performance.now` and the animation frame queue.
 *
 * @return {object} Advance the clock with `at`, run the queued frames with `tick`, count them with `pending`.
 */
function fakeClock() {
	let now = 0
	let nextId = 1
	const frames = new Map()

	vi.spyOn(performance, 'now').mockImplementation(() => now)
	vi.stubGlobal('requestAnimationFrame', (callback) => {
		const id = nextId++
		frames.set(id, callback)
		return id
	})
	vi.stubGlobal('cancelAnimationFrame', (id) => frames.delete(id))

	return {
		at(ms) {
			now = ms
		},
		tick(ms) {
			now += ms
			const due = [...frames.values()]
			frames.clear()
			due.forEach((callback) => callback(now))
		},
		pending: () => frames.size,
	}
}

/**
 * @param {object} [size] scrollWidth and clientWidth, when the default row is the wrong shape.
 * @return {{ el: HTMLElement, card: HTMLElement, button: HTMLElement }} A container with a card and a button inside.
 */
function container(size = {}) {
	const el = document.createElement('div')
	Object.defineProperty(el, 'scrollLeft', { value: 100, writable: true })
	Object.defineProperty(el, 'clientHeight', { value: 400 })
	Object.defineProperty(el, 'clientWidth', { value: size.clientWidth ?? 800 })
	Object.defineProperty(el, 'scrollWidth', { value: size.scrollWidth ?? 3000 })
	el.getBoundingClientRect = () => ({ top: 0, left: 0, width: 800, height: 412 })
	const card = document.createElement('div')
	card.className = 'case-card'
	const button = document.createElement('button')
	el.append(card, button)
	document.body.append(el)
	return { el, card, button }
}

/**
 * Press, drag and let go, with the clock standing at the moment of each step.
 *
 * @param {HTMLElement} el The container.
 * @param {Array<[number, number]>} steps `[time, clientX]` for the press, each move, and the release.
 * @return {void}
 */
function flick(el, steps) {
	const [[downAt, downX], ...rest] = steps
	const [upAt, upX] = rest[rest.length - 1]
	clock.at(downAt)
	pointer(el, 'pointerdown', { clientX: downX })
	rest.slice(0, -1).forEach(([at, x]) => {
		clock.at(at)
		pointer(window, 'pointermove', { clientX: x })
	})
	clock.at(upAt)
	pointer(window, 'pointerup', { clientX: upX })
}

/**
 * @param {EventTarget} target Where the event lands.
 * @param {string} type The pointer event type.
 * @param {object} fields clientX, clientY, button, pointerId.
 * @return {Event} The dispatched event.
 */
function pointer(target, type, fields = {}) {
	const event = new Event(type, { bubbles: true, cancelable: true })
	Object.assign(event, {
		button: 0,
		pointerId: 1,
		clientX: 0,
		clientY: 10,
		...fields,
	})
	target.dispatchEvent(event)
	return event
}

let handle

beforeEach(() => {
	clock = fakeClock()
})

afterEach(() => {
	handle?.destroy()
	handle = undefined
	document.body.innerHTML = ''
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('grab-to-pan', () => {
	it('pans the container by the pointer travel while pressed on empty space', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		pointer(el, 'pointerdown', { clientX: 300 })
		const move = pointer(window, 'pointermove', { clientX: 250 })

		expect(el.scrollLeft).toBe(150)
		expect(el.classList.contains('is-panning')).toBe(true)
		expect(move.defaultPrevented).toBe(true)

		pointer(window, 'pointermove', { clientX: 340 })
		expect(el.scrollLeft).toBe(60)

		pointer(window, 'pointerup', { clientX: 340 })
		expect(el.classList.contains('is-panning')).toBe(false)
	})

	it('leaves a press that barely moves alone, so clicks stay clicks', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		pointer(el, 'pointerdown', { clientX: 300 })
		const move = pointer(window, 'pointermove', { clientX: 302 })
		pointer(window, 'pointerup', { clientX: 302 })

		expect(el.scrollLeft).toBe(100)
		expect(move.defaultPrevented).toBe(false)
		expect(el.classList.contains('is-panning')).toBe(false)
	})

	it('never starts on a card or a control', () => {
		const { el, card, button } = container()
		handle = attachDragToScroll(el)

		pointer(card, 'pointerdown', { clientX: 300 })
		pointer(window, 'pointermove', { clientX: 200 })
		pointer(window, 'pointerup', { clientX: 200 })
		pointer(button, 'pointerdown', { clientX: 300 })
		pointer(window, 'pointermove', { clientX: 200 })
		pointer(window, 'pointerup', { clientX: 200 })

		expect(el.scrollLeft).toBe(100)
	})

	it('ignores a secondary button and a press on the scrollbar', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		pointer(el, 'pointerdown', { clientX: 300, button: 2 })
		pointer(window, 'pointermove', { clientX: 200, button: 2 })
		pointer(window, 'pointerup', { clientX: 200, button: 2 })
		// Below clientHeight is the horizontal scrollbar's strip.
		pointer(el, 'pointerdown', { clientX: 300, clientY: 405 })
		pointer(window, 'pointermove', { clientX: 200, clientY: 405 })
		pointer(window, 'pointerup', { clientX: 200, clientY: 405 })

		expect(el.scrollLeft).toBe(100)
	})

	it('follows only the pointer that pressed', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		pointer(el, 'pointerdown', { clientX: 300, pointerId: 1 })
		pointer(window, 'pointermove', { clientX: 100, pointerId: 2 })

		expect(el.scrollLeft).toBe(100)
	})

	it('stops listening once destroyed', () => {
		const { el } = container()
		handle = attachDragToScroll(el)
		handle.destroy()

		pointer(el, 'pointerdown', { clientX: 300 })
		pointer(window, 'pointermove', { clientX: 200 })

		expect(el.scrollLeft).toBe(100)
		expect(el.classList.contains('is-panning')).toBe(false)
	})
})

/** A leftward flick: the pointer travels 100px in 32ms, so the row runs right. */
const FAST = [
	[0, 300],
	[16, 250],
	[32, 200],
	[48, 150],
]

/**
 * Flick a row of its own and let it run out, on a row long enough not to clamp.
 *
 * @param {Array<[number, number]>} steps `[time, clientX]` for the press, each move, and the release.
 * @return {number} How far the row carried on after the release.
 */
function glideDistance(steps) {
	const { el } = container({ scrollWidth: 20000 })
	const attached = attachDragToScroll(el)
	flick(el, steps)
	const released = el.scrollLeft
	for (let frame = 0; frame < 500 && clock.pending() > 0; frame++) {
		clock.tick(16)
	}
	expect(clock.pending()).toBe(0)
	attached.destroy()
	el.remove()
	return el.scrollLeft - released
}

describe('the glide after the release', () => {
	it('carries the row on and slows it to a stop', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		flick(el, FAST)
		expect(el.scrollLeft).toBe(200)

		clock.tick(16)
		const afterOneFrame = el.scrollLeft
		expect(afterOneFrame).toBeGreaterThan(200)

		clock.tick(16)
		const afterTwo = el.scrollLeft
		// Still moving, but never further than the frame before: that is friction.
		expect(afterTwo).toBeGreaterThan(afterOneFrame)
		expect(afterTwo - afterOneFrame).toBeLessThan(afterOneFrame - 200)

		for (let frame = 0; frame < 200 && clock.pending() > 0; frame++) {
			clock.tick(16)
		}
		expect(clock.pending()).toBe(0)
		expect(el.scrollLeft).toBeGreaterThan(afterTwo)
	})

	it('throws a hard flick further than a brisk one', () => {
		// Both of these are ordinary mouse speeds, and both used to land on the
		// same ceiling, which made every flick above walking pace feel identical.
		const brisk = glideDistance([
			[0, 700],
			[16, 604],
			[32, 508],
			[48, 412],
		])
		const hard = glideDistance([
			[0, 700],
			[16, 508],
			[32, 316],
			[48, 124],
		])

		expect(hard).toBeGreaterThan(brisk * 1.8)
	})

	it('stops at the end of the row rather than running past it', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		// Rightward, so the row runs back towards its own left edge.
		flick(el, [
			[0, 100],
			[16, 150],
			[32, 200],
			[48, 250],
		])
		expect(el.scrollLeft).toBe(0)

		clock.tick(16)

		expect(el.scrollLeft).toBe(0)
		expect(clock.pending()).toBe(0)
	})

	it('does not glide after a drag that was already slowing to a halt', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		flick(el, [
			[0, 300],
			[100, 280],
			[300, 278],
			[400, 277],
		])

		expect(el.scrollLeft).toBe(122)
		expect(clock.pending()).toBe(0)
	})

	it('does not glide when the pointer was held still before letting go', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		// One fast move, then a pause longer than the window the speed is read over.
		flick(el, [
			[0, 300],
			[16, 200],
			[316, 200],
		])

		expect(el.scrollLeft).toBe(200)
		expect(clock.pending()).toBe(0)
	})

	it('is caught by a press, including one that could never start a pan', () => {
		const { el, card } = container()
		handle = attachDragToScroll(el)

		flick(el, FAST)
		clock.tick(16)
		const caught = el.scrollLeft

		pointer(card, 'pointerdown', { clientX: 300 })
		expect(clock.pending()).toBe(0)

		clock.tick(16)
		expect(el.scrollLeft).toBe(caught)
	})

	it('stays put for someone who asked for less movement', () => {
		vi.stubGlobal('matchMedia', (query) => ({
			matches: query === '(prefers-reduced-motion: reduce)',
		}))
		const { el } = container()
		handle = attachDragToScroll(el)

		flick(el, FAST)

		expect(el.scrollLeft).toBe(200)
		expect(clock.pending()).toBe(0)
	})

	it('drops the glide when the board goes away mid-flight', () => {
		const { el } = container()
		handle = attachDragToScroll(el)

		flick(el, FAST)
		expect(clock.pending()).toBe(1)

		handle.destroy()

		expect(clock.pending()).toBe(0)
	})
})

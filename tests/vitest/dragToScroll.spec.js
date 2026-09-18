// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Grab-to-pan on the workflow board's row of columns. jsdom lays nothing out,
 * so the container's scroll position and size are defined by hand and the
 * pointer events are dispatched as plain events carrying the fields the
 * helper reads.
 */

import { afterEach, describe, expect, it } from 'vitest'
import { attachDragToScroll } from '../../src/utils/dragToScroll.js'

/**
 * @return {{ el: HTMLElement, card: HTMLElement, button: HTMLElement }} A container with a card and a button inside.
 */
function container() {
	const el = document.createElement('div')
	Object.defineProperty(el, 'scrollLeft', { value: 100, writable: true })
	Object.defineProperty(el, 'clientHeight', { value: 400 })
	el.getBoundingClientRect = () => ({ top: 0, left: 0, width: 800, height: 412 })
	const card = document.createElement('div')
	card.className = 'case-card'
	const button = document.createElement('button')
	el.append(card, button)
	document.body.append(el)
	return { el, card, button }
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

describe('grab-to-pan', () => {
	let handle

	afterEach(() => {
		handle?.destroy()
		document.body.innerHTML = ''
	})

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

/**
 * Scroll a wide container sideways by grabbing its empty space.
 *
 * The workflow board is one row of columns wider than the screen, and the
 * scrollbar under it is a long way from where the pointer is. Pressing on
 * anything that is not a card or a control and moving sideways pans the row
 * instead; a press that does not move is left alone, so clicks keep working.
 * Cards are excluded so Sortable's own pointer drag is never fought over.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/** Pointer travel, in px, before a press counts as a pan rather than a click. */
const PAN_THRESHOLD = 4

/** What a press on must never start a pan: controls, and the draggable cards. */
const DEFAULT_IGNORE =
	'button, a, input, textarea, select, label, [role="button"], .case-card'

/**
 * Attach grab-to-pan to a horizontally scrolling element.
 *
 * @param {HTMLElement} el The scroll container.
 * @param {object} [options] Options.
 * @param {string} [options.ignore] Selector for targets a pan must not start on.
 * @param {string} [options.panningClass] Class set on `el` while panning.
 * @return {{ destroy: () => void }} Detaches every listener.
 */
export function attachDragToScroll(el, options = {}) {
	const ignore = options.ignore ?? DEFAULT_IGNORE
	const panningClass = options.panningClass ?? 'is-panning'
	let press = null

	/**
	 * @param {PointerEvent} event The press.
	 * @return {void}
	 */
	function onPointerDown(event) {
		if (event.button !== 0 || press !== null) {
			return
		}
		if (event.target instanceof Element && event.target.closest(ignore)) {
			return
		}
		// A press on the scrollbar itself is the browser's to handle.
		const rect = el.getBoundingClientRect()
		if (event.clientY - rect.top > el.clientHeight) {
			return
		}
		press = {
			pointerId: event.pointerId,
			startX: event.clientX,
			startLeft: el.scrollLeft,
			panning: false,
		}
	}

	/**
	 * @param {PointerEvent} event The move.
	 * @return {void}
	 */
	function onPointerMove(event) {
		if (press === null || event.pointerId !== press.pointerId) {
			return
		}
		const dx = event.clientX - press.startX
		if (!press.panning) {
			if (Math.abs(dx) < PAN_THRESHOLD) {
				return
			}
			press.panning = true
			el.classList.add(panningClass)
			if (typeof el.setPointerCapture === 'function') {
				try {
					el.setPointerCapture(press.pointerId)
				} catch {
					// A pointer that already ended cannot be captured; panning
					// still works while the pointer stays over the element.
				}
			}
		}
		el.scrollLeft = press.startLeft - dx
		event.preventDefault()
	}

	/**
	 * @param {PointerEvent} event The release.
	 * @return {void}
	 */
	function onPointerUp(event) {
		if (press === null || event.pointerId !== press.pointerId) {
			return
		}
		if (press.panning) {
			el.classList.remove(panningClass)
		}
		press = null
	}

	el.addEventListener('pointerdown', onPointerDown)
	window.addEventListener('pointermove', onPointerMove)
	window.addEventListener('pointerup', onPointerUp)
	window.addEventListener('pointercancel', onPointerUp)

	return {
		destroy() {
			el.removeEventListener('pointerdown', onPointerDown)
			window.removeEventListener('pointermove', onPointerMove)
			window.removeEventListener('pointerup', onPointerUp)
			window.removeEventListener('pointercancel', onPointerUp)
			el.classList.remove(panningClass)
			press = null
		},
	}
}

const handles = new WeakMap()

/**
 * `v-drag-to-scroll`: grab-to-pan for the element it is placed on.
 *
 * @type {import('vue').Directive<HTMLElement>}
 */
export const dragToScroll = {
	mounted(el) {
		handles.set(el, attachDragToScroll(el))
	},
	unmounted(el) {
		handles.get(el)?.destroy()
		handles.delete(el)
	},
}

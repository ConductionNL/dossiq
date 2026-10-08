/**
 * Scroll a wide container sideways by grabbing its empty space.
 *
 * The workflow board is one row of columns wider than the screen, and the
 * scrollbar under it is a long way from where the pointer is. Pressing on
 * anything that is not a card or a control and moving sideways pans the row
 * instead; a press that does not move is left alone, so clicks keep working.
 * Cards are excluded so Sortable's own pointer drag is never fought over.
 *
 * Letting go while still moving keeps the row gliding and slows it down, so
 * reaching a far column is one flick rather than several drags. A press
 * anywhere in the container catches it again.
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
 * How much pointer history, in ms, the release speed is measured over. Short
 * enough that a quick flick is read near its peak rather than averaged with
 * the wind-up that got it there.
 */
const VELOCITY_WINDOW = 60

/** Release speed, in px/ms, under which the row just stops where it is. */
const MIN_GLIDE_SPEED = 0.1

/**
 * Ceiling, in px/ms, on the glide. A guard against a nonsense sample, not a
 * design speed: a mouse flick runs from about 1 to 15, and a ceiling inside
 * that range flattens every flick above it into the same throw.
 */
const MAX_GLIDE_SPEED = 20

/** The frame length, in ms, the friction below is expressed per. */
const FRAME = 1000 / 60

/** Share of its speed the glide keeps each frame. Stops in roughly a second. */
const FRICTION = 0.95

/** Speed, in px/ms, at which the glide has arrived and ends. */
const GLIDE_STOP_SPEED = 0.02

/**
 * Longest frame, in ms, the glide integrates over. A backgrounded tab hands
 * back a gap of seconds, which would teleport the row.
 */
const MAX_FRAME = 64

/**
 * Whether the person asked for less movement than this.
 *
 * @return {boolean} True when the glide should not happen.
 *
 * @spec exclude Sideways panning of the board row; a pointer affordance over the existing scroll, with no spec scenario of its own.
 */
function prefersReducedMotion() {
	if (typeof window.matchMedia !== 'function') {
		return false
	}
	return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * Attach grab-to-pan to a horizontally scrolling element.
 *
 * @param {HTMLElement} el The scroll container.
 * @param {object} [options] Options.
 * @param {string} [options.ignore] Selector for targets a pan must not start on.
 * @param {string} [options.panningClass] Class set on `el` while panning.
 * @return {{ destroy: () => void }} Detaches every listener.
 *
 * @spec exclude Sideways panning of the board row; a pointer affordance over the existing scroll, with no spec scenario of its own.
 */
export function attachDragToScroll(el, options = {}) {
	const ignore = options.ignore ?? DEFAULT_IGNORE
	const panningClass = options.panningClass ?? 'is-panning'
	let press = null
	let glide = null
	let samples = []

	/**
	 * End the glide, wherever it got to.
	 *
	 * @return {void}
	 */
	function stopGlide() {
		if (glide !== null) {
			cancelAnimationFrame(glide.frame)
			glide = null
		}
	}

	/**
	 * Carry the row one frame further and slow it down.
	 *
	 * @param {number} now The frame's timestamp, in ms.
	 * @return {void}
	 */
	function stepGlide(now) {
		const elapsed = Math.min(Math.max(now - glide.at, 0), MAX_FRAME)
		glide.at = now
		glide.velocity *= FRICTION ** (elapsed / FRAME)

		// Carried as a float: scrollLeft rounds, and rounding away part of every
		// frame's movement would stall a slow glide short of where it was going.
		const wanted = glide.position + glide.velocity * elapsed
		const limit = Math.max(el.scrollWidth - el.clientWidth, 0)
		glide.position = Math.min(Math.max(wanted, 0), limit)
		el.scrollLeft = glide.position

		if (
			glide.position !== wanted
			|| Math.abs(glide.velocity) < GLIDE_STOP_SPEED
		) {
			glide = null
			return
		}
		glide.frame = requestAnimationFrame(stepGlide)
	}

	/**
	 * How fast the pointer was moving, in px/ms, over the last stretch of the pan.
	 *
	 * Measured from the oldest sample still inside the window, so a pointer held
	 * still before the release reports nothing and the row stops dead.
	 *
	 * @param {number} x Where the pointer let go.
	 * @param {number} at When it let go, in ms.
	 * @return {number} Signed speed; positive is a rightward pointer.
	 */
	function releaseVelocity(x, at) {
		const oldest = samples.find((sample) => at - sample.at <= VELOCITY_WINDOW)
		if (oldest === undefined || at <= oldest.at) {
			return 0
		}
		return (x - oldest.x) / (at - oldest.at)
	}

	/**
	 * Keep the row moving after the release, if it was moving enough to mean it.
	 *
	 * @param {number} velocity The pointer's release speed, in px/ms.
	 * @return {void}
	 */
	function startGlide(velocity) {
		const speed = Math.min(Math.abs(velocity), MAX_GLIDE_SPEED)
		if (speed < MIN_GLIDE_SPEED || prefersReducedMotion()) {
			return
		}
		glide = {
			// The row travels against the pointer, as it does during the pan.
			velocity: velocity > 0 ? -speed : speed,
			position: el.scrollLeft,
			at: performance.now(),
			frame: 0,
		}
		glide.frame = requestAnimationFrame(stepGlide)
	}

	/**
	 * @param {PointerEvent} event The press.
	 * @return {void}
	 */
	function onPointerDown(event) {
		// Before every guard below: catching a gliding row is what a press on it
		// means, including a press on a card or a control.
		stopGlide()
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
		samples = []
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
		samples.push({ x: event.clientX, at: performance.now() })
		while (
			samples.length > 1
			&& samples[0].at < samples[samples.length - 1].at - VELOCITY_WINDOW
		) {
			samples.shift()
		}
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
			startGlide(releaseVelocity(event.clientX, performance.now()))
		}
		press = null
		samples = []
	}

	el.addEventListener('pointerdown', onPointerDown)
	window.addEventListener('pointermove', onPointerMove)
	window.addEventListener('pointerup', onPointerUp)
	window.addEventListener('pointercancel', onPointerUp)

	return {
		/**
		 * Detach every listener this attachment added.
		 *
		 * @return {void}
		 *
		 * @spec exclude Sideways panning of the board row; a pointer affordance
		 * over the existing scroll, with no spec scenario of its own.
		 */
		destroy() {
			el.removeEventListener('pointerdown', onPointerDown)
			window.removeEventListener('pointermove', onPointerMove)
			window.removeEventListener('pointerup', onPointerUp)
			window.removeEventListener('pointercancel', onPointerUp)
			el.classList.remove(panningClass)
			stopGlide()
			press = null
			samples = []
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
	/**
	 * Attach on mount.
	 *
	 * @param {HTMLElement} el The scroll container.
	 * @return {void}
	 *
	 * @spec exclude Sideways panning of the board row; a pointer affordance
	 * over the existing scroll, with no spec scenario of its own.
	 */
	mounted(el) {
		handles.set(el, attachDragToScroll(el))
	},

	/**
	 * Detach on unmount, so a re-rendered board leaves no window listeners.
	 *
	 * @param {HTMLElement} el The scroll container.
	 * @return {void}
	 *
	 * @spec exclude Sideways panning of the board row; a pointer affordance
	 * over the existing scroll, with no spec scenario of its own.
	 */
	unmounted(el) {
		handles.get(el)?.destroy()
		handles.delete(el)
	},
}

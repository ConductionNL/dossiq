<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->

<template>
	<div
		class="map-component"
		:class="{ 'map-component--readonly': !interactive }"
		:role="interactive ? 'application' : 'img'"
		:aria-label="ariaLabel"
		:style="containerStyle">
		<CnMapWidget
			ref="widget"
			:locations="resolvedMarkers"
			:center="center"
			:zoom="zoom"
			:tile-layer="tileLayer"
			:clustering="clustering && interactive"
			:interactive="interactive"
			:options="leafletOptions"
			@marker-click="onMarkerClick"
			@viewport-change="onViewportChangeRaw"
			@ready="onReady" />
	</div>
</template>

<script>
import { CnMapWidget } from '@conduction/nextcloud-vue'
import { resolveFormatter } from '../../services/mapFormatters.js'

/**
 * Default debounce window for viewport-change emissions. The lib widget
 * fires `viewport-change` on every `moveend` / `zoomend`; Procest
 * downstream consumers (case-detail tab cache, dashboard URL sync)
 * cannot keep up with un-debounced traffic during a long pan.
 */
const VIEWPORT_DEBOUNCE_MS = 200

/**
 * Procest-side wrapper around `CnMapWidget` from `@conduction/nextcloud-vue`.
 *
 * One wrapper, three surfaces — case detail map tab, dashboard map
 * widget, public case page. The wrapper exists to:
 *
 *  1. Pin Procest defaults (PDOK BRT tiles, NL centre, case formatter).
 *  2. Switch ARIA role + Leaflet interaction flags via `interactive` prop.
 *  3. Resolve `markerFormatter` by name from the `mapFormatters` registry,
 *     so manifest pages can configure formatters declaratively.
 *
 * The component is stateless w.r.t. data: parents own `locations[]` and
 * listen for `marker-click` / `viewport-change`. Internal state is
 * limited to the debounce timer.
 */
export default {
	name: 'MapComponent',
	components: { CnMapWidget },
	props: {
		/**
		 * Geometry-bearing input objects. Each must carry a GeoJSON
		 * `geometry` field (Point or Polygon). The formatter handles
		 * polygon-to-centroid reduction.
		 */
		locations: {
			type: Array,
			default: () => [],
		},
		/**
		 * Initial map centre. Defaults to the Netherlands geographic
		 * centre so empty maps render in a sensible viewport.
		 */
		center: {
			type: Object,
			default: () => ({ lat: 52.1326, lon: 5.2913 }),
		},
		/** Initial zoom level. */
		zoom: {
			type: Number,
			default: 7,
		},
		/**
		 * When `false`, disables pan/zoom/keyboard and hides zoom
		 * controls (public read-only mode). Container role switches
		 * from `application` to `img`.
		 */
		interactive: {
			type: Boolean,
			default: true,
		},
		/**
		 * Name of a formatter registered in `src/services/mapFormatters.js`.
		 * Unknown names fall back to `caseMarkerFormatter`.
		 */
		markerFormatter: {
			type: String,
			default: 'caseMarkerFormatter',
		},
		/** Tile layer key resolved inside `CnMapWidget`. */
		tileLayer: {
			type: String,
			default: 'pdok-brt',
		},
		/** Enable marker clustering (auto-disabled in read-only mode). */
		clustering: {
			type: Boolean,
			default: true,
		},
		/** CSS height for the container; parent typically overrides. */
		height: {
			type: String,
			default: '400px',
		},
	},
	emits: ['marker-click', 'viewport-change', 'ready'],
	data() {
		return {
			viewportDebounceTimer: null,
		}
	},
	computed: {
		/**
		 * The resolved formatter function. `markerFormatter` is a
		 * string so manifest entries can configure it declaratively.
		 */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		formatterFn() {
			return resolveFormatter(this.markerFormatter)
		},
		/**
		 * Pre-format markers up-front so the lib widget receives a
		 * plain array of marker descriptors plus a reference back to
		 * the original location for event payloads.
		 */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		resolvedMarkers() {
			const out = []
			for (const location of this.locations) {
				const marker = this.formatterFn(location)
				if (marker) {
					out.push({ ...marker, __sourceLocation: location })
				}
			}
			return out
		},
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		ariaLabel() {
			// Inline fallback strings — `t()` returns the English
			// source when no l10n bundle is loaded, which is the
			// repo's standard pattern.
			return this.interactive
				? this.t('procest', 'Map with case locations')
				: this.t('procest', 'Map with case locations (read-only)')
		},
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		containerStyle() {
			return { height: this.height }
		},
		/**
		 * Leaflet options forwarded to the lib widget. Read-only mode
		 * disables every interaction primitive; interactive mode lets
		 * Leaflet's defaults apply.
		 */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		leafletOptions() {
			if (this.interactive) {
				return {
					dragging: true,
					scrollWheelZoom: true,
					doubleClickZoom: true,
					boxZoom: true,
					keyboard: true,
					zoomControl: true,
				}
			}
			return {
				dragging: false,
				scrollWheelZoom: false,
				doubleClickZoom: false,
				boxZoom: false,
				keyboard: false,
				zoomControl: false,
			}
		},
	},
	/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
	beforeDestroy() {
		if (this.viewportDebounceTimer) {
			clearTimeout(this.viewportDebounceTimer)
			this.viewportDebounceTimer = null
		}
	},
	methods: {
		/**
		 * Re-emit the lib widget's marker-click with the original
		 * location object the parent passed in. The `__sourceLocation`
		 * stamp on each formatted marker preserves referential
		 * equality so parents can compare by identity.
		 *
		 * @param {object} marker The formatted marker descriptor.
		 */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		onMarkerClick(marker) {
			const location = marker && marker.__sourceLocation
				? marker.__sourceLocation
				: null
			this.$emit('marker-click', { marker, location })
		},
		/**
		 * Debounce viewport changes to {@link VIEWPORT_DEBOUNCE_MS}.
		 * The lib widget can fire many `viewport-change` events
		 * during a single pan; consumers (case-detail tab cache,
		 * dashboard URL sync) only need the final value.
		 *
		 * @param {object} payload `{ center, zoom, bbox }` from the lib.
		 */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		onViewportChangeRaw(payload) {
			if (this.viewportDebounceTimer) {
				clearTimeout(this.viewportDebounceTimer)
			}
			this.viewportDebounceTimer = setTimeout(() => {
				this.$emit('viewport-change', payload)
				this.viewportDebounceTimer = null
			}, VIEWPORT_DEBOUNCE_MS)
		},
		/**
		 * Surface the Leaflet `L.Map` instance for advanced parents.
		 * This is an escape hatch — most consumers shouldn't need it.
		 *
		 * @param {object} payload `{ map }` from the lib.
		 */
		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		onReady(payload) {
			this.$emit('ready', payload)
		},
	},
}
</script>

<style scoped>
.map-component {
	position: relative;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.map-component:focus-within {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.map-component--readonly {
	cursor: default;
}
</style>

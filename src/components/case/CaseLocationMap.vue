<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="case-location-map" data-testid="case-location-map">
		<NcLoadingIcon v-if="loading" :size="32" />

		<div
			v-else-if="error"
			class="case-location-map__banner"
			role="alert"
			data-testid="case-location-map-error">
			{{ error }}
		</div>

		<div
			v-else-if="features.length === 0"
			class="case-location-map__empty"
			data-testid="case-location-map-empty">
			{{ emptyText }}
		</div>

		<CnMapWidget
			v-else
			:markers="{ features }"
			:height="height"
			:autoFit="true"
			:aria-label="mapLabel" />
	</div>
</template>

<script>
import { CnMapWidget } from '@conduction/nextcloud-vue'
/**
 * The case's own locations on a map.
 *
 * WHY THIS IS A BESPOKE WIDGET AND NOT `type: "map"`
 * --------------------------------------------------
 * The library's `map` widget can plot an OpenRegister register and schema
 * directly, and that is what a manifest would reach for here. It cannot be
 * used, because it cannot be scoped to one case, and the way it fails is the
 * dangerous kind rather than the loud kind:
 *
 *   - `markers.dataSource.{register, schema}` fetches
 *     `/apps/openregister/api/objects/{register}/{schema}` with `_limit` and
 *     NOTHING else. There is no filter in the signature, so a case page would
 *     plot every `case-location` in the register: other cases' addresses,
 *     rendered as this case's pins, with nothing on screen saying so.
 *   - `markers.dataSource.url` would take a filtered URL, but `CnMapWidget`
 *     resolves no manifest tokens at all, so `@objectId` would be sent to the
 *     server as the literal seven characters.
 *
 * Both routes end in a map that looks right and is wrong. So the filter lives
 * here, where the widget is handed the case's `objectId` as a prop, exactly as
 * `case-task-pane` is.
 *
 * This is interim by construction, the same way `case-task-pane` is. The
 * moment the library takes a filter on `markers.dataSource` this component is
 * deleted and the manifest goes back to `type: "map"`.
 *
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'CaseLocationMap',

	components: {
		CnMapWidget,
		NcLoadingIcon,
	},

	props: {
		/**
		 * The case whose locations are plotted.
		 *
		 * Injected by `CnDetailWidgetHost` for a widget rendered inside a tab,
		 * the same prop `case-task-pane` reads.
		 */
		objectId: {
			type: String,
			default: '',
		},

		/**
		 * Manifest `content` block: `{ height, emptyText, limit }`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/** GeoJSON features, one per location that has usable coordinates. */
			features: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The map's height, from the manifest or a compact default.
		 *
		 * @return {string} A CSS length.
		 */
		height() {
			return String(this.content.height || '260px')
		},

		/**
		 * What to say when the case has no plottable location.
		 *
		 * @return {string} The empty text.
		 */
		emptyText() {
			return String(
				this.content.emptyText
					|| t('dossiq', 'No locations linked to this case yet'),
			)
		},

		/**
		 * Accessible name for the map region.
		 *
		 * @return {string} The label.
		 */
		mapLabel() {
			return t('dossiq', 'Locations on this case')
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read this case's locations and turn them into GeoJSON.
		 *
		 * A location row with no usable latitude and longitude is SKIPPED
		 * rather than plotted at (0, 0), which is in the Gulf of Guinea and
		 * looks like a real pin.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			const caseId = String(this.objectId ?? '').trim()
			if (caseId === '') {
				this.features = []
				this.loading = false
				return
			}

			this.loading = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/dossiq/case-location',
				)
				const response = await fetch(
					`${url}?case=${encodeURIComponent(caseId)}&_limit=${Number(this.content.limit) || 100}`,
					{ headers: { requesttoken: this.requestToken() } },
				)
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}`)
				}
				const body = await response.json()
				this.features = (body.results || [])
					.map((row) => this.toFeature(row))
					.filter(Boolean)
			} catch (err) {
				// An empty map and a failed fetch look identical, so say which
				// one this is rather than letting it read as "no locations". The
				// banner and the toast carry it; there is no console line,
				// because a `no-console` disable is a debt-ratchet counter and
				// a message the handler cannot see is not worth one.
				this.error = `${t('dossiq', 'The locations could not be loaded.')} ${String(err.message ?? err)}`
				showError(this.error)
				this.features = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * One location row as a GeoJSON point feature.
		 *
		 * @param {object} row A `case-location` object.
		 * @return {object|null} The feature, or null when it cannot be plotted.
		 */
		toFeature(row) {
			const lat = Number(row.latitude)
			const lng = Number(row.longitude)
			if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
				return null
			}
			return {
				type: 'Feature',
				geometry: { type: 'Point', coordinates: [lng, lat] },
				properties: {
					title: row.label || row.formattedAddress || '',
				},
			}
		},

		/**
		 * The CSRF token Nextcloud requires on an API read.
		 *
		 * @return {string} The token, or an empty string.
		 */
		requestToken() {
			const meta = document.head.querySelector('meta[name="csrf-token"]')
			if (meta && meta.content) {
				return meta.content
			}
			return (window.OC && window.OC.requestToken) || ''
		},
	},
}
</script>

<style scoped>
.case-location-map {
	width: 100%;
}

.case-location-map__empty,
.case-location-map__banner {
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
}

.case-location-map__banner {
	color: var(--color-error-text, var(--color-error));
}
</style>

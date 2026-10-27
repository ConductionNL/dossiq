<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Adding a location to a case (board DqZaakLocatie).

  The address comes from the PDOK Locatieserver, so only a BAG address can be
  saved: a typed string has no address designation and would put a pin
  nowhere a later lookup can find. The map shows the chosen address with the
  map layers the organisation configured; the BAG and the cadastre/WOZ cards
  show what those registers say about it, read through dossiq's own BRK and
  WOZ adapters. Saving writes one `case-location` on the case.

  @spec openspec/specs/case-dashboard-view/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Add location')"
		:eyebrow="eyebrow"
		width="wizard"
		data-testid="case-location-dialog"
		@closing="$emit('close')">
		<div class="case-location-dialog">
			<CnAddressSearch
				:inputLabel="t('dossiq', 'Search address')"
				data-testid="case-location-search"
				@select="onSelect" />
			<p class="case-location-dialog__hint">
				{{ t('dossiq', 'Searches the PDOK Locatieserver. Only addresses from the BAG can be saved.') }}
			</p>

			<div class="case-location-dialog__map-row">
				<div class="case-location-dialog__map">
					<CnMapWidget
						:key="mapKey"
						:center="center"
						:zoom="chosen ? 17 : 12"
						:layers="activeLayers"
						:markers="markers"
						height="200px"
						:fitControl="false"
						:locateControl="false"
						:fullscreenControl="false"
						:zoomControl="false"
						:ariaLabel="t('dossiq', 'The chosen address on a map')" />
				</div>
				<fieldset class="case-location-dialog__layers" data-testid="case-location-layers">
					<legend>{{ t('dossiq', 'Map layers') }}</legend>
					<p v-if="layers.length === 0" class="case-location-dialog__hint">
						{{ t('dossiq', 'No map layers are set up yet.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-for="layer in layers"
						:key="layer.id"
						:modelValue="shown.includes(layer.id)"
						@update:modelValue="toggleLayer(layer.id, $event)">
						{{ layer.title }}
					</NcCheckboxRadioSwitch>
				</fieldset>
			</div>

			<div class="case-location-dialog__facts">
				<section class="case-location-dialog__card" data-testid="case-location-bag">
					<h3>{{ t('dossiq', 'BAG') }}</h3>
					<dl>
						<dt>{{ t('dossiq', 'Address designation') }}</dt>
						<dd>{{ chosen?.result?.nummeraanduiding_id || '—' }}</dd>
						<dt>{{ t('dossiq', 'Use') }}</dt>
						<dd>{{ useLabel || '—' }}</dd>
					</dl>
				</section>
				<section class="case-location-dialog__card" data-testid="case-location-cadastre">
					<h3>{{ t('dossiq', 'Cadastre and WOZ') }}</h3>
					<dl>
						<dt>{{ t('dossiq', 'Parcel') }}</dt>
						<dd>{{ parcelLabel || '—' }}</dd>
						<dt>{{ t('dossiq', 'Rights holders') }}</dt>
						<dd>{{ holders === null ? '—' : holders }}</dd>
						<dt>{{ t('dossiq', 'WOZ value') }}</dt>
						<dd>{{ wozLabel || '—' }}</dd>
					</dl>
				</section>
			</div>

			<NcNoteCard v-if="error" type="error" data-testid="case-location-error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!chosen || saving"
				data-testid="case-location-save"
				@click="save">
				<template #icon>
					<MapMarkerOutline :size="20" />
				</template>
				{{ t('dossiq', 'Save location') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnAddressSearch, CnDialog, CnMapWidget } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import { lookupParcel } from '../services/brkApi.js'
import { caseLocationRecord, parcelOf, saveCaseLocation } from '../services/caseLocationApi.js'
import { fetchCaseSummary } from '../services/caseSummary.js'
import { lookupWozValueByNummeraanduiding } from '../services/wozApi.js'

/** The centre of the Netherlands, before an address is chosen. */
const DEFAULT_CENTER = [52.13, 5.29]

export default {
	name: 'CaseLocationDialog',

	components: {
		CnAddressSearch,
		CnDialog,
		CnMapWidget,
		MapMarkerOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	props: {
		/** The case the location goes on; falls back to the route's id. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			/** What the address search emitted for the chosen address, or null. */
			chosen: null,
			/** The configured WMS/WFS layers, `{ id, title, type, url, layerName, isDefault }`. */
			layers: [],
			/** The ids of the layers drawn on the map. */
			shown: [],
			/** The WOZ object for the address, or null. */
			woz: null,
			/** The cadastral parcel for the address, or null. */
			parcel: null,
			saving: false,
			error: '',
			/** The case, for the eyebrow. */
			kase: null,
		}
	},

	computed: {
		/**
		 * @return {string} The case id, from the prop or the route.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * "<case title>, locatie", as the board heads the dialog; just the act
		 * while the case is not read yet.
		 *
		 * @return {string} The eyebrow.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		eyebrow() {
			const title = String(this.kase?.title ?? '').trim()
			return title === '' ? t('dossiq', 'Location') : t('dossiq', '{case}, location', { case: title })
		},

		/**
		 * @return {number[]} The map centre: the chosen address, else the country.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		center() {
			const c = this.chosen?.coordinates
			return c && Number.isFinite(c.lat) && Number.isFinite(c.lng) ? [c.lat, c.lng] : DEFAULT_CENTER
		},

		/**
		 * @return {string} Rebuilds the map when the address or the layers change.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		mapKey() {
			return `${this.center.join(',')}|${this.shown.join(',')}`
		},

		/**
		 * @return {object|null} One pin on the chosen address.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		markers() {
			return this.chosen ? { centerMarker: true } : null
		},

		/**
		 * @return {Array<object>} The checked layers in the shape CnMapWidget reads.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		activeLayers() {
			return this.layers
				.filter((layer) => this.shown.includes(layer.id))
				.map((layer) => ({
					type: String(layer.type).toLowerCase() === 'wfs' ? 'wfs' : 'wms',
					url: layer.url,
					options: { layers: layer.layerName, format: 'image/png', transparent: true },
				}))
		},

		/**
		 * @return {string} The cadastral reference of the chosen address.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		parcelLabel() {
			return this.parcel?.kadastraleAanduiding || parcelOf(this.chosen?.result)?.label || ''
		},

		/**
		 * @return {number|null} How many hold a right on the parcel.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		holders() {
			const list = this.parcel?.zakelijkGerechtigden
			return Array.isArray(list) ? list.length : null
		},

		/**
		 * @return {string} What the WOZ object is used for.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		useLabel() {
			const uses = this.woz?.gebruiksdoel
			return Array.isArray(uses) ? uses.join(', ') : ''
		},

		/**
		 * @return {string} The WOZ value with its reference date.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		wozLabel() {
			const value = this.woz?.value
			if (!Number.isFinite(value)) {
				return ''
			}
			const money = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(value)
			return this.woz.waardepeildatum ? `${money} (${this.woz.waardepeildatum})` : money
		},
	},

	async mounted() {
		await Promise.all([
			this.loadLayers(),
			fetchCaseSummary(this.targetCaseId).then((kase) => {
				this.kase = kase
			}),
		])
	},

	methods: {
		t,

		/**
		 * Read the active map layers the organisation configured.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		async loadLayers() {
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/dossiq/wmsLayer'), { params: { _limit: 20 } })
				const rows = Array.isArray(data?.results) ? data.results : []
				this.layers = rows
					.filter((row) => row.active !== false)
					.map((row) => ({ ...row, id: row.id || row['@self']?.id || row.title }))
				this.shown = this.layers.filter((layer) => layer.isDefault).map((layer) => layer.id)
			} catch {
				this.layers = []
			}
		},

		/**
		 * Show or hide one map layer.
		 *
		 * @param {string} id The layer id.
		 * @param {boolean} on Whether it is drawn.
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		toggleLayer(id, on) {
			this.shown = on ? [...new Set([...this.shown, id])] : this.shown.filter((entry) => entry !== id)
		},

		/**
		 * An address was chosen: look up what the registers say about it.
		 *
		 * @param {{result: object, coordinates: object|null, address: string}|null} chosen What the address search emitted; null when it was cleared.
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		async onSelect(chosen) {
			this.chosen = chosen
			this.error = ''
			this.woz = null
			this.parcel = null
			const designation = chosen?.result?.nummeraanduiding_id
			const parcel = parcelOf(chosen?.result)
			const [woz, brk] = await Promise.allSettled([
				designation ? lookupWozValueByNummeraanduiding(designation) : Promise.resolve(null),
				parcel ? lookupParcel(parcel.kadastraleGemeenteCode, parcel.sectie, parcel.perceelnummer) : Promise.resolve(null),
			])
			this.woz = woz.status === 'fulfilled' ? (woz.value?.wozObject || null) : null
			this.parcel = brk.status === 'fulfilled' ? (brk.value?.parcel || null) : null
		},

		/**
		 * Save the chosen address on the case.
		 *
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		async save() {
			if (!this.chosen || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const saved = await saveCaseLocation(caseLocationRecord(this.targetCaseId, this.chosen))
				showSuccess(t('dossiq', 'Location added to the case.'))
				emit('dossiq:case-location:saved', { caseId: this.targetCaseId })
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (error) {
				this.error = error?.response?.data?.message || t('dossiq', 'The location could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-location-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.case-location-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* The search's hint sits 6px under the field, as the board draws it with the
   field's label and field in one group, not a full 16px step below the search
   (whose select keeps a margin of its own), on the normal line. */
.case-location-dialog > .case-location-dialog__hint {
	margin-top: -16px;
	line-height: 1.2;
}

.case-location-dialog__map {
	min-height: 200px;
	border-radius: 8px;
	overflow: hidden;
}

/* The map takes the row; the layer list beside it is 200px. */
.case-location-dialog__map-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 200px;
	gap: 12px;
}

.case-location-dialog__layers {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 10px 12px;
}

.case-location-dialog__layers legend {
	padding: 0 4px;
	font-size: 14px;
	font-weight: 600;
	line-height: 1.2;
}

/* The board's layer toggles are plain 14px checkboxes, 8px from their
   label and without NcCheckboxRadioSwitch's row padding. The dialog
   sheet's own checkbox rule (padding-inline 0, gap 10px) is more
   specific than a single class, hence the longer selector. */
.case-location-dialog .case-location-dialog__layers :deep(.checkbox-radio-switch-checkbox .checkbox-content) {
	gap: 8px;
	min-height: 0;
	padding: 0;
	padding-inline-start: 4px;
}

.case-location-dialog__layers :deep(.checkbox-content__text) {
	font-size: 14px;
	line-height: 1.2;
}

.case-location-dialog :deep(.select__label) {
	margin-bottom: 6px;
	font-size: 14px;
	font-weight: 600;
	line-height: 1.2;
}

.case-location-dialog__facts {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

.case-location-dialog__card {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 14px 16px;
}

.case-location-dialog__card h3 {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
	line-height: 1.2;
}

.case-location-dialog__card dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
	justify-items: start;
	margin: 0;
	padding: 0;
	font-size: 14px;
}

.case-location-dialog__card dt,
.case-location-dialog__card dd {
	text-align: start;
	width: auto;
	margin: 0;
	padding: 0;
	line-height: 1.4;
}

.case-location-dialog__card dt {
	color: var(--color-text-maxcontrast);
}

.case-location-dialog__card dd {
	font-weight: 500;
}

@media (max-width: 600px) {
	.case-location-dialog__map-row,
	.case-location-dialog__facts {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>

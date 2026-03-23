<template>
	<div class="map-layer-settings">
		<div class="map-layer-settings__header">
			<NcButton type="primary" @click="showAddDialog = true">
				{{ t('procest', 'Add layer') }}
			</NcButton>
			<NcButton @click="showPresets = !showPresets">
				{{ t('procest', 'PDOK presets') }}
			</NcButton>
		</div>

		<!-- PDOK presets dropdown -->
		<div v-if="showPresets" class="map-layer-settings__presets">
			<h4>{{ t('procest', 'Common PDOK layers') }}</h4>
			<div
				v-for="preset in pdokPresets"
				:key="preset.title"
				class="map-layer-settings__preset"
				@click="addPreset(preset)">
				<span class="map-layer-settings__preset-name">{{ preset.title }}</span>
				<span class="map-layer-settings__preset-type">{{ preset.layerType }}</span>
			</div>
		</div>

		<!-- Layer list -->
		<div v-if="layers.length === 0" class="map-layer-settings__empty">
			<p>{{ t('procest', 'No map layers configured. Add a layer or use a PDOK preset.') }}</p>
		</div>

		<table v-else class="map-layer-settings__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Title') }}</th>
					<th>{{ t('procest', 'Type') }}</th>
					<th>{{ t('procest', 'URL') }}</th>
					<th>{{ t('procest', 'Default') }}</th>
					<th>{{ t('procest', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="layer in layers" :key="layer.id">
					<td>{{ layer.title }}</td>
					<td>{{ layer.layerType }}</td>
					<td class="map-layer-settings__url">{{ truncateUrl(layer.url) }}</td>
					<td>{{ layer.isDefault ? t('procest', 'Yes') : '' }}</td>
					<td>
						<NcButton type="tertiary-no-background" @click="editLayer(layer)">
							{{ t('procest', 'Edit') }}
						</NcButton>
						<NcButton type="tertiary-no-background" @click="testLayer(layer)">
							{{ t('procest', 'Test') }}
						</NcButton>
						<NcButton type="error" @click="removeLayer(layer)">
							{{ t('procest', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Test result -->
		<NcNoteCard v-if="testResult" :type="testResult.success ? 'success' : 'error'">
			{{ testResult.message }}
			<ul v-if="testResult.layers && testResult.layers.length > 0">
				<li v-for="l in testResult.layers.slice(0, 10)" :key="l.name">
					{{ l.title || l.name }}
				</li>
			</ul>
		</NcNoteCard>

		<!-- Add/Edit dialog -->
		<div v-if="showAddDialog" class="map-layer-settings__dialog-overlay" @click.self="closeDialog">
			<div class="map-layer-settings__dialog">
				<h3>{{ editingLayer ? t('procest', 'Edit layer') : t('procest', 'Add layer') }}</h3>
				<div class="form-group">
					<label>{{ t('procest', 'Title') }} *</label>
					<NcTextField :value="form.title" @update:value="v => form.title = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Type') }} *</label>
					<NcSelect
						v-model="form.layerType"
						:options="['tile', 'wms', 'wfs', 'geojson']" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'URL') }} *</label>
					<NcTextField :value="form.url" @update:value="v => form.url = v" />
				</div>
				<div v-if="form.layerType === 'wms' || form.layerType === 'wfs'" class="form-group">
					<label>{{ t('procest', 'Layer name(s)') }}</label>
					<NcTextField :value="form.layers" @update:value="v => form.layers = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Attribution') }}</label>
					<NcTextField :value="form.attribution" @update:value="v => form.attribution = v" />
				</div>
				<div class="form-row">
					<NcCheckboxRadioSwitch :checked="form.isDefault" @update:checked="v => form.isDefault = v">
						{{ t('procest', 'Show by default') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch :checked="form.proxyEnabled" @update:checked="v => form.proxyEnabled = v">
						{{ t('procest', 'Use proxy (for CORS)') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="map-layer-settings__dialog-actions">
					<NcButton @click="closeDialog">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="saveLayer">
						{{ t('procest', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { useGisStore } from '../../store/modules/gis.js'
import { getCapabilities } from '../../services/gisProxyService.js'

const PDOK_PRESETS = [
	{
		title: 'Kadastrale kaart',
		layerType: 'wms',
		url: 'https://service.pdok.nl/kadaster/kadastralekaart/wms/v5_0',
		layers: 'Kadastralegrens,Perceelnummer',
		format: 'image/png',
		attribution: 'Kadaster',
	},
	{
		title: 'BAG',
		layerType: 'wms',
		url: 'https://service.pdok.nl/lv/bag/wms/v2_0',
		layers: 'pand',
		format: 'image/png',
		attribution: 'BAG - Kadaster',
	},
	{
		title: 'Bestemmingsplannen',
		layerType: 'wms',
		url: 'https://service.pdok.nl/omgevingswet/omgevingsdocumenten/wms/v2',
		layers: 'enkelbestemming',
		format: 'image/png',
		attribution: 'Ruimtelijkeplannen.nl',
	},
	{
		title: 'CBS Wijken en buurten',
		layerType: 'wfs',
		url: 'https://service.pdok.nl/cbs/wijkenbuurten/2023/wfs/v1_0',
		layers: 'wijken',
		attribution: 'CBS',
	},
	{
		title: 'AHN3 Hoogtekaart',
		layerType: 'wms',
		url: 'https://service.pdok.nl/rws/ahn/wms/v1_0',
		layers: 'ahn3_05m_dsm',
		format: 'image/png',
		attribution: 'AHN - Rijkswaterstaat',
	},
	{
		title: 'Natura 2000',
		layerType: 'wms',
		url: 'https://service.pdok.nl/rvo/natura2000/wms/v1_0',
		layers: 'natura2000',
		format: 'image/png',
		attribution: 'RVO',
	},
]

export default {
	name: 'MapLayerSettings',
	components: { NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcNoteCard },
	data() {
		return {
			layers: [],
			showAddDialog: false,
			showPresets: false,
			editingLayer: null,
			testResult: null,
			pdokPresets: PDOK_PRESETS,
			form: this.emptyForm(),
		}
	},
	async created() {
		const gisStore = useGisStore()
		await gisStore.fetchLayers()
		this.layers = gisStore.layers
	},
	methods: {
		emptyForm() {
			return {
				title: '',
				layerType: 'wms',
				url: '',
				layers: '',
				format: 'image/png',
				attribution: '',
				isDefault: false,
				isBaseLayer: false,
				proxyEnabled: false,
				opacity: 1.0,
				order: 0,
			}
		},

		editLayer(layer) {
			this.editingLayer = layer
			this.form = { ...layer }
			this.showAddDialog = true
		},

		async saveLayer() {
			const gisStore = useGisStore()
			if (this.editingLayer) {
				await gisStore.updateLayer(this.editingLayer.id, this.form)
			} else {
				await gisStore.createLayer(this.form)
			}
			this.layers = gisStore.layers
			this.closeDialog()
		},

		async removeLayer(layer) {
			if (!confirm(this.t('procest', 'Delete layer "{title}"?', { title: layer.title }))) {
				return
			}
			const gisStore = useGisStore()
			await gisStore.deleteLayer(layer.id)
			this.layers = gisStore.layers
		},

		addPreset(preset) {
			this.form = { ...this.emptyForm(), ...preset }
			this.showAddDialog = true
			this.showPresets = false
		},

		async testLayer(layer) {
			this.testResult = null
			try {
				const result = await getCapabilities(layer.url, layer.layerType)
				this.testResult = {
					success: true,
					message: this.t('procest', 'Connection successful — {count} layers found', { count: result.layers?.length || 0 }),
					layers: result.layers || [],
				}
			} catch {
				this.testResult = {
					success: false,
					message: this.t('procest', 'Connection failed'),
				}
			}
		},

		closeDialog() {
			this.showAddDialog = false
			this.editingLayer = null
			this.form = this.emptyForm()
		},

		truncateUrl(url) {
			if (!url) return ''
			return url.length > 60 ? url.substring(0, 57) + '...' : url
		},
	},
}
</script>

<style scoped>
.map-layer-settings__header {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.map-layer-settings__presets {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 12px;
	margin-bottom: 16px;
}

.map-layer-settings__presets h4 {
	margin: 0 0 8px;
	font-size: 13px;
}

.map-layer-settings__preset {
	display: flex;
	justify-content: space-between;
	padding: 6px 8px;
	cursor: pointer;
	border-radius: 4px;
}

.map-layer-settings__preset:hover {
	background: var(--color-background-dark);
}

.map-layer-settings__preset-type {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
}

.map-layer-settings__table {
	width: 100%;
	border-collapse: collapse;
}

.map-layer-settings__table th,
.map-layer-settings__table td {
	padding: 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.map-layer-settings__url {
	max-width: 250px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.map-layer-settings__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px;
	text-align: center;
}

.map-layer-settings__dialog-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 10000;
	background: rgba(0, 0, 0, .5);
	display: flex;
	align-items: center;
	justify-content: center;
}

.map-layer-settings__dialog {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 24px;
	width: 500px;
	max-width: 90vw;
}

.map-layer-settings__dialog h3 {
	margin: 0 0 16px;
}

.map-layer-settings__dialog .form-group {
	margin-bottom: 12px;
}

.map-layer-settings__dialog .form-group label {
	display: block;
	margin-bottom: 4px;
	font-size: 13px;
	font-weight: 500;
}

.map-layer-settings__dialog .form-row {
	display: flex;
	gap: 16px;
	margin-bottom: 12px;
}

.map-layer-settings__dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>

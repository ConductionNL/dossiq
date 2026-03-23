<template>
	<div class="map-layer-switcher">
		<h4>{{ t('procest', 'Layers') }}</h4>

		<!-- Overlay layers with toggle and opacity -->
		<div v-for="layer in layers" :key="layer.title" class="map-layer-switcher__item">
			<NcCheckboxRadioSwitch
				:checked="enabledLayers.includes(layer.title)"
				@update:checked="toggleLayer(layer)">
				{{ layer.title }}
			</NcCheckboxRadioSwitch>
			<input
				v-if="enabledLayers.includes(layer.title)"
				type="range"
				min="0"
				max="100"
				:value="(layer.opacity ?? 1) * 100"
				class="map-layer-switcher__opacity"
				:aria-label="t('procest', 'Opacity for {layer}', { layer: layer.title })"
				@input="onOpacityChange(layer, $event)">
		</div>

		<p v-if="layers.length === 0" class="map-layer-switcher__empty">
			{{ t('procest', 'No overlay layers configured') }}
		</p>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
	name: 'MapLayerSwitcher',
	components: { NcCheckboxRadioSwitch },
	props: {
		/** Array of MapLayer configuration objects. */
		layers: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['toggle', 'opacity-change'],
	data() {
		return {
			enabledLayers: [],
		}
	},
	created() {
		// Enable default layers
		this.enabledLayers = this.layers
			.filter(l => l.isDefault)
			.map(l => l.title)
	},
	methods: {
		toggleLayer(layer) {
			const idx = this.enabledLayers.indexOf(layer.title)
			if (idx >= 0) {
				this.enabledLayers.splice(idx, 1)
			} else {
				this.enabledLayers.push(layer.title)
			}
			this.$emit('toggle', { layer, enabled: this.enabledLayers.includes(layer.title) })
		},
		onOpacityChange(layer, event) {
			const opacity = parseInt(event.target.value, 10) / 100
			this.$emit('opacity-change', { layer, opacity })
		},
	},
}
</script>

<style scoped>
.map-layer-switcher {
	padding: 8px 12px;
}

.map-layer-switcher h4 {
	margin: 0 0 8px;
	font-size: 13px;
	font-weight: 600;
}

.map-layer-switcher__item {
	margin-bottom: 4px;
}

.map-layer-switcher__opacity {
	width: 100%;
	margin-top: 2px;
}

.map-layer-switcher__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>

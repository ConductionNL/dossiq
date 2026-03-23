<template>
	<div class="map-legend">
		<div
			v-for="item in items"
			:key="item.label"
			class="map-legend__item">
			<span class="map-legend__dot" :style="{ background: item.color }" />
			{{ item.label }}
		</div>
	</div>
</template>

<script>
export default {
	name: 'MapLegend',
	data() {
		return {
			items: [
				{ color: '#2196F3', label: this.t('procest', 'Active') },
				{ color: '#FF9800', label: this.t('procest', 'Near deadline') },
				{ color: '#F44336', label: this.t('procest', 'Overdue') },
				{ color: '#4CAF50', label: this.t('procest', 'Closed') },
			],
		}
	},
}
</script>

<style scoped>
.map-legend {
	position: absolute;
	bottom: 24px;
	left: 12px;
	z-index: 1000;
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 8px 12px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
	font-size: 12px;
}

.map-legend__item {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 2px 0;
}

.map-legend__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
}
</style>

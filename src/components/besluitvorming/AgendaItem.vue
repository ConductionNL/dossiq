<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -->
<template>
	<div
		class="agenda-item"
		:class="{
			'agenda-item--bespreekstuk': item.behandeling === 'bespreekstuk',
		}">
		<span
			class="agenda-item__handle"
			:title="t('procest', 'Sleep om te herordenen')"
			>⋮⋮</span
		>
		<span class="agenda-item__number">{{ item.agendanummer || '–' }}</span>
		<span class="agenda-item__title">{{
			item.title || t('procest', 'Onbenoemd voorstel')
		}}</span>
		<div class="agenda-item__toggle">
			<NcButton
				:type="item.behandeling === 'hamerstuk' ? 'primary' : 'secondary'"
				@click="setBehandeling('hamerstuk')">
				{{ t('procest', 'Hamerstuk') }}
			</NcButton>
			<NcButton
				:type="item.behandeling === 'bespreekstuk' ? 'primary' : 'secondary'"
				@click="setBehandeling('bespreekstuk')">
				{{ t('procest', 'Bespreekstuk') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'AgendaItem',
	components: { NcButton },
	props: {
		item: {
			type: Object,
			required: true,
		},
	},
	methods: {
		/**
		 * Emit a behandeling change for this item.
		 *
		 * @param {string} behandeling 'hamerstuk' | 'bespreekstuk'.
		 */
		setBehandeling(behandeling) {
			this.$emit('set-behandeling', { id: this.item.id, behandeling })
		},
	},
}
</script>

<style scoped>
.agenda-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 6px;
	background: var(--color-main-background);
}

.agenda-item--bespreekstuk {
	border-left: 4px solid var(--color-primary-element);
}

.agenda-item__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
}

.agenda-item__number {
	font-weight: bold;
	min-width: 32px;
}

.agenda-item__title {
	flex: 1;
}

.agenda-item__toggle {
	display: flex;
	gap: 6px;
}
</style>

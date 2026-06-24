<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Bezwaar & Beroep — card-grid landing page (bezwaar-beroep-cards-collapse).

 Replaces the former four-leaf nav group (Bezwaren / Beroepen /
 BezwaarDecisions / BezwaarAdviceRequests) with a single top-level menu
 item and this card-grid overview, following the ADR-044 cards-collapse
 pattern. Each card navigates to its corresponding former-leaf page route;
 all four routes remain registered and reachable as deep links (ADR-044
 hard invariant — no page is removed).

 Registered in src/registry.js as kind:"page" with component key
 "BezwaarBeroepOverview". Manifest fragment declares the route and wires
 the BezwaarBeroepGroup menu item to this page.

 @spec openspec/changes/bezwaar-beroep-cards-collapse/specs/navigation/spec.md
-->
<template>
	<div class="bezwaar-overview" data-testid="bezwaar-overview">
		<header class="bezwaar-overview__header">
			<h2 class="bezwaar-overview__title" data-testid="bezwaar-overview-title">
				{{ t('procest', 'Bezwaar & Beroep') }}
			</h2>
			<p class="bezwaar-overview__hint">
				{{ t('procest', 'Beheer bezwaren, beroepen, beslissingen en BAC-adviezen vanuit één overzicht.') }}
			</p>
		</header>

		<div class="bezwaar-overview__grid" data-testid="bezwaar-overview-grid">
			<CnCard
				v-for="card in cards"
				:key="card.id"
				:title="t('procest', card.label)"
				:description="t('procest', card.description)"
				:icon="card.icon"
				:clickable="true"
				:data-testid="`bezwaar-card-${card.id}`"
				@click="navigate(card.route)" />
		</div>
	</div>
</template>

<script>
import { CnCard } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Comment from 'vue-material-design-icons/Comment.vue'

export default {
	name: 'BezwaarBeroepOverview',

	components: {
		CnCard,
	},

	data() {
		return {
			/**
			 * Card definitions, one per former leaf.
			 * id matches the manifest page id; route matches the vue-router route name
			 * (page id === route name per the manifest contract).
			 */
			cards: [
				{
					id: 'Bezwaren',
					label: 'Bezwaren',
					description: 'Overzicht van alle bezwaarschriften die bij de gemeente zijn ingediend.',
					icon: Gavel,
					route: 'Bezwaren',
				},
				{
					id: 'Beroepen',
					label: 'Beroepen',
					description: 'Overzicht van beroepsprocedures bij de bestuursrechter.',
					icon: ScaleBalance,
					route: 'Beroepen',
				},
				{
					id: 'BezwaarDecisions',
					label: 'Beslissingen op bezwaar',
					description: 'Overzicht van beslissingen op ingediende bezwaarschriften.',
					icon: CheckCircle,
					route: 'BezwaarDecisions',
				},
				{
					id: 'BezwaarAdviceRequests',
					label: 'BAC-adviezen',
					description: 'Adviezen van de Bezwaaradviescommissie (BAC) over ingediende bezwaren.',
					icon: Comment,
					route: 'BezwaarAdviceRequests',
				},
			],
		}
	},

	methods: {
		t,

		/**
		 * Navigate to the given route name using vue-router.
		 *
		 * @param {string} routeName The vue-router route name to navigate to.
		 */
		navigate(routeName) {
			this.$router.push({ name: routeName })
		},
	},
}
</script>

<style scoped>
.bezwaar-overview {
	padding: 1rem;
}

.bezwaar-overview__header {
	margin-bottom: 1.5rem;
}

.bezwaar-overview__title {
	margin: 0 0 0.25rem 0;
}

.bezwaar-overview__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	max-width: 48rem;
}

.bezwaar-overview__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 1rem;
}
</style>

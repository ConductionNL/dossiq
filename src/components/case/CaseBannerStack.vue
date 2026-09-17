<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The case's banner strips, in one grid row instead of five.

	Favourite, Follow, New since you last looked, What this status asks for and
	Attention were five `gridWidth: 12, gridHeight: 1` widgets stacked down the
	page. Three of the five render conditionally — `CaseUnreadPanel`,
	`CaseStatusDeclarationPanel` and `CaseAttentionPanel` are each a root
	`v-if` — so an ordinary case (nothing new, nothing flagged, status fine)
	reserved three empty full-width rows and showed a gap where they were.

	🔴 WHY THAT COULD NOT BE FIXED WHERE IT SHOWED. A grid row is reserved from
	the LAYOUT, before the component renders and decides it has nothing to say,
	and GridStack positions items absolutely from `gridY`/`gridHeight` — so
	collapsing an empty item in CSS hides it without moving anything below it.
	The row has to not be in the layout, or its height has to come from its
	content. One row holding all five is the first; `sizeToContent` on that row
	(nextcloud-vue CnDashboardGrid) is the second, and this widget uses both.

	The order is the order the rows had, which is the order they are read in:
	what I marked, what I am watching, what changed, what the status wants,
	what is wrong. The star and the Follow strip sit together at the top
	because both are per-reader state, where every strip under them is about
	the case rather than about you.

	Each panel keeps its own `v-if`, its own fetch and its own tests. This adds
	no conditions of its own: it is a container, and a sixth strip belongs here
	rather than in a sixth row.

	@spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	@spec openspec/changes/case-followers/specs/case-management/spec.md
-->
<template>
	<div class="case-banner-stack" data-testid="case-banner-stack">
		<CaseFavouriteStrip :objectId="objectId" />
		<CaseFollowStrip :objectId="objectId" :objectData="objectData" />
		<CaseUnreadPanel :objectId="objectId" />
		<CaseStatusDeclarationPanel :objectId="objectId" :objectData="objectData" />
		<CaseAttentionPanel :objectId="objectId" />
	</div>
</template>

<script>
import CaseAttentionPanel from './CaseAttentionPanel.vue'
import CaseFavouriteStrip from './CaseFavouriteStrip.vue'
import CaseFollowStrip from './CaseFollowStrip.vue'
import CaseStatusDeclarationPanel from './CaseStatusDeclarationPanel.vue'
import CaseUnreadPanel from './CaseUnreadPanel.vue'

export default {
	name: 'CaseBannerStack',

	components: {
		CaseAttentionPanel,
		CaseFavouriteStrip,
		CaseFollowStrip,
		CaseStatusDeclarationPanel,
		CaseUnreadPanel,
	},

	props: {
		/** The case these strips belong to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * The loaded case record. Only the status declaration reads it; the
		 * others fetch what they need from `objectId`.
		 */
		objectData: {
			type: Object,
			default: null,
		},
	},
}
</script>

<style scoped>
/*
 * The gap is BETWEEN strips that rendered, so a panel whose `v-if` is false
 * costs nothing: `gap` only applies between boxes that exist, which `margin`
 * on each strip would not have managed.
 */
.case-banner-stack {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

/* Nothing rendered at all (no favourite, no flags) leaves no stray padding. */
.case-banner-stack:empty {
	display: none;
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section v-if="handlers.length > 0" class="workload-summary" data-testid="workload-summary">
		<h2 class="workload-summary__title">
			{{ t('dossiq', 'Team workload') }}
		</h2>
		<p class="workload-summary__hint">
			{{ t('dossiq', 'Open cases per colleague in your teams. Choose a name to see their work.') }}
		</p>
		<CnQuickFilterBar
			inline
			:tabs="tabs"
			:activeIndex="ownIndex"
			:counts="counts"
			@update:activeIndex="choose" />
	</section>
</template>

<script>
import { CnQuickFilterBar } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'

/**
 * The team's open work per colleague, as a strip of name chips with a count,
 * rendered on My Work only when GET /api/work-queue/workload succeeds (the
 * caller is a coordinator). The reader's own chip is the active one; choosing
 * another name emits `select` with that handler's user id. Renders nothing
 * when there is no data: the parent swallows a 403 for non-coordinators, so
 * this component never needs to tell "no access" from "no data".
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export default {
	name: 'WorkloadSummaryBar',

	components: { CnQuickFilterBar },

	props: {
		/** Per-handler open-case counts, e.g. [{ handler, openCaseCount, displayName? }]. */
		handlers: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['select'],

	computed: {
		/** The signed-in user's id, or '' when nobody is signed in. */
		ownUid() {
			return getCurrentUser()?.uid ?? ''
		},

		/** One chip per handler, the reader's own marked "(you)" and placed first. */
		tabs() {
			return this.ordered.map((row) => ({
				label: row.handler === this.ownUid
					? this.t('dossiq', '{name} (you)', { name: row.displayName || row.handler })
					: (row.displayName || row.handler),
			}))
		},

		/** The handlers with the reader's own row first, the rest as the server ranked them. */
		ordered() {
			const own = this.handlers.filter((row) => row.handler === this.ownUid)
			return [...own, ...this.handlers.filter((row) => row.handler !== this.ownUid)]
		},

		/** The open-case count per chip, in chip order. */
		counts() {
			return this.ordered.map((row) => row.openCaseCount || 0)
		},

		/** The reader's own chip, or null when the reader holds no open cases. */
		ownIndex() {
			return this.ordered.findIndex((row) => row.handler === this.ownUid) === 0 ? 0 : null
		},
	},

	methods: {
		/**
		 * Emit the handler behind a chosen chip.
		 *
		 * @param {number|null} index The chosen chip.
		 */
		choose(index) {
			const row = index === null ? null : this.ordered[index]
			if (row) {
				this.$emit('select', row.handler)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.workload-summary {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 16px;
}

.workload-summary__title {
	margin: 0;
	font-size: 1.15rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.workload-summary__hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}
</style>

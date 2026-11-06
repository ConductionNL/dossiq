<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section
		v-if="rows.length > 0"
		class="workload-summary"
		:class="`workload-summary--${part}`"
		data-testid="workload-summary">
		<template v-if="part !== 'chips'">
			<h2 class="workload-summary__title">
				{{ t('dossiq', 'Team workload') }}
			</h2>
			<p class="workload-summary__hint">
				{{ t('dossiq', 'Open cases per colleague in your teams. Choose a name to see their work.') }}
			</p>
		</template>
		<CnQuickFilterBar
			v-if="part !== 'heading'"
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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The workload request in flight, shared by the parts mounted together. */
let workloadRequest = null

/**
 * Read the team workload once for every part that asks while a request runs.
 *
 * @return {Promise<object>} The axios response.
 */
function readWorkload() {
	if (workloadRequest === null) {
		workloadRequest = axios.get(generateUrl('/apps/dossiq/api/work-queue/workload'))
			.finally(() => { workloadRequest = null })
	}
	return workloadRequest
}

/**
 * The team's open work per colleague, as a strip of name chips with a count,
 * on My Work's `below-header` slot. It reads GET /api/work-queue/workload
 * itself, which answers only a coordinator; anyone else gets a 403 and the
 * strip renders nothing, so it never needs to tell "no access" from "no data".
 * The reader's own chip is the active one; choosing another name emits
 * `select` and opens that colleague's open cases on the Cases page.
 *
 * The chips are the strip's own rather than the index page's quick filters:
 * the names are only known at runtime, only a coordinator sees them, and a
 * choice leaves this page for the Cases list.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export default {
	name: 'WorkloadSummaryBar',

	components: { CnQuickFilterBar },

	props: {
		/**
		 * Per-handler open-case counts, e.g. [{ handler, openCaseCount, displayName? }].
		 * When not given the strip reads them from the workload endpoint.
		 */
		handlers: {
			type: Array,
			default: null,
		},

		/**
		 * What the strip draws: `all` (heading and chips), `heading` (title
		 * and hint, under the page header) or `chips` (the name chips, at the
		 * start of the toolbar row, board DqAanMijToegewezen).
		 */
		part: {
			type: String,
			default: 'all',
			validator: (value) => ['all', 'heading', 'chips'].includes(value),
		},
	},

	emits: ['select'],

	data() {
		return {
			/** The workload the strip read itself. */
			loaded: [],
		}
	},

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

		/** The workload shown: the prop when given, else what the strip read. */
		rows() {
			return Array.isArray(this.handlers) ? this.handlers : this.loaded
		},

		/** The handlers with the reader's own row first, the rest as the server ranked them. */
		ordered() {
			const own = this.rows.filter((row) => row.handler === this.ownUid)
			return [...own, ...this.rows.filter((row) => row.handler !== this.ownUid)]
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

	mounted() {
		if (!Array.isArray(this.handlers)) {
			this.fetchWorkload()
		}
	},

	methods: {
		/**
		 * Read the team workload; a refusal (not a coordinator) or a failure
		 * leaves the strip empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		async fetchWorkload() {
			try {
				const response = await readWorkload()
				this.loaded = (response.data && response.data.handlers) || []
			} catch {
				this.loaded = []
			}
		},

		/**
		 * Emit the handler behind a chosen chip and open a colleague's open
		 * cases; the reader's own chip stays on this page.
		 *
		 * @param {number|null} index The chosen chip.
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		choose(index) {
			const row = index === null ? null : this.ordered[index]
			if (!row) {
				return
			}
			this.$emit('select', row.handler)
			if (row.handler === this.ownUid || !this.$router) {
				return
			}
			this.$router.push({
				name: 'Cases',
				query: { assignee: row.handler, isFinalStatus: 'false', statusHiddenInLists: 'false', isDraft: 'false' },
			})
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

/* Board DqAanMijToegewezen: an 18px title over a 14px hint, 2px apart, on the
   normal line height; the page's gap is the only space below it. */
.workload-summary--heading {
	margin-bottom: 0;
	gap: 2px;

	.workload-summary__title {
		font-size: 18px;
		line-height: normal;
	}

	.workload-summary__hint {
		margin: 0;
		font-size: 14px;
		line-height: normal;
	}
}

.workload-summary--chips {
	margin-bottom: 0;
}
</style>

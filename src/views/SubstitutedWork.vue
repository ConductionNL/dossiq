<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<!-- Work routed here by an active substitution, above the reader's own
	     list on My Work (`before-collection` slot) and marked with whose it
	     is. A group of its own because the list below is the index page's:
	     these cases are not assigned to the reader, so no filter on that list
	     could hold them. -->
	<section
		v-if="cases.length"
		class="substituted-work"
		data-testid="substituted-work">
		<div class="substituted-work__head">
			<h3 class="substituted-work__title">
				{{ t('dossiq', 'Work you are standing in for') }}
			</h3>
			<NcButton
				variant="tertiary"
				data-testid="substituted-toggle"
				:aria-pressed="String(show)"
				@click="toggle">
				{{ show ? t('dossiq', 'Hide substituted work') : t('dossiq', 'Show substituted work') }}
			</NcButton>
		</div>
		<ul v-if="visible.length" class="substituted-work__grid">
			<li v-for="row in visible" :key="row.id">
				<button
					type="button"
					class="substituted-work__card"
					:data-testid="`substituted-card-${row.id}`"
					@click="open(row)">
					<span class="substituted-work__card-title">{{ row.title || row.identifier || t('dossiq', 'Untitled case') }}</span>
					<span class="substituted-work__meta">
						<span v-if="row.identifier" class="substituted-work__ref">{{ row.identifier }}</span>
						<span v-if="nameOf('caseTypeName', row.caseType)" class="substituted-work__chip" :title="nameOf('caseTypeName', row.caseType)">{{ nameOf('caseTypeName', row.caseType) }}</span>
						<span v-if="nameOf('statusTypeName', row.status)" class="substituted-work__chip">{{ nameOf('statusTypeName', row.status) }}</span>
						<span class="substituted-work__chip substituted-work__chip--marker" data-testid="substituted-marker">{{ markerOf(row) }}</span>
					</span>
				</button>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import formatters from '../services/formatters.js'
import { fetchSubstitutedWork } from '../services/substitutionApi.js'
import {
	applySubstitutedFilter,
	asSubstitutedItems,
	buildSubstitutedMap,
	readShowSubstituted,
	substitutedFor,
	substitutedUntil,
	writeShowSubstituted,
} from '../utils/substitutionHelpers.js'

/**
 * The cases an active substitution routes to the reader, on My Work.
 *
 * The group is hideable: the toggle is remembered per browser, and while it
 * is off the heading and its way back stay. A refused or failed read shows no
 * group, and the reader's own list below is untouched.
 *
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export default {
	name: 'SubstitutedWork',

	components: { NcButton },

	data() {
		return {
			/** Open cases routed here by an active substitution. */
			cases: [],
			/** `case:<id>` -> the routing context, for the marker and the filter. */
			routing: {},
			/** Whether the group's cases are listed; remembered per browser. */
			show: readShowSubstituted(),
		}
	},

	computed: {
		/**
		 * The cases listed: none while the toggle is off.
		 *
		 * @return {Array<object>}
		 */
		visible() {
			return applySubstitutedFilter(this.cases, this.routing, this.show)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the substituted work.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		async load() {
			try {
				const work = await fetchSubstitutedWork()
				this.routing = buildSubstitutedMap(work.cases, work.tasks)
				this.cases = asSubstitutedItems(work.cases, 'case')
			} catch {
				this.cases = []
				this.routing = {}
			}
		},

		/**
		 * Show or hide the group's cases, and remember it.
		 *
		 * @return {void}
		 */
		toggle() {
			this.show = !this.show
			writeShowSubstituted(this.show)
		},

		/**
		 * Whose case this is, and until when.
		 *
		 * @param {object} row The case.
		 * @return {string} The marker.
		 */
		markerOf(row) {
			const name = substitutedFor(this.routing, row)
			const until = substitutedUntil(this.routing, row)
			if (!until) {
				return this.t('dossiq', 'for {name}', { name })
			}
			return this.t('dossiq', 'for {name}, until {date}', { name, date: until })
		},

		/**
		 * A reference's name through dossiq's cell formatter, or ''.
		 *
		 * @param {string} formatter The formatter id.
		 * @param {string} value The reference.
		 * @return {string}
		 */
		nameOf(formatter, value) {
			if (!value) {
				return ''
			}
			const name = formatters[formatter](value)
			return name && name !== value && name !== '—' ? String(name) : ''
		},

		/**
		 * Open the case.
		 *
		 * @param {object} row The case.
		 * @return {void}
		 */
		open(row) {
			const id = row.id || (row['@self'] && row['@self'].id)
			if (id && this.$router) {
				this.$router.push({ name: 'CaseDetail', params: { id: String(id) } })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.substituted-work {
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.substituted-work__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.substituted-work__title {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.substituted-work__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 12px;
	margin: 12px 0 0;
	padding: 0;
	list-style: none;
}

.substituted-work__card {
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
	height: 100%;
	padding: 16px;
	text-align: start;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		background: var(--color-background-hover);
	}
}

.substituted-work__card-title {
	font-weight: 600;
	color: var(--color-main-text);
}

.substituted-work__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.substituted-work__ref {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.substituted-work__chip {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 0.8rem;
	white-space: nowrap;

	&--marker {
		background: var(--color-warning-element-light, var(--color-background-dark));
	}
}
</style>

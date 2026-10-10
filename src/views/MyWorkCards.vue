<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - NOTE ON `register="dossiq"` BELOW — it is deliberately NOT `dossiq`.
  - That value is the OpenRegister register SLUG, not this app's id. OpenRegister
  - resolves a register by slug, so renaming it alongside the procest -> dossiq
  - app-id rename would point this page at a register that does not exist and
  - orphan every stored case. The failure is silent: the page still renders and
  - simply shows no work. The l10n domain and the /apps/… URLs on the same
  - component DO move, because those name this app to Nextcloud.
-->
<template>
	<!-- Two lists behind one sort toggle. Urgency renders the work queue the
	     server ranked (`listMode` 'ranked'): CnIndexPage cannot reorder rows it
	     fetched itself, so the order has to arrive in the rows. Newest, and
	     Urgency when the queue failed, keep the self-fetch ('self'). The
	     `:key` remounts the index when the mode flips, because CnIndexPage
	     decides between self-fetch and handed-in rows once, at setup. -->
	<CnIndexPage
		:key="listMode"
		:title="t('dossiq', 'Assigned to me')"
		:showTitle="true"
		:headerButtons="headerButtons"
		:countText="t('dossiq', '{total} cases in your name, most urgent first')"
		:footerNote="
			t(
				'dossiq',
				'Urgency follows from the deadline, the priority and how long a case has been idle.',
			)
		"
		v-bind="listBinding"
		:filter="filter"
		viewMode="cards"
		:viewModes="['cards', 'table']"
		:columns="columns"
		:sidebar="sidebar"
		:showViewAction="false"
		:rowClickToView="true"
		:excludeFields="caseForm.excludeFields"
		:includeFields="caseForm.includeFields"
		:fieldOverrides="caseForm.fieldOverrides"
		:createDefaults="caseForm.createDefaults"
		:formSize="caseForm.formSize"
		:formColumns="caseForm.formColumns"
		:createSuccessRoute="caseForm.createSuccessRoute"
		:createSuccessMessage="caseForm.createSuccessMessage"
		editOpensDetail
		@view="openCase"
		@rowClick="openCase"
		@editOpen="openCase"
		@search="onRankedSearch"
		@filterChange="onRankedFilter"
		@pageChanged="onRankedPage"
		@clearFilters="onRankedClear">
		<template #below-header>
			<WorkloadSummaryBar :handlers="workloadHandlers" />
			<div
				class="mywork-sort-toggle"
				role="group"
				:aria-label="t('dossiq', 'Sort My Work')">
				<NcButton
					:variant="sortMode === 'urgency' ? 'primary' : 'tertiary'"
					@click="setSortMode('urgency')">
					{{ t('dossiq', 'Urgency') }}
				</NcButton>
				<NcButton
					:variant="sortMode === 'newest' ? 'primary' : 'tertiary'"
					@click="setSortMode('newest')">
					{{ t('dossiq', 'Newest') }}
				</NcButton>
			</div>
			<p
				v-if="urgencyFellBack"
				class="mywork-fallback"
				data-testid="urgency-fallback">
				{{
					t(
						'dossiq',
						'Ordered by deadline: the urgency could not be computed.',
					)
				}}
			</p>
		</template>
		<!-- Work routed here by an active substitution, above the reader's own
		     list and marked with whose it is. It is a group of its own because
		     the list below is self-fetched by CnIndexPage from OpenRegister:
		     handing it rows through `:objects` would take the facet sidebar and
		     the server-side search with it. -->
		<template #before-collection>
			<section
				v-if="substitutedCases.length"
				class="mywork-substituted"
				data-testid="substituted-work">
				<div class="mywork-substituted__head">
					<h3 class="mywork-substituted__title">
						{{ t('dossiq', 'Work you are standing in for') }}
					</h3>
					<NcButton
						variant="tertiary"
						data-testid="substituted-toggle"
						:aria-pressed="String(showSubstituted)"
						@click="toggleSubstituted">
						{{
							showSubstituted
								? t('dossiq', 'Hide substituted work')
								: t('dossiq', 'Show substituted work')
						}}
					</NcButton>
				</div>
				<div
					v-if="visibleSubstitutedCases.length"
					class="mywork-substituted__grid">
					<MyWorkCaseCard
						v-for="row in visibleSubstitutedCases"
						:key="row.id"
						:object="row"
						:caseTypeMap="caseTypeMap"
						:statusMap="statusMap"
						:urgencyMap="urgencyMap"
						:substitutedFor="absenteeOf(row)"
						:substitutedUntil="untilOf(row)"
						@open="openCase" />
				</div>
			</section>
		</template>
		<!-- Custom card so case-type + status render as names, not raw UUIDs
		     (card view does not apply column formatters). -->
		<template #card="{ object, selected }">
			<MyWorkCaseCard
				:object="object"
				:selected="selected"
				:caseTypeMap="caseTypeMap"
				:statusMap="statusMap"
				:urgencyMap="urgencyMap"
				@open="openCase" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import MyWorkCaseCard from './MyWorkCaseCard.vue'
import WorkloadSummaryBar from './WorkloadSummaryBar.vue'
import { fetchSubstitutedWork } from '../services/substitutionApi.js'
import { useObjectStore } from '../store/modules/object.js'
import { initializeStores } from '../store/store.js'
import { caseCreateFormProps } from '../utils/caseCreateForm.js'
import {
	applySubstitutedFilter,
	asSubstitutedItems,
	buildSubstitutedMap,
	readShowSubstituted,
	substitutedFor,
	substitutedUntil,
	writeShowSubstituted,
} from '../utils/substitutionHelpers.js'
import {
	buildUrgencyMap,
	filterRankedRows,
	pageOfRows,
	rankedCaseRows,
	resolveListMode,
	resolveSortConfig,
} from '../utils/workQueueHelpers.js'

/** Cards per page in the ranked list. */
const RANKED_PAGE_SIZE = 20

/**
 * My Work — the current user's assigned cases, rendered as a standard
 * CnIndexPage card list. A thin wrapper (rather than a bare manifest
 * `type: index` page) because the stock index base-filter resolves only
 * `@route.*` tokens, not the `@me` current-user token; here we inject the
 * resolved uid into the `assignee` filter so the same self-fetch index path
 * scopes to the signed-in user.
 */
export default {
	name: 'MyWorkCards',

	components: { CnIndexPage, MyWorkCaseCard, WorkloadSummaryBar, NcButton },

	data() {
		return {
			/** { caseTypeUuid: humanName } for the card's Case type chip. */
			caseTypeMap: {},
			/** { statusTypeUuid: humanName } for the card's Status chip. */
			statusMap: {},
			/** 'urgency' (default) or 'newest' — drives the sort toggle. */
			sortMode: 'urgency',
			/** { caseId: { deadlineTier, score, daysUntilDeadline } } from GET /api/work-queue. */
			urgencyMap: {},
			/** The work queue: 'loading', 'ready' or 'failed'. */
			queueState: 'loading',
			/** The reader's open cases, highest score first. */
			rankedRows: [],
			/** The case schema object, so the ranked list keeps its filter sidebar. */
			caseSchema: null,
			/** The ranked list's search term, filters and page. */
			rankedSearch: '',
			rankedFilters: {},
			rankedPage: 1,
			/**
			 * Per-handler open-case counts from GET /api/work-queue/workload.
			 * Stays empty (no error UI) for non-coordinators, who get a 403.
			 */
			workloadHandlers: [],
			/** Open cases routed here by an active substitution. */
			substitutedCases: [],
			/** `case:<id>` -> the routing context, for the marker and the filter. */
			substitutedMap: {},
			/** Whether the substituted group is shown; remembered per browser. */
			showSubstituted: readShowSubstituted(),
		}
	},

	computed: {
		/**
		 * The header buttons of the board: Download, Actions, New case.
		 *
		 * @return {Array<object>} CnIndexPage headerButtons.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		headerButtons() {
			return [
				{
					action: 'export',
					label: this.t('dossiq', 'Download'),
					icon: 'TrayArrowDown',
				},
				{ action: 'actions-menu', label: this.t('dossiq', 'Actions') },
				{
					action: 'add',
					label: this.t('dossiq', 'New case'),
					variant: 'primary',
					icon: 'Plus',
				},
			]
		},

		/**
		 * CnIndexPage sortKey/sortOrder for the active sort mode.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		sortConfig() {
			return resolveSortConfig(this.sortMode)
		},

		/**
		 * Which list renders: the ranked work queue or the self-fetch.
		 *
		 * @return {string} 'ranked' or 'self'.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		listMode() {
			return resolveListMode(this.sortMode, this.queueState)
		},

		/**
		 * Whether Urgency had to fall back to the deadline order.
		 *
		 * @return {boolean} True when the queue failed while Urgency is chosen.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		urgencyFellBack() {
			return this.sortMode === 'urgency' && this.queueState === 'failed'
		},

		/**
		 * Field filters a link carries in its query (`?title=...`), which the
		 * self-fetch applies too, so a link narrows both lists alike. Keys
		 * starting with `_` are the index's own state, not filters.
		 *
		 * @return {{[key: string]: Array<string>}} The filters.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		routeFilters() {
			const query = (this.$route && this.$route.query) || {}
			const filters = {}
			for (const [key, value] of Object.entries(query)) {
				if (
					key.startsWith('_')
					|| value === null
					|| value === undefined
					|| value === ''
				) {
					continue
				}
				filters[key] = (Array.isArray(value) ? value : [value]).map(String)
			}
			return filters
		},

		/**
		 * The ranked rows after search and filters, one page of them.
		 *
		 * @return {{rows: Array<object>, pagination: object}} The page.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		rankedPageView() {
			return pageOfRows(
				filterRankedRows(this.rankedRows, this.rankedSearch, {
					...this.routeFilters,
					...this.rankedFilters,
				}),
				this.rankedPage,
				RANKED_PAGE_SIZE,
			)
		},

		/**
		 * The props that differ between the two lists.
		 *
		 * The self-fetch gets the register and schema slugs and a sort key.
		 * The ranked list gets the rows and NO register, because a register
		 * and a schema slug together switch CnIndexPage to self-fetch; it gets
		 * the schema as an object instead, which keeps the filter sidebar.
		 *
		 * @return {object} Props for CnIndexPage.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		listBinding() {
			if (this.listMode === 'self') {
				return {
					register: 'dossiq',
					schema: 'case',
					sortKey: this.sortConfig.key,
					sortOrder: this.sortConfig.order,
				}
			}
			return {
				schema: this.caseSchema,
				objects: this.rankedPageView.rows,
				pagination: this.rankedPageView.pagination,
				loading: this.queueState === 'loading',
				searchValue: this.rankedSearch,
				activeFilters: this.rankedFilters,
			}
		},

		/**
		 * Base filter scoping the case list to the current user's assignments.
		 *
		 * `statusHiddenInLists: false` is the second condition, and it is the
		 * one a reader would otherwise notice as a disagreement. A status an
		 * administrator marks hidden drops out of the Cases index; My Work read
		 * the same cases without it, so the same case was gone from one list and
		 * present in the other with nothing on either page to say why. Search
		 * and the case's own page are untouched: hidden means out of the working
		 * list, not unfindable.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
		 */
		filter() {
			const uid = (getCurrentUser() && getCurrentUser().uid) || ''
			return { assignee: uid, statusHiddenInLists: false, isDraft: false }
		},

		/**
		 * Curated card/table columns, mirroring the Cases index.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		columns() {
			return [
				'identifier',
				'title',
				{
					key: 'caseType',
					label: this.t('dossiq', 'Case type'),
					formatter: 'caseTypeName',
				},
				{
					key: 'status',
					label: this.t('dossiq', 'Status'),
					formatter: 'statusTypeName',
				},
				'deadline',
			]
		},

		/**
		 * Enable the embedded filter/search sidebar (search box + per-field facet
		 * filters derived from the case schema), mirroring the Cases index so
		 * users can narrow their assigned cases by status, case type, priority,
		 * etc. Metadata column group is hidden to keep it focused.
		 */
		sidebar() {
			return { enabled: true, showMetadata: false }
		},

		/**
		 * The shared case create-form definition, so filing a case here asks
		 * what the Dashboard's New case button asks.
		 *
		 * @spec openspec/specs/friendly-case-create-form/spec.md
		 */
		caseForm() {
			return caseCreateFormProps()
		},

		/**
		 * The substituted cases the reader currently sees.
		 *
		 * Empty while the toggle is off, which is what "hideable" means here:
		 * the rows are still fetched and still counted in the group's own
		 * heading, they are simply not listed.
		 *
		 * @return {Array<object>} The cards to render.
		 *
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		visibleSubstitutedCases() {
			return applySubstitutedFilter(
				this.substitutedCases,
				this.substitutedMap,
				this.showSubstituted,
			)
		},
	},

	/**
	 * Load the caseType / statusType collections up front and build UUID→name
	 * maps so the cards show human names (card view does not apply the column
	 * formatters, and the lazy formatter self-load is unreliable through a
	 * scoped-slot child's computed).
	 *
	 * @return {Promise<void>} Resolves once the name maps are built; the three
	 *   loads after them are deliberately not awaited.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	async mounted() {
		await initializeStores()
		const store = useObjectStore()
		try {
			const [caseTypes, statuses] = await Promise.all([
				store.fetchCollection('caseType', { _limit: 200 }),
				store.fetchCollection('statusType', { _limit: 200 }),
			])
			this.caseTypeMap = this.buildNameMap(caseTypes)
			this.statusMap = this.buildNameMap(statuses)
		} catch {
			// Names simply fall back to hidden chips; never block the list.
		}

		// Urgency chips, coordinator workload and substituted work never block
		// the list rendering.
		this.fetchWorkQueue()
		this.fetchCaseSchema(store)
		this.fetchWorkload()
		this.loadSubstitutedWork()
	},

	methods: {
		/**
		 * Build a UUID→name map from an OpenRegister collection.
		 *
		 * @param {Array<object>} collection The fetched objects.
		 * @return {{[key: string]: string}} id to title or name.
		 */
		buildNameMap(collection) {
			const map = {}
			for (const o of collection || []) {
				const id = o.id || (o['@self'] && o['@self'].id)
				if (id) {
					map[id] = o.title || o.name || String(id)
				}
			}
			return map
		},

		/**
		 * Fetch the current user's ranked work queue: the pill map, and the
		 * ranked case rows the Urgency list renders. A failure moves Urgency
		 * to the self-fetching list ordered by deadline, and says so.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		async fetchWorkQueue() {
			try {
				const response = await axios.get(
					generateUrl('/apps/dossiq/api/work-queue'),
				)
				const items = (response.data && response.data.items) || []
				this.urgencyMap = buildUrgencyMap(items)
				this.rankedRows = rankedCaseRows(items)
				this.queueState = 'ready'
			} catch {
				this.queueState = 'failed'
			}
		},

		/**
		 * Load the case schema, so the ranked list's sidebar offers the same
		 * filters as the self-fetching one.
		 *
		 * @param {object} store The object store.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		async fetchCaseSchema(store) {
			try {
				this.caseSchema = (await store.fetchSchema('case')) || null
			} catch {
				this.caseSchema = null
			}
		},

		/**
		 * The ranked list's search box.
		 *
		 * @param {string} value The search term.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		onRankedSearch(value) {
			if (this.listMode !== 'ranked') {
				return
			}
			this.rankedSearch = value || ''
			this.rankedPage = 1
		},

		/**
		 * The ranked list's sidebar filters.
		 *
		 * @param {{key: string, values: unknown}} payload The changed filter.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		onRankedFilter(payload) {
			if (this.listMode !== 'ranked' || !payload || !payload.key) {
				return
			}
			this.rankedFilters = {
				...this.rankedFilters,
				[payload.key]: payload.values,
			}
			this.rankedPage = 1
		},

		/**
		 * The ranked list's Clear all: search and every filter.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		onRankedClear() {
			if (this.listMode !== 'ranked') {
				return
			}
			this.rankedSearch = ''
			this.rankedFilters = {}
			this.rankedPage = 1
		},

		/**
		 * The ranked list's page.
		 *
		 * @param {number} page The 1-based page.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		onRankedPage(page) {
			if (this.listMode === 'ranked') {
				this.rankedPage = Number(page) || 1
			}
		},

		/**
		 * Fetch the coordinator workload summary. A 403 (non-coordinator) is
		 * expected and silently swallowed — no error UI, no summary rendered.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		async fetchWorkload() {
			try {
				const response = await axios.get(
					generateUrl('/apps/dossiq/api/work-queue/workload'),
				)
				this.workloadHandlers =
					(response.data && response.data.handlers) || []
			} catch {
				this.workloadHandlers = []
			}
		},

		/**
		 * Fetch the work an active substitution routes to the signed-in user.
		 *
		 * The resolver already applied the substitution scope and the reader's
		 * own OpenRegister permissions, so what comes back is exactly what may
		 * be shown. A failure leaves the group absent rather than emptying the
		 * page: this is work added to the list, never work the list depends on.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		async loadSubstitutedWork() {
			try {
				const work = await fetchSubstitutedWork()
				this.substitutedMap = buildSubstitutedMap(work.cases, work.tasks)
				this.substitutedCases = asSubstitutedItems(work.cases, 'case')
			} catch {
				this.substitutedCases = []
				this.substitutedMap = {}
			}
		},

		/**
		 * The absentee one substituted card stands in for.
		 *
		 * @param {object} row A substituted case row.
		 * @return {string} The absentee's user id, or ''.
		 *
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		absenteeOf(row) {
			return substitutedFor(this.substitutedMap, row)
		},

		/**
		 * The day one substituted card stops being routed here.
		 *
		 * @param {object} row A substituted case row.
		 * @return {string} The end date, or ''.
		 *
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		untilOf(row) {
			return substitutedUntil(this.substitutedMap, row)
		},

		/**
		 * Show or hide the substituted group, and remember which.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
		 */
		toggleSubstituted() {
			this.showSubstituted = !this.showSubstituted
			writeShowSubstituted(this.showSubstituted)
		},

		/**
		 * Switch the active sort mode.
		 *
		 * @param {string} mode 'urgency' or 'newest'.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		setSortMode(mode) {
			this.sortMode = mode
		},

		/**
		 * Open a case detail page from a clicked row/card.
		 *
		 * @param {object} row The case object emitted by CnIndexPage.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		openCase(row) {
			const id =
				(row && (row.id || row.uuid))
				|| (row && row['@self'] && row['@self'].id)
			if (id) {
				this.$router.push({ name: 'CaseDetail', params: { id: String(id) } })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mywork-sort-toggle {
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}

.mywork-fallback {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.mywork-substituted {
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.mywork-substituted__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.mywork-substituted__title {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.mywork-substituted__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 12px;
	margin-top: 12px;
}
</style>

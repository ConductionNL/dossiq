<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The Related cases tab, with the follow-ups that do not exist yet.

  A follow-up may be a SERIES: one flow that opens a case every quarter or every
  year until its end. A series row says how often it comes back, when the next
  one is due, and how many cases it has opened already, and it carries the one
  gesture a series needs that a single follow-up does not: stop it. Stopping
  leaves the cases it already opened alone, because somebody is working on
  them.

  A planned follow-up is a scheduled flow, not a case, so the `related` widget
  cannot list it: it reads related OBJECTS. This wraps the library's widget and
  hands it the planned rows as an `extraSections` group, so a handler sees what
  is related to this case and what is about to be, in one place, and the built
  in related content is unchanged.

  WHY THE WIDGET DECLARES ITS OWN TYPE RATHER THAN `custom`. This is a child of
  the `case-panels` tabs widget. A `type: "custom"` widget resolves through the
  page's `widget-<id>` slot, and CnDetailPage renders one of those per LAYOUT
  grid item; a tab child is deliberately absent from `layout`, because the tabs
  widget renders it and a layout entry would render it twice. CnTabsWidget
  dispatches its children through CnDetailWidgetHost, which picks a renderer
  from `cnRegistry[widget.type]` and, failing that, renders NOTHING and logs
  nothing. So the manifest names the registry key as the TYPE, exactly as
  `case-task-pane` beside it does.

  Deleted the day OpenRegister's `related` widget can include scheduled flows
  by subject (tasks 3.3).

  @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
-->
<template>
	<div class="case-planned">
		<CnRelatedObjectsWidget
			bare
			:objectId="objectId"
			:objectData="objectData"
			:objectType="objectType"
			:register="register"
			:schema="schema"
			:store="store"
			:extraSections="extraSections" />

		<ul v-if="series.length > 0" class="case-planned__series" data-testid="case-planned-series">
			<li
				v-for="row in series"
				:key="row.key"
				class="case-planned__row"
				:data-testid="`case-planned-series-${row.key}`">
				<div class="case-planned__row-body">
					<span class="case-planned__label">{{ row.label }}</span>
					<ul v-if="row.occurrences.length > 0" class="case-planned__occurrences">
						<li
							v-for="occurrence in row.occurrences"
							:key="occurrence.id"
							:data-testid="`case-planned-occurrence-${occurrence.id}`">
							<a :href="caseLink(occurrence.id)">{{ occurrence.title || occurrence.id }}</a>
						</li>
					</ul>
				</div>
				<NcButton
					:data-testid="`case-planned-stop-${row.key}`"
					:disabled="stopping === row.key"
					@click="stop(row.key)">
					{{ t('dossiq', 'Stop series') }}
				</NcButton>
			</li>
		</ul>

		<p
			v-if="stopError"
			class="case-planned__error"
			data-testid="case-planned-error"
			role="alert">
			{{ stopError }}
		</p>

		<div class="case-planned__footer">
			<NcButton data-testid="case-planned-plan" @click="planning = true">
				<template #icon>
					<CalendarClock :size="20" />
				</template>
				{{ t('dossiq', 'Plan follow-up') }}
			</NcButton>
		</div>

		<CasePlanFollowUpDialog
			v-if="planning"
			:caseId="objectId"
			@close="onPlanned" />
	</div>
</template>

<script>
import { CnRelatedObjectsWidget } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CasePlanFollowUpDialog from '../../dialogs/CasePlanFollowUpDialog.vue'
import {
	caseActionRefusal,
	plannedRows,
} from '../../utils/caseActionsHelpers.js'

export default {
	name: 'CasePlannedWidget',

	components: {
		CalendarClock,
		CasePlanFollowUpDialog,
		CnRelatedObjectsWidget,
		NcButton,
	},

	props: {
		/** The case this tab belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The loaded case, or null while it is still being fetched. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The resolved object-type slug. */
		objectType: {
			type: String,
			default: '',
		},

		/** OpenRegister register slug of the surface. */
		register: {
			type: [String, Object],
			default: '',
		},

		/** OpenRegister schema slug of the surface. */
		schema: {
			type: [String, Object],
			default: '',
		},

		/** The effective object store. */
		store: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			planned: [],
			planning: false,
			stopping: '',
			stopError: '',
		}
	},

	computed: {
		/**
		 * The planned follow-ups, as a section the library's widget renders.
		 *
		 * An empty `items` array renders no heading at all, so a case with no
		 * planned follow-up looks exactly as it did before this widget existed.
		 *
		 * @return {Array} The extra sections.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		extraSections() {
			return [
				{
					key: 'planned',
					label: t('dossiq', 'Planned cases'),
					icon: 'CalendarClock',
					items: this.rows,
				},
			]
		},

		/**
		 * Every planned follow-up of this case, single and series alike.
		 *
		 * @return {Array} The rows.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		rows() {
			return plannedRows(this.planned, (s) => t('dossiq', s))
		},

		/**
		 * The rows that repeat, which are the only ones Stop series applies to.
		 *
		 * @return {Array} The series rows.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		series() {
			return this.rows.filter((row) => row.recurrence !== 'none')
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Reload when the tab is bound to a different case.
			 *
			 * @return {void} Nothing.
			 * @spec openspec/specs/workflow-definition-engine/spec.md
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the follow-ups planned for this case that have not fired yet.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		async load() {
			const id = String(this.objectId ?? '')
			if (id === '') {
				this.planned = []
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(id)}/planned`,
					),
				)
				this.planned = Array.isArray(data?.results) ? data.results : []
			} catch (error) {
				// A read that fails leaves the related content alone. The planned
				// group is additive, and hiding the whole tab because one extra
				// section could not load would cost more than it saves.
				this.planned = []

				// But it does not fail QUIETLY. The bare `catch` this replaces
				// made a failed read and a case with nothing planned the same
				// observation: an empty group, an empty console, and no way to
				// tell them apart from the page. That cost real time — the
				// endpoint was answering 200 with the row while the tab showed
				// none, and the first thing anyone had to rule out was a read
				// that had silently thrown.
				//
				// The disable, and the console, follow `CaseNotesTab.vue`: the
				// app has no logger of its own, and a swallowed read is worse
				// than a lint exception.
				// eslint-disable-next-line no-console
				console.error(
					`[CasePlannedWidget] could not read the planned follow-ups for case ${id}`,
					error,
				)
			}
		},

		/**
		 * Close the dialog and pick up whatever it planned.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		async onPlanned() {
			this.planning = false
			await this.load()
		},

		/**
		 * The page one case a series opened lives on.
		 *
		 * @param {string} id The case id.
		 * @return {string} The link.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		caseLink(id) {
			return generateUrl(`/apps/dossiq/cases/${encodeURIComponent(id)}`)
		},

		/**
		 * Stop one series, leaving the cases it already opened alone.
		 *
		 * @param {string} flowId The series flow's id.
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		async stop(flowId) {
			const id = String(this.objectId ?? '')
			if (id === '' || this.stopping !== '') {
				return
			}
			this.stopping = flowId
			this.stopError = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(id)}/planned/${encodeURIComponent(flowId)}/stop`,
					),
				)
				await this.load()
			} catch (error) {
				this.stopError = caseActionRefusal(
					error?.response?.data ?? {},
					(s) => t('dossiq', s),
				)
			} finally {
				this.stopping = ''
			}
		},
	},
}
</script>

<style scoped>
.case-planned {
	display: flex;
	flex-direction: column;
	gap: 8px;
	height: 100%;
}

.case-planned__footer {
	display: flex;
	justify-content: flex-end;
}

.case-planned__series {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-planned__row {
	align-items: flex-start;
	display: flex;
	gap: 8px;
	justify-content: space-between;
}

.case-planned__row-body {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.case-planned__label {
	font-weight: bold;
}

.case-planned__occurrences {
	color: var(--color-text-maxcontrast);
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-planned__error {
	color: var(--color-error);
	margin: 0;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The Related cases tab, with the follow-ups that do not exist yet.

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
  `dossier-tab` and `case-task-pane` beside it do.

  Deleted the day OpenRegister's `related` widget can include scheduled flows
  by subject (tasks 3.3).

  @spec openspec/specs/workflow-definition-engine/spec.md
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
import { plannedRows } from '../../utils/caseActionsHelpers.js'

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
					items: plannedRows(this.planned, (s) => t('dossiq', s)),
				},
			]
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
		 * @spec openspec/specs/workflow-definition-engine/spec.md
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
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		async onPlanned() {
			this.planning = false
			await this.load()
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
</style>

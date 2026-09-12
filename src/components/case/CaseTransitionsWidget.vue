<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The transition buttons in the case page's header, left of Edit.

  Mounted through the page's `actionsComponent` (manifest `pages[].actionsComponent`),
  which CnPageRenderer maps onto CnDetailPage's `actions` slot. It used to be a
  grid widget under the KPI row: a status chip plus the buttons, two rows below
  the title, repeating the status the Status card beside it already shows. The
  buttons are the one thing on the page a handler reaches for mid call, so they
  sit with the page's other actions now, and the chip is gone.

  WHY THIS IS A COMPONENT AND NOT `lifecycleActions`. CnLifecycleActions is
  server-driven: with no declared transitions it asks OpenRegister's
  /available-actions and renders exactly what comes back. For `case` that is
  nothing, because a case's status is a $ref to a per-caseType `statusType`
  row and OpenRegister's lifecycle is anchored on an enum field. Its graph
  mode (fk-graph-lifecycle-transitions) does ship, but derives the moves from
  sibling ORDER alone: it cannot express the workflow template's named,
  role-filtered, guarded transitions, cannot report which guard refused, and
  cannot ask for the result a closing case needs. All three are requirements
  here, so this widget reads dossiq's own engine instead. When the platform
  can serve them, this file is deleted and `lifecycleActions` is declared
  again (tasks 4.4).

  @spec openspec/specs/status-transition-engine/spec.md
-->
<template>
	<div class="case-transitions" data-testid="case-transitions">
		<NcLoadingIcon v-if="loading" :size="24" />

		<template v-else>
			<!-- No status chip. This strip sits in the page header now, and the
			     Status card in the KPI row directly beneath names the status in
			     its colour, so a chip beside the buttons said the same thing
			     twice. The two markers stay: neither is a status, and nothing
			     else on the page says a case is suspended or closed. -->
			<span
				v-if="suspended"
				class="case-transitions__suspended"
				data-testid="case-suspended-marker">
				{{ t('dossiq', 'Suspended') }}
			</span>
			<span
				v-else-if="closed"
				class="case-transitions__closed"
				data-testid="case-closed-marker">
				{{ t('dossiq', 'This case is closed') }}
			</span>

			<div class="case-transitions__buttons">
				<div
					v-for="transition in transitions"
					:key="transition.id"
					class="case-transitions__offer">
					<NcButton
						:data-testid="`case-transition-${transition.id}`"
						:disabled="blocked(transition)"
						:title="reasonFor(transition)"
						variant="primary"
						@click="openConfirm(transition)">
						{{ transition.label }}
					</NcButton>
					<span
						v-if="reasonFor(transition)"
						class="case-transitions__reason"
						:data-testid="`case-transition-reason-${transition.id}`">
						{{ reasonFor(transition) }}
					</span>
				</div>

				<NcButton
					v-if="lifecycleActions.includes('resume')"
					data-testid="case-lifecycle-resume"
					variant="secondary"
					@click="openLifecycle('resume')">
					{{ t('dossiq', 'Resume') }}
				</NcButton>
			</div>
		</template>

		<CaseTransitionConfirmDialog
			v-if="pending"
			:caseId="caseId"
			:transition="pending"
			:closing="pendingClosing"
			:resultTypes="resultTypes"
			@close="pending = null" />

		<CaseLifecycleActionDialog
			v-if="lifecycleAction"
			:caseId="caseId"
			:action="lifecycleAction"
			@close="lifecycleAction = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CaseLifecycleActionDialog from '../../dialogs/CaseLifecycleActionDialog.vue'
import CaseTransitionConfirmDialog from '../../dialogs/CaseTransitionConfirmDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import {
	isClosingTransition,
	offeredLifecycleActions,
	rowId,
	transitionBlockReason,
	transitionIsBlocked,
} from '../../utils/caseLifecycleHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTransitionsWidget',

	components: {
		CaseLifecycleActionDialog,
		CaseTransitionConfirmDialog,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			transitions: [],
			statusTypes: [],
			resultTypes: [],
			lifecycleState: null,
			pending: null,
			lifecycleAction: null,
		}
	},

	computed: {
		/** @spec openspec/specs/status-transition-engine/spec.md */
		caseId() {
			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		suspended() {
			return this.lifecycleState?.suspended === true
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		closed() {
			return this.lifecycleState?.isFinalStatus === true
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		lifecycleActions() {
			return offeredLifecycleActions(this.lifecycleState)
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		pendingClosing() {
			return isClosingTransition(this.statusTypes, this.pending?.toStatus)
		},
	},

	/**
	 * Read the case's transitions and lifecycle state once the strip is up.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	async mounted() {
		subscribe(PAGE_REFRESH, this.onPageRefresh)
		await initializeStores()
		await this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.onPageRefresh)
	},

	methods: {
		t,

		/**
		 * Whether a guard is holding this transition.
		 *
		 * The engine offers a held-back transition rather than hiding it, so
		 * the handler learns what the case still needs instead of wondering
		 * where the button went.
		 *
		 * @param {object} transition One offered transition.
		 * @return {boolean} True when it cannot be taken yet.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		blocked(transition) {
			return transitionIsBlocked(transition)
		},

		/**
		 * The reason a transition cannot be taken yet.
		 *
		 * @param {object} transition One offered transition.
		 * @return {string} The reason, or the empty string.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		reasonFor(transition) {
			return transitionBlockReason(transition)
		},

		/**
		 * Load the transitions this user may take, the case's lifecycle state,
		 * and the rows the confirm dialog needs to know what it is asking.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async load() {
			if (!this.caseId) {
				this.loading = false
				return
			}
			this.loading = true
			await Promise.all([this.loadTransitions(), this.loadLifecycleState()])
			await this.loadCaseTypeRows()
			this.loading = false
		},

		/**
		 * The engine's answer for this user on this case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async loadTransitions() {
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.caseId)}/available-transitions`,
					),
				)
				this.transitions = Array.isArray(data?.transitions)
					? data.transitions
					: []
			} catch {
				// A case whose engine cannot answer shows no buttons rather than
				// a broken strip: the rest of the page is still readable.
				this.transitions = []
			}
		},

		/**
		 * What the case allows besides moving status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async loadLifecycleState() {
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.caseId)}/lifecycle`,
					),
				)
				this.lifecycleState = data ?? null
			} catch {
				this.lifecycleState = null
			}
		},

		/**
		 * The case type's status types (to know which transition closes the
		 * case) and its result types (to know what to ask for when one does).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async loadCaseTypeRows() {
			const store = useObjectStore()
			let caseTypeId
			try {
				const caseObject =
					(await store.fetchObject('case', this.caseId)) || {}
				caseTypeId = String(caseObject.caseType ?? '')
			} catch {
				caseTypeId = ''
			}
			if (!caseTypeId) {
				this.statusTypes = []
				this.resultTypes = []
				return
			}

			// Read the two collections INDEPENDENTLY. `resultType` is registered
			// from a config key with no slug fallback, so it is the one of the
			// pair that can fail on a fresh register — and a shared catch would
			// take the statuses down with it, which would silently stop the
			// dialog asking for a result on a CLOSING transition rather than
			// merely leaving it nothing to offer.
			try {
				this.statusTypes =
					(await store.fetchCollection('statusType', {
						caseType: caseTypeId,
						_limit: 100,
					})) || []
			} catch {
				this.statusTypes = []
			}
			try {
				const results =
					(await store.fetchCollection('resultType', {
						caseType: caseTypeId,
						_limit: 100,
					})) || []
				this.resultTypes = results.map((row) => ({
					id: rowId(row),
					label: String(row.name ?? row.title ?? ''),
				}))
			} catch {
				this.resultTypes = []
			}
		},

		/**
		 * Open the confirmation for one transition.
		 *
		 * @param {object} transition The transition the handler pressed.
		 * @return {void}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		openConfirm(transition) {
			this.pending = transition
		},

		/**
		 * Open the reason dialog for one lifecycle gesture.
		 *
		 * @param {string} action One of suspend, resume, extend, reopen.
		 * @return {void}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		openLifecycle(action) {
			this.lifecycleAction = action
		},

		/**
		 * A write landed anywhere on the page: re-read the case.
		 *
		 * The dialogs bump `cn:page:refresh` themselves rather than reporting
		 * back to whoever opened them, because the Actions menu opens the
		 * lifecycle dialog with no parent widget listening at all.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async onPageRefresh() {
			this.pending = null
			this.lifecycleAction = null
			await this.load()
		},
	},
}
</script>

<style scoped>
.case-transitions__offer {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.case-transitions__reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

/* A row, because it sits in the header's button row beside Edit and the
   Actions menu; the markers and the buttons line up on one baseline. */
.case-transitions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.case-transitions__suspended,
.case-transitions__closed {
	border-radius: var(--border-radius-pill);
	padding: 2px 8px;
	font-size: 0.85em;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.case-transitions__suspended {
	background-color: var(--color-warning, var(--color-background-dark));
	color: var(--color-warning-text, var(--color-main-text));
}

.case-transitions__buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>

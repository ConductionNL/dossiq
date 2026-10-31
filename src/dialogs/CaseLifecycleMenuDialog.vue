<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One menu holding every lifecycle act on the case.

  The acts used to sit in three places: four header actions, the transitions
  the stages widget drew, and a delete behind its own button. Each was gated
  differently, so a handler found out what they could do by trying. This is the
  same three server answers rendered in one list.

  An act the handler may not perform is SHOWN and disabled with the reason. It
  is never hidden. A hidden act teaches nobody why, and the reason is what
  tells the handler who to ask.

  The menu decides nothing. Every verdict and every sentence in it is copied
  from a server answer, because a second derivation would eventually offer a
  move the write refuses.

  An act that carries a result also offers the result templates of the case's
  case type. This is the close form a handler reaches, so this is where a
  standard refusal is not retyped. A template never writes over what the
  handler already typed.

  `size="normal"`: the default small dialog is about 390px wide, and NcDialog
  reserves no room beside its title for the close button, so "Wat je met deze
  zaak kunt doen" ran under the X. The wider dialog keeps the title clear of it,
  and the acts and their reasons wrap less (lifecycle-dialog-title-clears-the-
  close-button).

  @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
  @spec openspec/changes/the-close-form-keeps-its-template/specs/template-library/spec.md
  @spec openspec/specs/case-management/spec.md#requirement-the-lifecycle-dialogs-title-stays-clear-of-its-close-button-req-cm-78
  @spec openspec/specs/case-management/spec.md#requirement-finishing-or-aborting-a-case-picks-its-result-from-the-case-type-req-cm-80
-->
<template>
	<CnDialog
		:name="menuMode ? t('dossiq', 'What you can do with this case') : actTitle(chosen)"
		:eyebrow="eyebrow"
		:subtitle="menuMode ? t('dossiq', 'Only what this case allows now is active. The rest is greyed out, with the reason.') : explainer(chosen)"
		width="form"
		data-testid="case-acts-dialog"
		@closing="$emit('close')">
		<div v-if="menuMode" class="case-acts">
			<p v-if="loading" data-testid="case-acts-loading">
				{{ t('dossiq', 'Reading what this case allows.') }}
			</p>

			<!--
				What the case is waiting for, and on whom (REQ-DEC-01). Above
				the list rather than only on the act it blocks: the handler's
				next move is to chase a person, and that answer should not be
				behind a disabled button they have to find first.
			-->
			<ul
				v-if="!loading && awaiting.length > 0"
				class="case-acts__awaiting"
				data-testid="case-awaiting-approval">
				<li
					v-for="entry in awaiting"
					:key="entry.act"
					:data-testid="'case-awaiting-' + entry.act">
					<span class="case-acts__awaiting-label">{{
						heading(entry)
					}}</span>
					<span class="case-acts__reason">{{ entry.sentence }}</span>
					<span
						v-if="approvers(entry)"
						class="case-acts__awaiting-people"
						:data-testid="'case-awaiting-people-' + entry.act">
						{{ approvers(entry) }}
					</span>
					<NcButton
						v-if="!entry.decisionRef"
						:disabled="busy"
						:data-testid="'case-awaiting-ask-' + entry.act"
						@click="askForApproval(entry)">
						{{ t('dossiq', 'Ask for this approval') }}
					</NcButton>
				</li>
			</ul>

			<p
				v-if="error && chosen === null"
				class="case-acts__error"
				data-testid="case-awaiting-error"
				role="alert">
				{{ error }}
			</p>

			<CnChoiceCards
				v-if="!loading"
				:modelValue="chosenKey"
				layout="rows"
				:options="choiceOptions"
				data-testid="case-acts-list"
				@update:modelValue="chooseByKey">
				<template #option-details="{ option }">
					<CnTransitionInputFields
						v-if="chosen !== null && option.value === entryKey(chosen)"
						v-bind="fieldProps"
						@update:modelValue="values = $event" />
				</template>
			</CnChoiceCards>

			<p
				v-if="error && chosen !== null"
				class="case-acts__error"
				data-testid="case-act-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<div v-else class="case-acts">
			<CnTransitionInputFields v-bind="fieldProps" @update:modelValue="values = $event" />

			<p
				v-if="error"
				class="case-acts__error"
				data-testid="case-act-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-acts-cancel" @click="back">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<!--
				Busy while the act posts: a transition can take seconds, and a
				button that only greys out reads as one that did nothing.
			-->
			<NcButton
				v-if="chosen !== null"
				data-testid="case-acts-confirm"
				variant="primary"
				:disabled="!canConfirm"
				:aria-busy="busy ? 'true' : null"
				@click="confirm">
				<template v-if="busy || form.confirmIcon || actIcon" #icon>
					<NcLoadingIcon v-if="busy" :size="20" data-testid="case-acts-confirm-busy" />
					<component :is="form.confirmIcon" v-else-if="form.confirmIcon" :size="20" />
					<CnIcon v-else :name="actIcon" :size="20" />
				</template>
				{{ label(chosen) }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog, CnIcon, CnTransitionInputFields, readTransitionInputs } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import manifest from '../manifest.json'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { actForm } from '../utils/caseActInputs.js'
import { buildActsMenu, endpointFor, iconFor } from '../utils/caseActsMenu.js'
import {
	approvalHeading,
	approversLine,
	awaitingApprovals,
} from '../utils/caseAwaitingApproval.js'
import {
	archiveNotice,
	buildTransitionPayload,
	extensionNotice,
	refusalMessage,
} from '../utils/caseLifecycleHelpers.js'
import { findHeaderAction } from '../utils/manifestHeaderAction.js'
import { failedActionsWarning } from '../utils/transitionOutcome.js'

const PAGE_REFRESH = 'cn:page:refresh'

/**
 * A move's label: "To {status}" when its own label is only the status it
 * leads to, or empty (board DqVolgendeStap, "Naar Besluit"); else the label an
 * administrator wrote.
 *
 * @param {object} entry A menu entry.
 * @return {string} The label.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
function moveLabel(entry) {
	const own = String(entry?.label ?? '')
	const status = String(entry?.toStatusName ?? '')
	if (entry?.kind === 'transition' && status !== '' && (own === '' || own === status)) {
		return t('dossiq', 'To {status}', { status })
	}
	return own
}

export default {
	name: 'CaseLifecycleMenuDialog',

	components: {
		CnChoiceCards,
		CnDialog,
		CnIcon,
		CnTransitionInputFields,
		NcButton,
		NcLoadingIcon,
	},

	inject: {
		/** The page's action dispatcher, to open an act's own dialog. */
		cnDispatchAction: { default: null },
	},

	props: {
		/**
		 * The case to act on. Absent when the manifest opened the dialog: an
		 * `open-modal` action carries no object context, so the route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},

		/** An act to open on directly, such as `archive`, when the case allows it. */
		act: {
			type: String,
			default: '',
		},

		/**
		 * Acts that open a dialog of their own instead of a form in the menu,
		 * as `{ act: headerActionId }` (board DqArchiveren draws archiving as
		 * its own dialog: `{ archive: 'case-archive' }`).
		 */
		actDialogs: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close'],

	data() {
		return {
			entries: [],
			kase: null,
			awaiting: [],
			chosen: null,
			loading: true,
			// What the chosen act's inputs hold (caseActInputs.js). The
			// reason, the date and the days carry over from one act to the
			// next; a result and a pause reason do not (`choose`).
			values: {
				reason: '',
				resultTypeId: '',
				until: '',
				days: '14',
				pauseReason: '',
				notifyRequester: true,
			},

			pauseReasons: [],
			caseType: '',
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {string} The context line above the title.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		eyebrow() {
			return caseEyebrow(this.menuMode ? t('dossiq', 'Next step') : this.label(this.chosen), this.kase)
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * The chosen act's form: its input declarations and layout.
		 *
		 * @return {object} The form (`actForm`).
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		form() {
			return actForm(this.chosen, {
				caseType: this.caseType,
				caseId: this.targetCaseId,
				requester: String(this.kase?.initiatorDisplayName ?? ''),
			})
		},

		/**
		 * What the chosen act's inputs read: the case for the summary, the
		 * case type's pause reasons and the act's result types.
		 *
		 * @return {object} The context.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		formContext() {
			return { kase: this.kase, pauseReasons: this.pauseReasons, resultTypes: this.resultOptions }
		},

		/**
		 * The values against the declarations: whether the form is complete,
		 * and what it sends.
		 *
		 * @return {{complete: boolean, data: object}} The reading.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		answers() {
			return readTransitionInputs(this.form.inputs, this.values, { context: this.formContext })
		},

		/**
		 * The result types the chosen act may close with, as the engine sent them.
		 *
		 * @return {Array<{id: string, name: string}>} The choices, empty when none.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		resultOptions() {
			return Array.isArray(this.chosen?.resultTypes)
				? this.chosen.resultTypes
				: []
		},

		/**
		 * Whether the dialog lists every act (the case's Lifecycle menu), rather
		 * than opening on one act the manifest named (`act`).
		 *
		 * @return {boolean} True for the list.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		menuMode() {
			return this.act === '' || this.chosen === null
		},

		/**
		 * The acts as choice cards: what each does, or why the case does not allow it now.
		 * A refused act carries its reason once, as the card's disabled reason.
		 *
		 * @return {Array<object>} CnChoiceCards options.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		choiceOptions() {
			return this.entries.map((entry) => ({
				value: this.entryKey(entry),
				label: this.label(entry),
				icon: iconFor(entry),
				description: entry.disabled ? '' : this.explainer(entry),
				disabled: Boolean(entry.disabled),
				disabledReason: this.refusalOn(entry),
			}))
		},

		/**
		 * The icon the chosen act's card shows, for its confirm button.
		 *
		 * @return {string} A registered icon name, '' when none is chosen.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		actIcon() {
			return this.chosen === null ? '' : iconFor(this.chosen)
		},

		/**
		 * The chosen act's card, or null.
		 *
		 * @return {string|null} The card value.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		chosenKey() {
			return this.chosen === null ? null : this.entryKey(this.chosen)
		},

		/**
		 * What the chosen act's inputs are drawn with.
		 *
		 * @return {object} CnTransitionInputFields props.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		fieldProps() {
			return {
				inputs: this.form.inputs,
				modelValue: this.values,
				context: this.formContext,
				columns: this.form.columns,
				summary: this.form.summary,
				notice: this.form.notice,
				disabled: this.busy,
				markRequired: false,
				// The boards mark what is NOT required ("(niet verplicht)").
				markOptional: true,
				'data-testid': 'case-act-fields-' + String(this.chosen?.id ?? ''),
			}
		},

		/**
		 * Whether the act may be posted: not while posting, and only with every
		 * input it asks filled. A pause reason picker needs a pick and the free
		 * text only after Other; a closing move with results waits for one,
		 * because the engine refuses the close without it.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		canConfirm() {
			return !this.busy && this.chosen !== null && this.answers.complete
		},
	},

	/**
	 * Read what the case allows before anything is on screen.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * One translated label.
		 *
		 * 🔴 EVERY ACT'S LABEL IS A LITERAL `t()` CALL HERE, not a lookup of
		 * the string the menu carries. The l10n extractor reads literal calls
		 * out of the source, so `t('dossiq', entry.label)` gives translators
		 * nothing at all: the strings would ship English on every instance and
		 * the check would pass, because there would be no key to miss.
		 *
		 * A transition's label is the exception and is passed through: it is
		 * authored by an administrator in the workflow template, in their own
		 * language, so there is no key for it and nothing to translate.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The label.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		label(entry) {
			switch (String(entry?.id ?? '')) {
				case 'suspend':
					return t('dossiq', 'Suspend')
				case 'resume':
					return t('dossiq', 'Resume')
				case 'extend':
					return t('dossiq', 'Extend term')
				case 'reopen':
					return t('dossiq', 'Reopen or restore from the archive')
				case 'finish':
					return t('dossiq', 'Finish')
				case 'abort':
					return t('dossiq', 'Break off')
				case 'archive':
					return t('dossiq', 'Archive it')
				case 'unarchive':
					return t('dossiq', 'Reopen or restore from the archive')
				case 'hold':
					return t('dossiq', 'Hold')
				case 'release-hold':
					return t('dossiq', 'Take off hold')
				case 'promote':
					return t('dossiq', 'Promote to case')
				default:
					return moveLabel(entry)
			}
		},

		/**
		 * One translated explainer, for the same reason label() is a switch.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The explainer, empty when the entry carries none.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		explainer(entry) {
			if (entry?.kind === 'transition') {
				return t(
					'dossiq',
					'The next status of the case type. Statuses that cannot follow from here are greyed out.',
				)
			}
			switch (String(entry?.id ?? '')) {
				case 'finish':
					return t(
						'dossiq',
						'Choose the result type. The end date and the archive outcome are recorded.',
					)
				case 'suspend':
					return t('dossiq', 'The term stands still until the answer is in.')
				case 'abort':
					return t(
						'dossiq',
						'The case stops without a decision. Asks for a reason.',
					)
				case 'archive':
					return t(
						'dossiq',
						'The case moves to the retention rule its result type carries.',
					)
				case 'reopen':
				case 'unarchive':
					return t('dossiq', 'Only for a finished or archived case.')
				case 'hold':
					return t('dossiq', 'Park it internally, the term keeps running.')
				case 'release-hold':
					return t('dossiq', 'You pick the case up again before its date.')
				case 'promote':
					return t(
						'dossiq',
						'The term starts now. The case leaves your drafts.',
					)
				case 'extend':
					// Board DqVolgendeStap. Two literal keys, so the extractor
					// finds both; caseActsMenu picks which one applies.
					return entry?.explainer
						=== 'Once, with a reason. The requester gets a message.'
						? t(
								'dossiq',
								'Once, with a reason. The requester gets a message.',
							)
						: t('dossiq', 'With a reason. The requester gets a message.')
				default:
					return ''
			}
		},

		/**
		 * One translated refusal.
		 *
		 * The sentences the menu builds for a refused term gesture are the
		 * same four `refusalMessage` prints for the matching code, so they are
		 * translated through it: one key per refusal, shared by the menu and
		 * by the dialog behind it. A refusal the SERVER authored, which is
		 * every role refusal, is shown as it arrived.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The refusal, empty when the entry is not refused.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		refusalOn(entry) {
			if (!entry?.disabled || !entry?.reason) {
				return ''
			}

			return entry.kind === 'gesture'
				? t('dossiq', entry.reason)
				: String(entry.reason)
		},

		/**
		 * Ask the three endpoints and merge their answers into one menu.
		 *
		 * `allSettled` and not `all`: one endpoint that is down must not empty
		 * the menu, because an empty menu is a legitimate answer for a case in
		 * a terminal status and the two would be indistinguishable.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async load() {
			if (!this.targetCaseId) {
				this.loading = false
				return
			}
			const id = encodeURIComponent(this.targetCaseId)
			const [moves, state, acts] = await Promise.allSettled([
				axios.get(
					generateUrl(`/apps/dossiq/api/case/${id}/available-transitions`),
				),
				axios.get(generateUrl(`/apps/dossiq/api/case/${id}/lifecycle`)),
				axios.get(generateUrl(`/apps/dossiq/api/case/${id}/acts`)),
			])

			const actsBody =
				acts.status === 'fulfilled' ? (acts.value?.data ?? null) : null

			this.entries = buildActsMenu({
				transitions:
					moves.status === 'fulfilled'
						? (moves.value?.data?.transitions ?? [])
						: [],
				state:
					state.status === 'fulfilled'
						? (state.value?.data ?? null)
						: null,
				acts: actsBody,
			})
			fetchCaseSummary(this.targetCaseId).then((kase) => {
				this.kase = kase
			})
			const preset = this.entries.find((entry) => entry.id === this.act && !entry.disabled)
			if (this.act !== '' && preset !== undefined) {
				this.choose(preset)
			}
			this.awaiting = awaitingApprovals(actsBody)
			// The case type scopes the result templates. '' is a real answer:
			// the picker then offers the templates meant for every case type.
			this.caseType =
				state.status === 'fulfilled'
					? String(state.value?.data?.caseType ?? '')
					: ''
			this.pauseReasons =
				state.status === 'fulfilled' && Array.isArray(state.value?.data?.pauseReasons)
					? state.value.data.pauseReasons
					: []
			this.loading = false
		},

		/**
		 * Ask decidiq to walk the approval one act waits on, then re-read.
		 *
		 * Only offered while no approval has been asked for. Once decidiq has
		 * it, the walk and its people are decidiq's, and the row says who it
		 * waits on instead.
		 *
		 * @param {object} entry One row of the awaiting list.
		 * @return {Promise<void>}
		 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
		 */
		async askForApproval(entry) {
			this.busy = true
			this.error = ''
			const id = encodeURIComponent(this.targetCaseId)
			const act = encodeURIComponent(entry.act)
			try {
				await axios.post(
					generateUrl(`/apps/dossiq/api/case/${id}/approvals/${act}`),
				)
				await this.load()
			} catch (e) {
				this.error = refusalMessage(e?.response?.data ?? {}, (key) =>
					t('dossiq', key),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The name of one outstanding approval.
		 *
		 * @param {object} entry One row of the awaiting list.
		 * @return {string} The heading.
		 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
		 */
		heading(entry) {
			return approvalHeading(entry)
		},

		/**
		 * The people one outstanding approval waits on.
		 *
		 * Empty when decidiq named none, and the sentence beside it already
		 * says so, so nothing here renders an empty list as nobody.
		 *
		 * @param {object} entry One row of the awaiting list.
		 * @return {string} The names, or ''.
		 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
		 */
		approvers(entry) {
			return approversLine(entry)
		},

		/**
		 * @param {object} entry A menu entry.
		 * @return {string} The dialog title while that act is open.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		actTitle(entry) {
			return actForm(entry).title || this.label(entry)
		},

		/**
		 * The card value of one act: its kind and id, since a transition and a gesture may share an id.
		 *
		 * @param {object} entry A menu entry.
		 * @return {string} The value.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		entryKey(entry) {
			return `${entry.kind}:${entry.id}`
		},

		/**
		 * Choose the act behind a card.
		 *
		 * @param {string} key The card value.
		 * @return {void}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		chooseByKey(key) {
			const entry = this.entries.find((candidate) => this.entryKey(candidate) === key)
			if (entry) {
				this.choose(entry)
			}
		},

		/**
		 * Open one act's form.
		 *
		 * @param {object} entry The entry the handler pressed.
		 * @return {void}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		choose(entry) {
			if (entry.disabled) {
				return
			}
			if (this.openOwnDialog(entry)) {
				return
			}
			this.chosen = entry
			this.error = ''
			// A result or a pause reason picked for one act is not an answer for another.
			this.values = { ...this.values, resultTypeId: '', pauseReason: '' }
		},

		/**
		 * Open the dialog an act is configured to open, closing the menu.
		 *
		 * Without a page to open it (no dispatcher), or without the action the
		 * configuration names, the act's form folds open in the menu as before.
		 *
		 * @param {object} entry The entry the handler pressed.
		 * @return {boolean} Whether a dialog of its own was opened.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		openOwnDialog(entry) {
			const named = String(this.actDialogs?.[String(entry?.id ?? '')] ?? '')
			if (named === '' || typeof this.cnDispatchAction !== 'function') {
				return false
			}
			const action = findHeaderAction(manifest, named)
			if (action === null) {
				return false
			}
			this.$emit('close')
			this.cnDispatchAction(action, { tokenCtx: { objectId: this.targetCaseId } })
			return true
		},

		/**
		 * Go back to the list, or close when already on it.
		 *
		 * @return {void}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		back() {
			if (this.chosen === null || this.menuMode) {
				this.$emit('close')
				return
			}
			this.chosen = null
			this.error = ''
		},

		/**
		 * Post the act, and keep the form open on a refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.busy = true
			this.error = ''
			const id = encodeURIComponent(this.targetCaseId)
			// Only what the act asks now is sent: text typed before a pause
			// reason was picked instead of Other is not a reason.
			const sent = { reason: '', resultTypeId: '', until: '', days: '', pauseReason: '', ...this.answers.data }
			try {
				if (this.chosen.kind === 'transition') {
					const { data } = await axios.post(
						generateUrl(`/apps/dossiq/api/case/${id}/transition`),
						buildTransitionPayload({
							transitionId: this.chosen.id,
							comment: sent.reason,
							resultTypeId: sent.resultTypeId,
						}),
					)
					// A 200 is not the same as everything having happened: the
					// status moves before the automatic actions run, and the
					// engine answers `partial` naming what failed.
					this.warnAboutFailedActions(data)
				} else {
					const { data } = await axios.post(
						generateUrl(
							`/apps/dossiq/api/case/${id}/${endpointFor(this.chosen)}`,
						),
						{
							reason: sent.reason,
							resultTypeId: sent.resultTypeId,
							until: sent.until,
							days: Number(sent.days) || 0,
							pauseReason: sent.pauseReason,
							...(this.chosen.id === 'archive' ? { notifyRequester: this.values.notifyRequester === true } : {}),
						},
					)
					this.sayWhetherTheRequesterWasTold(data, this.chosen.id)
				}
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error = refusalMessage(error?.response?.data ?? {}, (s) =>
					t('dossiq', s),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Tell the handler when the case moved without all of its work.
		 *
		 * A warning rather than an error, and the dialog still closes: the move
		 * itself happened and is recorded, so holding the dialog open would
		 * offer a retry of something that is already done.
		 *
		 * @param {object} data The transition response body.
		 * @return {void}
		 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
		 */
		warnAboutFailedActions(data) {
			const warning = failedActionsWarning(data)
			if (warning !== '') {
				showWarning(warning)
			}
		},

		/**
		 * After an extension or an archive, say whether the requester was told.
		 *
		 * The extension stands either way and the dialog closes. A notice that
		 * did not go out is a warning with its reason, so the handler can tell
		 * the requester another way. An act that answers no notice says nothing.
		 *
		 * @param {object} data The act's response body.
		 * @param {string} [act] The act that answered.
		 * @return {void}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
		 */
		sayWhetherTheRequesterWasTold(data, act = '') {
			const translate = (s) => t('dossiq', s)
			const notice = act === 'archive' ? archiveNotice(data, translate) : extensionNotice(data, translate)
			if (notice === null) {
				return
			}
			if (notice.told) {
				showSuccess(notice.text)
			} else {
				showWarning(notice.text)
			}
		},
	},
}
</script>

<style scoped>
/* The dialog body already pads the list; the board starts the first act
   22px under the subtitle and ends the body 22px under the last one. */
.case-acts {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.case-acts__reason,
.case-acts__explainer {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-acts__awaiting {
	border-inline-start: 4px solid var(--color-warning, var(--color-primary));
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 8px 12px;
}

.case-acts__awaiting li {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.case-acts__awaiting-label {
	font-weight: bold;
}

.case-acts__awaiting-people {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-acts__error {
	color: var(--color-error-text, var(--color-error));
}
</style>

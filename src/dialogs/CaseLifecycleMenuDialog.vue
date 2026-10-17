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
  @spec openspec/changes/lifecycle-dialog-title-clears-the-close-button/specs/case-management/spec.md
  @spec openspec/changes/finishing-picks-a-result/specs/case-management/spec.md
-->
<template>
	<CnDialog
		:name="chosen === null ? t('dossiq', 'What you can do with this case') : actTitle(chosen)"
		:eyebrow="eyebrow"
		:subtitle="chosen === null ? '' : explainer(chosen)"
		width="form"
		data-testid="case-acts-dialog"
		@closing="$emit('close')">
		<div v-if="chosen === null" class="case-acts">
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
				v-if="error"
				class="case-acts__error"
				data-testid="case-awaiting-error"
				role="alert">
				{{ error }}
			</p>

			<ul v-else class="case-acts__list" data-testid="case-acts-list">
				<li
					v-for="entry in entries"
					:key="entry.kind + ':' + entry.id"
					class="case-acts__item"
					:class="{ 'case-acts__item--disabled': entry.disabled }"
					:data-testid="'case-act-' + entry.id">
					<NcButton
						:disabled="entry.disabled"
						:data-testid="'case-act-button-' + entry.id"
						@click="choose(entry)">
						{{ label(entry) }}
					</NcButton>
					<span
						v-if="entry.disabled && entry.reason"
						class="case-acts__reason"
						:data-testid="'case-act-reason-' + entry.id">
						{{ refusalOn(entry) }}
					</span>
					<span v-else-if="entry.explainer" class="case-acts__explainer">
						{{ explainer(entry) }}
					</span>
				</li>
			</ul>
		</div>

		<div v-else class="case-acts">
			<template v-if="chosen.id === 'archive' && kase">
				<div class="case-acts__case" data-testid="case-act-archive-case">
					<FolderOutline :size="20" class="case-acts__case-icon" />
					<span class="case-acts__case-text">
						<strong>{{ kase.title }}</strong>
						<span class="case-acts__muted">{{ [kase.identifier, kase.initiatorDisplayName].filter(Boolean).join(' · ') }}</span>
					</span>
					<span v-if="kase.resultPublicLabel" class="case-acts__badge">{{ kase.resultPublicLabel }}</span>
				</div>

				<section class="case-acts__retention" data-testid="case-act-archive-retention">
					<h3 class="case-acts__section-title">
						{{ t('dossiq', 'Retention under the case type') }}
					</h3>
					<dl class="case-acts__facts">
						<div>
							<dt>{{ t('dossiq', 'Archive nomination') }}</dt>
							<dd>{{ kase.archiveNomination || t('dossiq', 'Not set') }}</dd>
						</div>
						<div>
							<dt>{{ t('dossiq', 'Destruction date') }}</dt>
							<dd>{{ kase.archiveActionDate || t('dossiq', 'Not set') }}</dd>
						</div>
						<div>
							<dt>{{ t('dossiq', 'Archive status afterwards') }}</dt>
							<dd>{{ kase.archiveStatus || t('dossiq', 'Not set') }}</dd>
						</div>
					</dl>
				</section>
			</template>

			<TemplatePicker
				v-if="inputs.result"
				kind="result"
				:caseType="caseType"
				:caseId="targetCaseId"
				:label="t('dossiq', 'Result template')"
				@apply="applyTemplate" />

			<div v-if="inputs.reason" class="case-acts__field">
				<NcTextArea
					v-model="reason"
					data-testid="case-act-reason-input"
					:label="t('dossiq', 'Reason')"
					:labelOutside="true"
					resize="vertical" />
				<span class="case-acts__muted">
					{{ t('dossiq', 'Goes into the history of the case.') }}
				</span>
			</div>

			<NcNoteCard
				v-if="chosen.id === 'archive'"
				type="info"
				:heading="t('dossiq', 'Read only afterwards')">
				{{ t('dossiq', 'Once archived, the documents, tasks and messages of this case can no longer be changed. The case stays findable until its archive action date.') }}
			</NcNoteCard>

			<!--
				A closing move or ending act picks from the case type's own
				result types, which the server publishes on the move and on
				`/acts`. There is no free field: a typed name is not an id, and
				a case type with no results needs none.
			-->
			<NcSelect
				v-if="inputs.result && resultOptions.length > 0"
				v-model="selectedResult"
				:options="resultOptions"
				label="name"
				:inputLabel="t('dossiq', 'Result')"
				:clearable="false"
				:disabled="busy"
				data-testid="case-act-result-picker" />

			<NcTextField
				v-if="inputs.until"
				v-model="until"
				type="date"
				data-testid="case-act-until-input"
				:label="t('dossiq', 'The case comes back on')" />

			<NcTextField
				v-if="inputs.days"
				v-model="days"
				type="number"
				data-testid="case-act-days-input"
				:label="t('dossiq', 'Days the applicant is given')" />

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
				<template v-if="busy || chosen.id === 'archive'" #icon>
					<NcLoadingIcon v-if="busy" :size="20" data-testid="case-acts-confirm-busy" />
					<ArchiveOutline v-else :size="20" />
				</template>
				{{ chosen.id === 'archive' ? t('dossiq', 'Archive it') : t('dossiq', 'Confirm') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess, showWarning } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import TemplatePicker from '../components/TemplatePicker.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { buildActsMenu, endpointFor, inputsFor } from '../utils/caseActsMenu.js'
import {
	approvalHeading,
	approversLine,
	awaitingApprovals,
} from '../utils/caseAwaitingApproval.js'
import {
	buildTransitionPayload,
	extensionNotice,
	refusalMessage,
} from '../utils/caseLifecycleHelpers.js'
import { failedActionsWarning } from '../utils/transitionOutcome.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseLifecycleMenuDialog',

	components: {
		ArchiveOutline,
		CnDialog,
		FolderOutline,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		TemplatePicker,
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
	},

	emits: ['close'],

	data() {
		return {
			entries: [],
			kase: null,
			awaiting: [],
			chosen: null,
			loading: true,
			reason: '',
			resultTypeId: '',
			until: '',
			days: '14',
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
			return caseEyebrow(this.chosen === null ? t('dossiq', 'Lifecycle') : this.label(this.chosen), this.kase)
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		inputs() {
			return inputsFor(this.chosen ?? {})
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

		/** @spec openspec/specs/status-transition-engine/spec.md */
		selectedResult: {
			get() {
				return (
					this.resultOptions.find(
						(option) => option.id === this.resultTypeId,
					) ?? null
				)
			},

			set(option) {
				this.resultTypeId = option ? String(option.id) : ''
			},
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		canConfirm() {
			if (this.busy || this.chosen === null) {
				return false
			}
			if (this.inputs.reason && this.reason.trim().length === 0) {
				return false
			}
			// A closing move with results to pick from waits for one: the
			// engine refuses the close without it.
			if (
				this.inputs.result
				&& this.resultOptions.length > 0
				&& this.resultTypeId === ''
			) {
				return false
			}

			return !(this.inputs.until && this.until.trim().length === 0)
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
					return t('dossiq', 'Reopen')
				case 'finish':
					return t('dossiq', 'Finish')
				case 'abort':
					return t('dossiq', 'Abort')
				case 'archive':
					return t('dossiq', 'Archive')
				case 'unarchive':
					return t('dossiq', 'Restore from the archive')
				case 'hold':
					return t('dossiq', 'Hold')
				case 'release-hold':
					return t('dossiq', 'Take off hold')
				case 'promote':
					return t('dossiq', 'Promote to case')
				default:
					return String(entry?.label ?? '')
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
			switch (String(entry?.id ?? '')) {
				case 'finish':
					return t(
						'dossiq',
						'The case reached its result. The result decides what is kept.',
					)
				case 'abort':
					return t(
						'dossiq',
						'An intrekking. There is a result, and it is not a besluit.',
					)
				case 'archive':
					return t(
						'dossiq',
						'The case moves to the retention rule its result type carries.',
					)
				case 'unarchive':
					return t(
						'dossiq',
						'The case comes back into the working lists and can be edited again.',
					)
				case 'hold':
					return t(
						'dossiq',
						'You park the case until a date. The statutory term keeps running.',
					)
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
			return entry?.id === 'archive' ? t('dossiq', 'Archive this case') : this.label(entry)
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
			this.chosen = entry
			this.error = ''
			// A result picked for one act is not an answer for another.
			this.resultTypeId = ''
		},

		/**
		 * Fill the outcome text from a result template.
		 *
		 * 🔑 IT DOES NOT OVERWRITE WHAT THE HANDLER ALREADY TYPED. Picking a
		 * template after writing two paragraphs and losing them is worse than
		 * having no templates: the handler cannot get the text back, and the
		 * gesture that destroyed it looked like a convenience.
		 *
		 * @param {object} chosen The template, with its body and presets.
		 * @return {void}
		 * @spec openspec/changes/the-close-form-keeps-its-template/specs/template-library/spec.md
		 */
		applyTemplate(chosen) {
			const text = chosen?.body || chosen?.presets?.body || ''
			if (!text || this.reason.trim() !== '') {
				return
			}

			this.reason = text
		},

		/**
		 * Go back to the list, or close when already on it.
		 *
		 * @return {void}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		back() {
			if (this.chosen === null) {
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
			try {
				if (this.chosen.kind === 'transition') {
					const { data } = await axios.post(
						generateUrl(`/apps/dossiq/api/case/${id}/transition`),
						buildTransitionPayload({
							transitionId: this.chosen.id,
							comment: this.reason,
							resultTypeId: this.resultTypeId,
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
							reason: this.reason,
							resultTypeId: this.resultTypeId,
							until: this.until,
							days: Number(this.days) || 0,
						},
					)
					this.sayWhetherTheRequesterWasTold(data)
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
		 * After an extension, say whether the requester was told.
		 *
		 * The extension stands either way and the dialog closes. A notice that
		 * did not go out is a warning with its reason, so the handler can tell
		 * the requester another way. An act that answers no notice says nothing.
		 *
		 * @param {object} data The act's response body.
		 * @return {void}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
		 */
		sayWhetherTheRequesterWasTold(data) {
			const notice = extensionNotice(data, (s) => t('dossiq', s))
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
.case-acts__case {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.case-acts__case-icon {
	color: var(--color-primary-element);
}

.case-acts__case-text {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.case-acts__badge {
	padding: 3px 10px;
	border-radius: 12px;
	background: var(--color-success-light, var(--color-background-dark));
	color: var(--color-success-text);
	font-size: 13px;
	font-weight: 600;
}

.case-acts__muted {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-acts__section-title {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 600;
}

.case-acts__facts {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 0 24px;
	margin: 0;
}

.case-acts__facts > div {
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.case-acts__facts dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-acts__facts dd {
	margin: 2px 0 0;
	font-size: 15px;
	font-weight: 600;
}

.case-acts__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.case-acts {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-acts__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-acts__item {
	display: flex;
	flex-direction: column;
	gap: 2px;
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

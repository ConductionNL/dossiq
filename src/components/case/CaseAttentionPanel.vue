<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The three things this case says "look here" about, on one strip.

	🔴 THEY ARE THREE DIFFERENT FACTS AND THE STRIP KEEPS THEM APART. A flag a
	PERSON raised, an assessment the ORGANISATION made, and markers the SYSTEM
	raised against a named panel. Running them together into one "attention"
	badge is how a handler ends up unable to tell a colleague's judgement from
	a failed virus scan.

	🔴 A MARKER IS NOT THE UNREAD BADGE BESIDE IT. `CaseUnreadPanel` sits
	directly above this strip and says what has changed since this reader last
	looked; opening the panel clears that. A marker clears when the thing
	behind it is handled, and opening the panel does nothing to it. Both can be
	true at once on the same panel, which is why each is drawn in its own row
	with its own lead sentence rather than as two counts on one line.

	🔴 THE ASSESSMENT IS ABSENT, NOT BLANK, FOR A READER WITHOUT THE
	PERMISSION. OpenRegister filters the property out of the case before this
	component ever sees it, so `riskAssessment` is simply missing and the block
	is not drawn. There is no "you may not see this" state, deliberately:
	telling somebody an assessment exists that they may not read is most of
	what the permission was for.

	THE REASON IS REQUIRED IN BOTH DIRECTIONS. The button is disabled until
	something is written, but the refusal that matters is the server's: the
	browser's gate is a courtesy and `CaseAttentionFlagService` is the
	decision.

	@spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
-->
<template>
	<div v-if="show" class="case-attention" data-testid="case-attention">
		<div v-if="raised" class="case-attention__flag" data-testid="case-attention-raised">
			<span class="case-attention__lead">{{ raisedLead }}</span>
			<span class="case-attention__reason">{{ flagReason }}</span>
		</div>

		<ul v-if="markers.length > 0" class="case-attention__markers" data-testid="case-attention-markers">
			<li v-for="marker in markers" :key="marker.marker" :data-testid="`case-marker-${marker.marker}`" :data-tab="marker.tab">
				<span class="case-attention__panel">{{ marker.panelLabel }}</span>
				<span class="case-attention__reason">{{ marker.reason }}</span>
			</li>
		</ul>

		<div v-if="assessment.present" class="case-attention__risk" data-testid="case-attention-risk">
			<span class="case-attention__lead">{{ riskLead }}</span>
			<span :data-risk-level="assessment.level" class="case-attention__level">{{ levelLabel }}</span>
			<span class="case-attention__reason">{{ assessment.ground }}</span>
			<span v-if="assessment.dueForReview" data-testid="case-attention-risk-stale" class="case-attention__stale">
				{{ staleLabel }}
			</span>
		</div>

		<div class="case-attention__act">
			<NcTextField
				:value.sync="reason"
				:label="reasonLabel"
				:placeholder="reasonLabel"
				data-testid="case-attention-reason"
				@update:value="onReason" />
			<NcButton
				variant="tertiary"
				:disabled="busy || reason.trim() === ''"
				:data-testid="raised ? 'case-attention-clear' : 'case-attention-raise'"
				@click="submit">
				{{ actLabel }}
			</NcButton>
		</div>

		<ol v-if="history.length > 0" class="case-attention__history" data-testid="case-attention-history">
			<li v-for="(row, index) in history" :key="`${row.moment}-${index}`" :data-act="row.act">
				<span class="case-attention__panel">{{ actOf(row) }}</span>
				<span class="case-attention__reason">{{ row.reason }}</span>
			</li>
		</ol>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { clearAttention, fetchAttention, raiseAttention } from '../../services/caseAttentionApi.js'

/**
 * What each panel of the case page is called, for a marker that points at one.
 *
 * The ids are the widget ids the `case-panels` strip in `src/manifest.json`
 * already uses, so a marker never names a panel that is not on the page. A
 * marker whose panel has no entry here shows the id rather than nothing: a
 * marker nobody can label is still a marker somebody should see.
 */
const PANEL_LABELS = {
	'case-data-panel': 'Data',
	'case-files': 'Files',
	'case-notes-panel': 'Notes',
	'case-people-panel': 'People',
	'case-communication-panel': 'Communication',
	'case-email-panel': 'Email',
	'case-work-panel': 'Work',
	'case-decisions-panel': 'Decisions',
	'case-related-panel': 'Related',
}

/** What each assessed level is called. */
const LEVEL_LABELS = {
	low: 'Low',
	medium: 'Medium',
	high: 'High',
	critical: 'Critical',
}

export default {
	name: 'CaseAttentionPanel',

	components: { NcButton, NcTextField },

	props: {
		/** The case this strip belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The loaded case, or null while it is still being fetched. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			loaded: false,
			busy: false,
			raised: false,
			flag: {},
			history: [],
			reason: '',
		}
	},

	computed: {
		/**
		 * The case this strip is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * The markers standing on this case, each naming its panel.
		 *
		 * Read off the case rather than fetched: they are derived into the
		 * save, so the case the page already read carries them.
		 *
		 * @return {Array<object>} The markers.
		 *
		 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
		 */
		markers() {
			const raised = (this.objectData?.attentionMarkers ?? [])
			if (Array.isArray(raised) === false) {
				return []
			}

			return raised
				.filter((marker) => (marker && String(marker.marker ?? '') !== ''))
				.map((marker) => ({
					marker: String(marker.marker),
					tab: String(marker.tab ?? ''),
					reason: String(marker.reason ?? ''),
					panelLabel: t('dossiq', PANEL_LABELS[marker.tab] || String(marker.tab ?? '')),
				}))
		},

		/**
		 * The risk assessment, as far as this reader is allowed to see it.
		 *
		 * A reader without the extra permission gets a case with no
		 * `riskAssessment` property at all, because OpenRegister filtered it
		 * out. That is the same answer as a case with no assessment, and this
		 * component cannot and should not tell them apart.
		 *
		 * @return {object} The assessment, with `present` and `dueForReview`.
		 *
		 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
		 */
		assessment() {
			const stored = (this.objectData?.riskAssessment ?? null)
			const level = String(stored?.level ?? '')

			return {
				present: (level !== ''),
				level,
				ground: String(stored?.ground ?? ''),
				assessor: String(stored?.assessor ?? ''),
				reviewDate: String(stored?.reviewDate ?? ''),
				dueForReview: this.hasPassed(String(stored?.reviewDate ?? '')),
			}
		},

		/**
		 * Whether the strip has anything to say at all.
		 *
		 * A case with no flag, no marker and no assessment still shows the act,
		 * because raising the flag is a gesture that has to be reachable from
		 * a case nobody has flagged yet.
		 *
		 * @return {boolean} True once the flag has been read.
		 *
		 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
		 */
		show() {
			return this.loaded
		},

		/** @return {string} The lead sentence over a raised flag. */
		raisedLead() {
			return t('dossiq', 'Flagged as needing attention:')
		},

		/** @return {string} What the flag says, in the words of whoever raised it. */
		flagReason() {
			return String(this.flag?.reason ?? '')
		},

		/** @return {string} The lead sentence over the assessment. */
		riskLead() {
			return t('dossiq', 'Assessed risk:')
		},

		/** @return {string} The assessed level in the reader's language. */
		levelLabel() {
			return t('dossiq', LEVEL_LABELS[this.assessment.level] || this.assessment.level)
		},

		/** @return {string} What a passed review date says. */
		staleLabel() {
			return t('dossiq', 'Due for review')
		},

		/** @return {string} The label over the reason field. */
		reasonLabel() {
			if (this.raised) {
				return t('dossiq', 'Why it no longer needs attention')
			}

			return t('dossiq', 'Why this case needs attention')
		},

		/** @return {string} The label of the act this strip offers. */
		actLabel() {
			if (this.raised) {
				return t('dossiq', 'Clear the flag')
			}

			return t('dossiq', 'Flag for attention')
		},
	},

	/**
	 * Read where the flag stands.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the flag and its history.
		 *
		 * A failure leaves the strip undrawn rather than showing an error band
		 * across every case page: an instance that does not carry the change
		 * yet answers 404, and nothing else on the page depends on this.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
		 */
		async load() {
			if (this.caseId === '') {
				return
			}

			try {
				this.apply(await fetchAttention(this.caseId))
				this.loaded = true
			} catch {
				this.loaded = false
			}
		},

		/**
		 * Take the answer of a read or an act.
		 *
		 * @param {object} state What the endpoint answered.
		 * @return {void}
		 */
		apply(state) {
			this.raised = (state?.raised === true)
			this.flag = (state?.flag ?? {})
			this.history = (Array.isArray(state?.history) ? state.history : [])
		},

		/**
		 * Keep the typed reason, so the button knows whether there is one.
		 *
		 * @param {string} value What is in the field.
		 * @return {void}
		 */
		onReason(value) {
			this.reason = String(value ?? '')
		},

		/**
		 * Raise the flag, or clear it, with the reason that was written.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
		 */
		async submit() {
			const reason = this.reason.trim()
			if (reason === '') {
				return
			}

			this.busy = true
			try {
				const act = this.raised ? clearAttention : raiseAttention
				this.apply(await act(this.caseId, reason))
				this.reason = ''
				window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
			} catch (error) {
				const refusal = String(error?.response?.data?.error ?? '')
				showError(refusal !== '' ? refusal : t('dossiq', 'This did not work. Try again.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * What one history row records, in the reader's language.
		 *
		 * @param {object} row The history row.
		 * @return {string} The act.
		 */
		actOf(row) {
			if (String(row?.act ?? '') === 'cleared') {
				return t('dossiq', 'Cleared')
			}

			return t('dossiq', 'Raised')
		},

		/**
		 * Whether a stored date is behind today.
		 *
		 * The day named is the day the assessment is still good for, so a
		 * review date of today is not yet due. The same reading the server
		 * uses, deliberately: two answers to "is this stale" is one too many.
		 *
		 * @param {string} date The date.
		 * @return {boolean} True when it has passed.
		 */
		hasPassed(date) {
			if (String(date ?? '') === '') {
				return false
			}

			const moment = new Date(date)
			if (Number.isNaN(moment.getTime())) {
				return false
			}

			return (moment.toISOString().slice(0, 10) < new Date().toISOString().slice(0, 10))
		},
	},
}
</script>

<style scoped>
.case-attention {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
}

.case-attention__flag,
.case-attention__risk,
.case-attention__act {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.case-attention__lead {
	font-weight: bold;
}

.case-attention__markers,
.case-attention__history {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.case-attention__markers li,
.case-attention__history li {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.case-attention__panel {
	font-weight: bold;
}

.case-attention__level {
	font-weight: bold;
}

.case-attention__stale {
	color: var(--color-warning-text, var(--color-main-text));
}

.case-attention__reason {
	color: var(--color-text-maxcontrast);
}
</style>

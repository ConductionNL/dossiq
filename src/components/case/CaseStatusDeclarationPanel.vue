<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	What the status this case is in declares about itself.

	Three things, and the third is the one that earns the strip. A status the
	case type DERIVES is not a move a handler can pick, so when it has not
	fired there is nothing on the page to press and nothing to read: the case
	simply sits where it is, for a reason the handler cannot see. Naming the
	missing document is what turns that into an errand.

	The other two are read at the same moment and so are fetched in the same
	round trip: who the case is waiting on, which is why nobody on the team has
	touched it, and how long it has been here, which is the number the Cases
	index sorts on.

	🔴 IT ASKS THE TRANSITION ENDPOINT, NOT AN ENDPOINT OF ITS OWN. All three
	are published on `/available-transitions` beside the moves, because the
	handler asks "what can I do with this case" and "why is the thing I
	expected not on offer" in one breath. A second endpoint would be a second
	round trip for one strip, and a second place for the derivation verdict to
	be computed.

	Silent on a case with nothing to say: a case that is ours to move, inside
	its status maximum, with no derivation pending, renders nothing at all
	rather than three empty lines.

	@spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
-->
<template>
	<div
		v-if="show"
		class="case-status-declaration"
		data-testid="case-status-declaration">
		<p
			v-if="reasons"
			class="case-status-declaration__derivation"
			data-testid="case-status-derivation">
			<span class="case-status-declaration__heading">{{ heading }}</span>
			<span
				v-for="reason in reasons.unmet"
				:key="reason"
				class="case-status-declaration__missing"
				data-testid="case-status-missing">
				{{ reason }}
			</span>
		</p>

		<p
			v-if="statusExplanation"
			class="case-status-declaration__status-explanation"
			data-testid="case-status-explanation">
			{{ statusExplanation }}
		</p>

		<ul
			v-if="withheld.length > 0"
			class="case-status-declaration__withheld"
			data-testid="case-status-withheld">
			<li
				v-for="entry in withheld"
				:key="entry.id"
				data-testid="case-status-withheld-entry">
				{{ sentenceFor(entry) }}
			</li>
		</ul>

		<p
			v-if="waiting"
			class="case-status-declaration__waiting"
			data-testid="case-status-waiting">
			{{ waiting }}
		</p>

		<p
			v-if="fieldRuleLines.length > 0"
			class="case-status-declaration__fields"
			data-testid="case-status-field-rules">
			<span class="case-status-declaration__heading">{{
				t('dossiq', 'What this status asks of the case')
			}}</span>
			<span
				v-for="line in fieldRuleLines"
				:key="line"
				class="case-status-declaration__missing"
				data-testid="case-status-field-rule">
				{{ line }}
			</span>
		</p>

		<p
			v-if="dwellText"
			class="case-status-declaration__dwell"
			:class="{ 'is-breached': breached }"
			data-testid="case-status-dwell">
			{{ dwellText }}
			<span v-if="breached" class="case-status-declaration__chip">{{
				t('dossiq', 'longer than this status allows')
			}}</span>
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	derivationHeading,
	derivationReasons,
	dwellLabel,
	isDwellBreached,
	waitingOnLabel,
	withheldSentence,
	withheldTransitions,
} from '../../utils/statusDeclaration.js'
import {
	fieldRulesOf,
	hasFieldRules,
} from '../../utils/statusFieldRules.js'

export default {
	name: 'CaseStatusDeclarationPanel',

	props: {
		/** The case this strip belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/**
		 * The case as OpenRegister answered it, bound by CnDetailWidgetHost.
		 *
		 * It carries `@self.fieldRules`, already decided for this reader and
		 * this status. The strip reads it and nothing else: re-deciding it here
		 * would be a second evaluator, and the one on the screen is the one that
		 * would be wrong.
		 */
		objectData: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			loaded: false,
			current: {},
			derivation: null,
			withheldRaw: [],
		}
	},

	computed: {
		/**
		 * The case this strip is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * What is still missing for a derived status that has not fired.
		 *
		 * @return {{name: string, unmet: Array<string>}|null} The verdict.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		reasons() {
			return derivationReasons(this.derivation)
		},

		/**
		 * The sentence above the missing things.
		 *
		 * @return {string} The heading, or the empty string.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		heading() {
			return this.reasons ? derivationHeading(this.reasons.name) : ''
		},

		/**
		 * Who the case is waiting on, when it is not us.
		 *
		 * @return {string} The sentence, or the empty string.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		waiting() {
			return waitingOnLabel(this.current?.waitingOn)
		},

		/**
		 * How long the case has been in this status.
		 *
		 * @return {string} The sentence, or the empty string.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
		 */
		dwellText() {
			return dwellLabel(this.current?.dwell)
		},

		/**
		 * Whether that is longer than the status allows.
		 *
		 * @return {boolean} True when the status maximum is past.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		breached() {
			return isDwellBreached(this.current?.dwell)
		},

		/**
		 * The moves this case cannot make yet, and what is in the way.
		 *
		 * A withheld move is not missing: it is on the page with its reason in
		 * its place, which is the difference between a handler learning that
		 * the list is unreliable and a handler learning what to fetch next.
		 *
		 * @return {Array<object>} The entries worth rendering.
		 *
		 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
		 */
		withheld() {
			return withheldTransitions(this.withheldRaw)
		},

		/**
		 * The explanation an administrator wrote on this status.
		 *
		 * `statusType.description` has existed for as long as the schema has
		 * and nothing rendered it, so an administrator who wrote one was
		 * writing into a field nobody read.
		 *
		 * @return {string} The text, or the empty string.
		 *
		 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
		 */
		statusExplanation() {
			return String(this.current?.statusDescription ?? '').trim()
		},

		/**
		 * What this status requires, locks and hides, as sentences.
		 *
		 * Three lines at most, one per kind, each naming its fields. One line
		 * per FIELD would run to a paragraph on a status that locks eight of
		 * them, and the handler is reading this to find out whether to go and
		 * fill something in.
		 *
		 * @return {Array<string>} The lines, empty when the status asks nothing.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		fieldRuleLines() {
			const rules = fieldRulesOf(this.objectData)
			if (hasFieldRules(rules) === false) {
				return []
			}

			const lines = []
			if (rules.required.length > 0) {
				lines.push(
					t('dossiq', 'Fill in: {fields}', {
						fields: rules.required.join(', '),
					}),
				)
			}
			if (rules.readOnly.length > 0) {
				lines.push(
					t('dossiq', 'Cannot be changed here: {fields}', {
						fields: rules.readOnly.join(', '),
					}),
				)
			}
			if (rules.hidden.length > 0) {
				lines.push(
					t('dossiq', 'Not shown to you here: {fields}', {
						fields: rules.hidden.join(', '),
					}),
				)
			}

			return lines
		},

		/**
		 * Whether the strip has anything to say at all.
		 *
		 * @return {boolean} True when at least one of the three lines has text.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		show() {
			// The field rules are NOT behind `loaded`: they ride on the case
			// object the page already holds, not on the transition endpoint, so
			// an instance whose engine refuses still says what the status asks
			// of the fields. Gating them on `loaded` would have made the one
			// half that needs no round trip depend on the one that does.
			if (this.fieldRuleLines.length > 0) {
				return true
			}

			return (
				this.loaded
				&& (this.reasons !== null
					|| this.waiting !== ''
					|| this.dwellText !== ''
					|| this.statusExplanation !== ''
					|| this.withheld.length > 0)
			)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * What one withheld move is waiting on.
		 *
		 * @param {object} entry The withheld entry.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
		 */
		sentenceFor(entry) {
			return withheldSentence(entry)
		},

		/**
		 * Read what the case's current status declares.
		 *
		 * An instance whose engine cannot answer renders no strip, which is
		 * the right answer to "this app does not know what this status
		 * declares": the catch binds nothing and says so in words rather than
		 * swallowing the failure into an empty object that reads as "nothing
		 * declared".
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
		 */
		async load() {
			if (this.caseId === '') {
				return
			}

			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.caseId)}/available-transitions`,
					),
				)
				this.current = data?.current || {}
				this.derivation = data?.derivation || null
				this.withheldRaw = data?.withheld || []
				this.loaded = true
			} catch {
				// Binds nothing, deliberately. An instance whose transition
				// engine refuses, or whose case has no workflow at all, has
				// nothing to declare: no strip is the honest rendering of
				// that, and an error toast here would fire on every case page
				// of such an instance.
				this.loaded = false
			}
		},
	},
}
</script>

<style scoped>
.case-status-declaration {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
}

.case-status-declaration p {
	margin: 0;
}

.case-status-declaration__heading {
	display: block;
	font-weight: bold;
}

.case-status-declaration__missing {
	display: block;
	margin-inline-start: 12px;
}

.case-status-declaration__missing::before {
	content: '• ';
}

.case-status-declaration__withheld {
	margin: 0;
	padding-inline-start: 18px;
}

.case-status-declaration__status-explanation {
	color: var(--color-text-maxcontrast);
}

.case-status-declaration__dwell.is-breached {
	color: var(--color-warning-text);
	font-weight: bold;
}

.case-status-declaration__chip {
	margin-inline-start: 4px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 100px);
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	font-size: 0.85em;
	font-weight: normal;
}
</style>

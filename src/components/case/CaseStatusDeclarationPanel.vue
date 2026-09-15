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
			v-if="waiting"
			class="case-status-declaration__waiting"
			data-testid="case-status-waiting">
			{{ waiting }}
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
} from '../../utils/statusDeclaration.js'

export default {
	name: 'CaseStatusDeclarationPanel',

	props: {
		/** The case this strip belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loaded: false,
			current: {},
			derivation: null,
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
		 * Whether the strip has anything to say at all.
		 *
		 * @return {boolean} True when at least one of the three lines has text.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		show() {
			return (
				this.loaded
				&& (this.reasons !== null || this.waiting !== '' || this.dwellText !== '')
			)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
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
				this.loaded = true
			} catch (error) {
				// An instance whose transition engine refuses, or whose case
				// has no workflow at all, has nothing to declare. No strip is
				// the honest rendering of that, and an error toast here would
				// fire on every case page of such an instance.
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

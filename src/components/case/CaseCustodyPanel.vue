<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Who has held this case, and who is asking for it.

	The timeline beside this one says what CHANGED. This panel says who HELD
	it, which is the question a complaint, a WOO request or an internal review
	actually asks, and the one a sequence of diffs cannot answer.

	🔴 EVERY HOLDING IS DRAWN WITH BOTH ENDS. A row that showed only the unit
	and the start would look complete on a chain with a gap in it, which is the
	one defect this record exists to prevent. The open holding is the only one
	allowed to have no end, and it says so in words rather than by a blank.

	🔴 A REFUSAL IS NOT AN EMPTY CHAIN, AND THE TWO ARE DRAWN APART. Reading
	the custody needs read access to the case, so a reader without it is
	refused. Drawing that as "this case has never changed hands" would be a
	claim about the history that this reader was never told.

	🔴 THE PANEL WRITES NO HOLDING. Accept and refuse answer a REQUEST; the
	holding that follows an accept is opened server-side by the move itself. A
	browser that could write a holding would be a second way for the chain to
	disagree with the case.

	@spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
-->
<template>
	<div class="case-custody" data-testid="case-custody">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="refused"
			:name="t('dossiq', 'Only somebody who may open this case sees who has held it')"
			:description="t('dossiq', 'Nothing changed.')" />

		<NcEmptyContent
			v-else-if="failed"
			:name="t('dossiq', 'We could not read who has held this case')"
			:description="t('dossiq', 'The case has not moved. Try again in a moment.')" />

		<template v-else>
			<section class="case-custody__section">
				<h4 class="case-custody__heading">
					{{ t('dossiq', 'Who has held this case') }}
				</h4>

				<NcEmptyContent
					v-if="holdings.length === 0"
					:name="t('dossiq', 'This case has not changed hands yet')"
					:description="t('dossiq', 'Its first holding opens when the case is registered.')" />

				<ol v-else class="case-custody__chain">
					<li
						v-for="holding in holdings"
						:key="holding.id || holding.sequence"
						class="case-custody__holding"
						:class="{ 'case-custody__holding--open': holding.open === true }"
						data-testid="case-custody-holding">
						<span class="case-custody__unit">{{ holding.organisationUnit }}</span>
						<span v-if="holding.handler" class="case-custody__handler">
							{{ holding.handler }}
						</span>
						<span class="case-custody__period">{{ periodOf(holding) }}</span>
						<span v-if="holding.reason" class="case-custody__reason">
							{{ holding.reason }}
						</span>
					</li>
				</ol>
			</section>

			<section class="case-custody__section">
				<h4 class="case-custody__heading">
					{{ t('dossiq', 'Who is asking for it') }}
				</h4>

				<NcEmptyContent
					v-if="takeovers.length === 0"
					:name="t('dossiq', 'Nobody has asked for this case')"
					:description="t('dossiq', 'A colleague can ask the holder for it, with a reason.')" />

				<ul v-else class="case-custody__requests">
					<li
						v-for="request in takeovers"
						:key="request.id"
						class="case-custody__request"
						:data-status="request.status"
						data-testid="case-custody-request">
						<span class="case-custody__asker">{{ request.requestedBy }}</span>
						<span class="case-custody__request-reason">{{ request.reason }}</span>
						<span class="case-custody__status">{{ statusOf(request) }}</span>
						<span v-if="request.refusalReason" class="case-custody__refusal">
							{{ request.refusalReason }}
						</span>

						<span v-if="isOpen(request)" class="case-custody__answer">
							<NcButton
								variant="primary"
								:disabled="answering"
								data-testid="case-custody-accept"
								@click="accept(request)">
								{{ t('dossiq', 'Hand it over') }}
							</NcButton>
							<NcTextField
								v-model="refusalReasons[request.id]"
								:label="t('dossiq', 'Why you are keeping it')"
								data-testid="case-custody-refusal-reason" />
							<NcButton
								variant="secondary"
								:disabled="answering || !hasReason(request)"
								data-testid="case-custody-refuse"
								@click="refuse(request)">
								{{ t('dossiq', 'Keep it, and say why') }}
							</NcButton>
						</span>
					</li>
				</ul>
			</section>

			<p v-if="error" class="case-custody__error" data-testid="case-custody-error">
				{{ error }}
			</p>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	acceptTakeover,
	readChain,
	readTakeovers,
	refuseTakeover,
} from '../../services/custodyApi.js'

export default {
	name: 'CaseCustodyPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/** The case this panel belongs to, bound by CnTabsWidget. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			/** The read was refused because this reader may not open the case. */
			refused: false,
			/** The read failed for any other reason. */
			failed: false,
			/** An answer that did not land, said in words rather than swallowed. */
			error: '',
			/** True while an accept or refuse is in flight. */
			answering: false,
			holdings: [],
			takeovers: [],
			/** The reason typed against each open request, keyed by request id. */
			refusalReasons: {},
		}
	},

	computed: {
		/**
		 * The case this panel is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	watch: {
		caseId: {
			immediate: true,

			/**
			 * Read the chain of whichever case this panel is now about.
			 *
			 * `immediate`, and a watcher rather than `mounted()`, because the
			 * tab host mounts this panel before the route has settled on some
			 * paths: read once at mount and the id can still be empty, and the
			 * panel would then draw "no recorded holdings" for a case whose
			 * chain was never asked for.
			 *
			 * @return {void}
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the chain and the requests.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
		 */
		async load() {
			if (this.caseId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.refused = false
			this.failed = false
			this.error = ''

			try {
				const [chain, requests] = await Promise.all([
					readChain(this.caseId),
					readTakeovers(this.caseId),
				])
				this.holdings = chain.holdings ?? []
				this.takeovers = requests
			} catch (readError) {
				// 403 is the server saying this reader may not open the case,
				// which is a different answer from a case that never moved.
				if (readError?.response?.status === 403) {
					this.refused = true
				} else {
					this.failed = true
				}

				this.holdings = []
				this.takeovers = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether a request is still waiting for an answer.
		 *
		 * `escalated` counts as open: the question moved to the unit, it was
		 * not answered, and the two are not the same fact.
		 *
		 * @param {object} request The request row.
		 * @return {boolean} True when it can still be answered.
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
		 */
		isOpen(request) {
			return request?.status === 'pending' || request?.status === 'escalated'
		},

		/**
		 * Whether a refusal has been given a reason to carry.
		 *
		 * @param {object} request The request row.
		 * @return {boolean} True when there is something to send.
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
		 */
		hasReason(request) {
			return String(this.refusalReasons[request?.id] ?? '').trim() !== ''
		},

		/**
		 * Hand the case to whoever asked for it.
		 *
		 * @param {object} request The request row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
		 */
		async accept(request) {
			await this.answer(() => acceptTakeover(this.caseId, request.id))
		},

		/**
		 * Keep the case, and record why.
		 *
		 * @param {object} request The request row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
		 */
		async refuse(request) {
			const reason = String(this.refusalReasons[request?.id] ?? '').trim()
			if (reason === '') {
				this.error = t('dossiq', 'Say why you are keeping the case, so the answer means something.')
				return
			}

			await this.answer(() => refuseTakeover(this.caseId, request.id, reason))
		},

		/**
		 * Send one answer and read the case back.
		 *
		 * Re-reads rather than patching the row in place: an accept opens a
		 * holding this panel did not write, and a panel that only rewrote the
		 * request would show an answered request beside a chain that had not
		 * moved.
		 *
		 * @param {() => Promise<object>} send The call that carries the answer.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
		 */
		async answer(send) {
			this.answering = true
			this.error = ''

			try {
				await send()
				await this.load()
			} catch (answerError) {
				this.error = String(
					answerError?.response?.data?.message
						?? t('dossiq', 'We did not record the answer, so the case has not moved.'),
				)
			} finally {
				this.answering = false
			}
		},

		/**
		 * The period a holding ran for, in words.
		 *
		 * @param {object} holding The holding row.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
		 */
		periodOf(holding) {
			const from = this.dateOf(holding?.from)
			if (holding?.open === true) {
				return t('dossiq', 'Since {date}', { date: from })
			}

			return t('dossiq', 'From {from} to {to}', { from, to: this.dateOf(holding?.until) })
		},

		/**
		 * What a request stands at, in words.
		 *
		 * @param {object} request The request row.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
		 */
		statusOf(request) {
			const sentences = {
				pending: t('dossiq', 'Waiting for the holder'),
				accepted: t('dossiq', 'Handed over'),
				refused: t('dossiq', 'Kept by the holder'),
				escalated: t('dossiq', 'Nobody answered, so the question went to the unit'),
			}

			return sentences[request?.status] ?? String(request?.status ?? '')
		},

		/**
		 * One date, readable.
		 *
		 * @param {string} raw The stored moment.
		 * @return {string} The date, or the raw value when it cannot be read.
		 *
		 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
		 */
		dateOf(raw) {
			if (!raw) {
				return ''
			}

			const moment = new Date(raw)
			if (Number.isNaN(moment.getTime())) {
				return String(raw)
			}

			return moment.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.case-custody__section {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

.case-custody__heading {
	margin: 0 0 var(--default-grid-baseline, 4px) 0;
}

.case-custody__chain,
.case-custody__requests {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-custody__holding,
.case-custody__request {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.case-custody__holding--open .case-custody__unit {
	font-weight: bold;
}

.case-custody__period,
.case-custody__reason,
.case-custody__status,
.case-custody__refusal,
.case-custody__handler {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-custody__answer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	width: 100%;
}

.case-custody__error {
	color: var(--color-error);
}
</style>

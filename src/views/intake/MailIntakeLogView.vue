<template>
	<NcAppContent>
		<div class="intake-log">
			<h2 class="intake-log__title">
				{{ t('dossiq', 'Mail intake log') }}
			</h2>

			<NcNoteCard type="info">
				{{
					t(
						'dossiq',
						'Every message the intake mailbox processed. You see the filter that decided and what the sender checks found. A message that became no case says why.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard
				v-if="forbidden"
				type="error"
				data-testid="intake-log-forbidden">
				{{
					t(
						'dossiq',
						'You do not have the intake role, so you cannot read this log. It holds the original of every message the mailbox received. Ask an administrator to add you to the intake group.',
					)
				}}
			</NcNoteCard>

			<template v-if="!forbidden">
				<div class="intake-log__filters">
					<NcTextField
						v-model="sender"
						:label="t('dossiq', 'Search by sender')"
						placeholder="aanvrager@voorbeeld.nl"
						data-testid="intake-log-sender"
						@update:modelValue="reload" />

					<NcSelect
						v-model="outcomeOption"
						:inputLabel="t('dossiq', 'Outcome')"
						:options="outcomeOptions"
						:placeholder="t('dossiq', 'Every outcome')"
						data-testid="intake-log-outcome" />
				</div>

				<p
					v-if="filterOrder.length > 0"
					class="intake-log__order"
					data-testid="intake-log-order">
					{{
						t('dossiq', 'Filters run in this order: {order}', {
							order: filterOrder.join(', '),
						})
					}}
				</p>

				<NcLoadingIcon v-if="loading" :size="32" />

				<NcEmptyContent
					v-else-if="entries.length === 0"
					:name="t('dossiq', 'Nothing processed yet')"
					:description="
						t(
							'dossiq',
							'No message matches this search. A message we never received leaves no entry. Neither does one your mail server filtered before it reached the folder.',
						)
					" />

				<table
					v-else
					class="intake-log__table"
					data-testid="intake-log-table">
					<thead>
						<tr>
							<th scope="col">{{ t('dossiq', 'Sender') }}</th>
							<th scope="col">{{ t('dossiq', 'Subject') }}</th>
							<th scope="col">{{ t('dossiq', 'Deciding filter') }}</th>
							<th scope="col">{{ t('dossiq', 'SPF') }}</th>
							<th scope="col">{{ t('dossiq', 'DKIM') }}</th>
							<th scope="col">{{ t('dossiq', 'DMARC') }}</th>
							<th scope="col">{{ t('dossiq', 'Threading') }}</th>
							<th scope="col">{{ t('dossiq', 'Outcome') }}</th>
							<th scope="col">{{ t('dossiq', 'Reason') }}</th>
							<th scope="col" />
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="entry in entries"
							:key="entryId(entry)"
							:data-testid="`intake-log-row-${entryId(entry)}`">
							<td>{{ entry.sender }}</td>
							<td>{{ entry.subject }}</td>
							<td>
								{{
									entry.decidingFilter
									|| t('dossiq', 'none, accepted by default')
								}}
							</td>
							<td :class="resultClass(entry.spfResult)">
								{{ resultLabel(entry.spfResult) }}
							</td>
							<td :class="resultClass(entry.dkimResult)">
								{{ resultLabel(entry.dkimResult) }}
							</td>
							<td :class="resultClass(entry.dmarcResult)">
								{{ resultLabel(entry.dmarcResult) }}
							</td>
							<td :class="resultClass(entry.threadingResult)">
								{{ resultLabel(entry.threadingResult) }}
							</td>
							<td>{{ outcomeLabel(entry.outcome) }}</td>
							<td>
								{{
									entry.junkRule ? junkReason(entry) : entry.reason
								}}
							</td>
							<td>
								<NcButton
									v-if="entry.outcome === 'quarantined'"
									variant="secondary"
									:data-testid="`intake-log-release-${entryId(entry)}`"
									@click="release(entry)">
									{{ t('dossiq', 'Release') }}
								</NcButton>
								<NcButton
									v-if="entry.junkRule"
									variant="tertiary"
									:data-testid="`intake-log-not-junk-${entryId(entry)}`"
									@click="markNotJunk(entry)">
									{{ t('dossiq', 'Not junk') }}
								</NcButton>
								<!-- Offered on EVERY entry, not only on one that
								     became no case: a wrong match is the common
								     reason somebody reaches for this, and an
								     entry that became case 2026-090 when it
								     belonged on 2026-114 looks exactly like a
								     successful match until a person reads it. -->
								<NcButton
									variant="tertiary"
									:data-testid="`intake-log-file-on-case-${entryId(entry)}`"
									@click="openFileOnCase(entry)">
									{{ t('dossiq', 'File on a case') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<NcDialog
			v-if="filing !== null"
			:name="t('dossiq', 'File this message on a case')"
			size="normal"
			@closing="filing = null">
			<div class="intake-log-file-on-case">
				<NcNoteCard v-if="filing.case" type="info">
					{{
						t(
							'dossiq',
							'The matcher filed this message on {case}. Filing it elsewhere records that you overrode it; it changes no matching rule.',
							{ case: filing.case },
						)
					}}
				</NcNoteCard>

				<NcTextField
					:modelValue="fileOnCaseId"
					:label="t('dossiq', 'Case')"
					data-testid="intake-log-case-id"
					@update:modelValue="(v) => (fileOnCaseId = v)" />

				<NcTextField
					:modelValue="fileOnCaseReason"
					:label="t('dossiq', 'Why this case')"
					data-testid="intake-log-file-reason"
					@update:modelValue="(v) => (fileOnCaseReason = v)" />

				<NcNoteCard v-if="fileOnCaseError" type="error">
					{{ fileOnCaseError }}
				</NcNoteCard>

				<div class="intake-log-file-on-case__actions">
					<NcButton
						variant="primary"
						:disabled="filingBusy || !fileOnCaseId || !fileOnCaseReason"
						data-testid="intake-log-file-confirm"
						@click="confirmFileOnCase">
						{{ t('dossiq', 'File it here') }}
					</NcButton>
					<NcButton @click="filing = null">
						{{ t('dossiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</NcAppContent>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The intake log as a surface rather than a log file.
 *
 * 🔴 IT READS THROUGH THE DOSSIQ ENDPOINT, NOT THE GENERIC OBJECT API. The
 * entries hold the original source of every message the mailbox received, and
 * the intake-role check that guards them lives in MailIntakeController. A view
 * that read the same objects through the generic register endpoint would show
 * them to anyone the register lets read, which is the one thing the spec says
 * must not happen.
 *
 * 🔴 AND `unavailable` IS RENDERED AS ITS OWN THING. A check nobody made and a
 * check that passed are different facts, so they get different words and
 * different colours. Showing an absent SPF header as a blank cell would let a
 * reader take it for a pass.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
export default {
	name: 'MailIntakeLogView',
	components: {
		NcAppContent,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			forbidden: false,
			entries: [],
			filterOrder: [],
			junkRules: [],
			sender: '',
			outcome: '',
			// The entry a handler is filing by hand, or null.
			filing: null,
			fileOnCaseId: '',
			fileOnCaseReason: '',
			fileOnCaseError: '',
			filingBusy: false,
		}
	},

	computed: {
		/**
		 * The outcomes a reader can narrow the log to.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		outcomeOptions() {
			return [
				{ id: 'case', label: t('dossiq', 'Became a case') },
				{ id: 'quarantined', label: t('dossiq', 'Held for a person') },
				{ id: 'released', label: t('dossiq', 'Released by hand') },
				{ id: 'inbox', label: t('dossiq', 'Waiting in the intake inbox') },
				{ id: 'refused', label: t('dossiq', 'Refused') },
				{ id: 'forwarded', label: t('dossiq', 'Sent on to another body') },
				{ id: 'moved', label: t('dossiq', 'Filed in another folder') },
			]
		},

		/**
		 * The outcome currently filtered on.
		 *
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		outcomeOption: {
			/**
			 * @return {object|null} The chosen option.
			 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
			 */
			get() {
				return this.outcomeOptions.find((o) => o.id === this.outcome) || null
			},

			/**
			 * @param {object|null} option The chosen option.
			 * @return {void}
			 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
			 */
			set(option) {
				this.outcome = option ? option.id : ''
				this.reload()
			},
		},
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		/**
		 * Read the log, narrowed by whatever the reader typed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		async reload() {
			this.loading = true
			try {
				const params = new URLSearchParams()
				if (this.sender) {
					params.set('sender', this.sender)
				}
				if (this.outcome) {
					params.set('outcome', this.outcome)
				}
				const response = await fetch(
					generateUrl(
						'/apps/dossiq/api/mail-intake/log?' + params.toString(),
					),
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				if (response.status === 403) {
					this.forbidden = true
					this.entries = []
					return
				}
				this.forbidden = false
				const data = await response.json()
				this.entries = data?.results || []
				this.filterOrder = data?.filterOrder || []
				this.junkRules = data?.junkRules || []
			} catch {
				// A read that failed shows nothing rather than a stale list. The
				// empty state says a message that was never received leaves no
				// entry, which is the honest reading of an empty table.
				this.entries = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * One entry's id.
		 *
		 * @param {object} entry The entry.
		 * @return {string} The id.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		entryId(entry) {
			return String(entry?.['@self']?.id || entry?.id || '')
		},

		/**
		 * Release a held message into a case.
		 *
		 * @param {object} entry The entry.
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		/**
		 * Open the picker on one entry.
		 *
		 * @param {object} entry The log entry.
		 * @return {void}
		 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
		 */
		openFileOnCase(entry) {
			this.filing = entry
			// PRE-FILLED WITH WHERE IT IS, not blank: the common gesture is
			// correcting a match, and a handler who has to retype the right
			// case beside a field that forgot the wrong one cannot see what
			// they are changing.
			this.fileOnCaseId = String(entry.case || '')
			this.fileOnCaseReason = ''
			this.fileOnCaseError = ''
		},

		/**
		 * File the message on the case the handler picked.
		 *
		 * The reason is REQUIRED by the endpoint, and the button is disabled
		 * without one, so the refusal is visible before the click rather than
		 * after it. This records ONE person's correction of ONE message; it
		 * changes no matching rule.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
		 */
		async confirmFileOnCase() {
			if (this.filing === null) {
				return
			}
			this.filingBusy = true
			this.fileOnCaseError = ''
			try {
				const response = await fetch(
					generateUrl(
						`/apps/dossiq/api/mail-intake/log/${this.entryId(this.filing)}/file-on-case`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							caseId: this.fileOnCaseId,
							reason: this.fileOnCaseReason,
						}),
					},
				)

				// 🔴 A RESPONSE IS NOT A RESULT. The endpoint answers 403 for a
				// case this caller may not read, and closing the dialog on it
				// would tell the handler the message moved when it did not.
				if (!response.ok) {
					const body = await response.json().catch(() => ({}))
					this.fileOnCaseError =
						body.message === 'Not authorized'
							? t(
									'dossiq',
									'You cannot read that case, so the message was not filed on it.',
								)
							: t('dossiq', 'The message was not filed. Check the case number.')
					return
				}

				this.filing = null
				await this.reload()
			} catch {
				this.fileOnCaseError = t('dossiq', 'The message was not filed.')
			} finally {
				this.filingBusy = false
			}
		},

		async release(entry) {
			await this.post(`${this.entryId(entry)}/release`, {})
			await this.reload()
		},

		/**
		 * Correct a wrong junk verdict, which sends the message back through
		 * the pipeline.
		 *
		 * @param {object} entry The entry.
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		async markNotJunk(entry) {
			await this.post(`${this.entryId(entry)}/junk`, { junk: false })
			await this.reload()
		},

		/**
		 * Post to one of the intake endpoints.
		 *
		 * @param {string} path The path after the log entry base.
		 * @param {object} body The request body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		async post(path, body) {
			await fetch(generateUrl(`/apps/dossiq/api/mail-intake/log/${path}`), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify(body),
			})
		},

		/**
		 * What one authentication result is called on screen.
		 *
		 * @param {string} result The stored result.
		 * @return {string} The label.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		resultLabel(result) {
			const labels = {
				pass: t('dossiq', 'passed'),
				fail: t('dossiq', 'failed'),
				none: t('dossiq', 'no policy published'),
				unavailable: t('dossiq', 'not checked'),
			}
			return labels[result] || t('dossiq', 'not checked')
		},

		/**
		 * The class that colours one authentication result.
		 *
		 * @param {string} result The stored result.
		 * @return {string} The class.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		resultClass(result) {
			if (result === 'pass') {
				return 'intake-log__result--pass'
			}
			if (result === 'fail') {
				return 'intake-log__result--fail'
			}
			return 'intake-log__result--unknown'
		},

		/**
		 * What one outcome is called on screen.
		 *
		 * @param {string} outcome The stored outcome.
		 * @return {string} The label.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		outcomeLabel(outcome) {
			const option = this.outcomeOptions.find((o) => o.id === outcome)
			return option ? option.label : outcome
		},

		/**
		 * The readable rule behind a junk verdict.
		 *
		 * @param {object} entry The entry.
		 * @return {string} The rule, described.
		 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
		 */
		junkReason(entry) {
			const rule = this.junkRules.find((r) => r.name === entry.junkRule)
			if (rule) {
				return `${rule.name}: ${rule.description}`
			}
			return entry.junkRule
		},
	},
}
</script>

<style scoped>
.intake-log {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.intake-log__title {
	margin: 0;
}

.intake-log__filters {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.intake-log__order {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.intake-log__table {
	width: 100%;
	border-collapse: collapse;
}

.intake-log__table th,
.intake-log__table td {
	border-bottom: 1px solid var(--color-border);
	padding: 6px 8px;
	text-align: start;
	vertical-align: top;
}

.intake-log__result--pass {
	color: var(--color-success-text);
}

.intake-log__result--fail {
	color: var(--color-error-text);
}

.intake-log__result--unknown {
	color: var(--color-text-maxcontrast);
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseConversationsPanel — the live conversation on the case.

  Starts a conversation in Nextcloud Talk from any case, not only from a
  bezwaar hoorzitting, and lists what the case already recorded: the moment,
  who joined and how long it lasted. It also carries the major declaration,
  which opens exactly one working channel with the responders the case type
  names.

  dossiq ships no calling, no recorder and no signalling. Where the instance
  has no Talk, this panel says so instead of offering a button that cannot
  work, because a dead affordance costs a handler a click and a guess.

  @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
-->
<template>
	<div class="case-conversations">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="talkUnavailable"
			:name="t('dossiq', 'Talk is not available')"
			:description="
				t(
					'dossiq',
					'A live conversation runs in Nextcloud Talk. Install Talk to start one from this case.',
				)
			">
			<template #icon>
				<PhoneOff :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="case-conversations__actions">
				<NcButton
					variant="primary"
					:disabled="starting"
					data-testid="start-conversation"
					@click="startConversation">
					<template #icon>
						<PhoneInTalk :size="20" />
					</template>
					{{ t('dossiq', 'Start a conversation') }}
				</NcButton>

				<NcButton
					v-if="!isMajor"
					:disabled="declaring"
					data-testid="declare-major"
					@click="declareMajor">
					<template #icon>
						<AlertOutline :size="20" />
					</template>
					{{ t('dossiq', 'Declare major') }}
				</NcButton>

				<NcButton
					v-else
					variant="secondary"
					:href="majorChannelUrl"
					data-testid="open-major-channel"
					@click="openMajorChannel">
					<template #icon>
						<AlertOutline :size="20" />
					</template>
					{{ t('dossiq', 'Open the working channel') }}
				</NcButton>
			</div>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('dossiq', 'We could not start the conversation')">
				{{ error }}
			</NcNoteCard>

			<NcEmptyContent
				v-if="!conversations.length"
				:name="t('dossiq', 'No conversations yet')"
				:description="
					t(
						'dossiq',
						'Every conversation you start from this case lands here. You see when it was held, who joined and how long it lasted.',
					)
				">
				<template #icon>
					<PhoneInTalk :size="48" />
				</template>
			</NcEmptyContent>

			<ul
				v-else
				class="case-conversations__list"
				data-testid="conversation-records">
				<li
					v-for="record in conversations"
					:key="record.roomId"
					class="case-conversations__record">
					<span class="case-conversations__subject">{{
						record.subject
					}}</span>
					<span class="case-conversations__meta">{{
						recordSummary(record)
					}}</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import PhoneInTalk from 'vue-material-design-icons/PhoneInTalk.vue'
import PhoneOff from 'vue-material-design-icons/PhoneOff.vue'

export default {
	name: 'CaseConversationsPanel',

	components: {
		AlertOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		PhoneInTalk,
		PhoneOff,
	},

	props: {
		/** Case UUID. The manifest passes this as :id; CaseDetail injects it inline. */
		caseId: {
			type: String,
			default: null,
		},

		/** Inline case object, so the panel can read what is already loaded. */
		caseObject: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			starting: false,
			declaring: false,
			talkUnavailable: false,
			error: '',
			conversations: [],
			isMajor: false,
			majorChannelUrl: '',
		}
	},

	computed: {
		resolvedCaseId() {
			return this.caseId || this.$route?.params?.id || null
		},
	},

	watch: {
		caseObject: {
			immediate: true,
			handler(value) {
				this.applyCaseObject(value)
			},
		},
	},

	async mounted() {
		await this.loadAvailability()
	},

	methods: {
		/**
		 * Read what the case already records, so the panel shows history before
		 * anybody presses anything.
		 *
		 * @param {object|null} value The case as loaded.
		 * @return {void}
		 *
		 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
		 */
		applyCaseObject(value) {
			if (!value) {
				return
			}
			this.conversations = Array.isArray(value.conversations)
				? value.conversations
				: []
			this.isMajor = value.isMajor === true
			this.majorChannelUrl = value.majorChannel?.roomUrl || ''
		},

		/**
		 * Ask whether this instance can hold a conversation at all.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
		 */
		async loadAvailability() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/dossiq/api/conversations/availability'),
				)
				this.talkUnavailable = data?.available !== true
			} catch {
				this.talkUnavailable = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Start a conversation from this case and open the room it created.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
		 */
		async startConversation() {
			if (!this.resolvedCaseId) {
				return
			}
			this.starting = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/cases/{caseId}/conversations', {
						caseId: this.resolvedCaseId,
					}),
					{},
				)
				this.conversations = [...this.conversations, data.conversation]
				if (data.conversation?.roomUrl) {
					window.open(data.conversation.roomUrl, '_blank', 'noopener')
				}
			} catch (e) {
				this.error =
					e?.response?.data?.reason || t('dossiq', 'Unknown error')
			} finally {
				this.starting = false
			}
		},

		/**
		 * Declare this case major, which opens exactly one working channel.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
		 */
		async declareMajor() {
			if (!this.resolvedCaseId) {
				return
			}
			this.declaring = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/cases/{caseId}/major', {
						caseId: this.resolvedCaseId,
					}),
					{},
				)
				this.isMajor = true
				this.majorChannelUrl = data.channel?.roomUrl || ''
				this.openMajorChannel()
			} catch (e) {
				const unresolved = e?.response?.data?.unresolved
				this.error =
					Array.isArray(unresolved) && unresolved.length
						? t(
								'dossiq',
								'We could not find these responders: {names}',
								{ names: unresolved.join(', ') },
							)
						: e?.response?.data?.reason || t('dossiq', 'Unknown error')
			} finally {
				this.declaring = false
			}
		},

		/**
		 * Open the one channel this case has, rather than opening a second.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
		 */
		openMajorChannel() {
			if (this.majorChannelUrl) {
				window.open(this.majorChannelUrl, '_blank', 'noopener')
			}
		},

		/**
		 * One line saying when a conversation was held, who joined and for how long.
		 *
		 * @param {object} record The conversation record on the case.
		 * @return {string} The summary line.
		 *
		 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
		 */
		recordSummary(record) {
			const parts = []
			if (record?.startedAt) {
				parts.push(new Date(record.startedAt).toLocaleString())
			}
			const participants = Array.isArray(record?.participants)
				? record.participants
				: []
			if (participants.length) {
				parts.push(participants.join(', '))
			}
			if (record?.durationSeconds) {
				parts.push(
					t('dossiq', '{minutes} min', {
						minutes: Math.round(record.durationSeconds / 60),
					}),
				)
			}
			return parts.join(' · ')
		},
	},
}
</script>

<style scoped>
.case-conversations__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 4px);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 3);
}

.case-conversations__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-conversations__record {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding-block: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
}

.case-conversations__subject {
	font-weight: bold;
}

.case-conversations__meta {
	color: var(--color-text-maxcontrast);
}
</style>

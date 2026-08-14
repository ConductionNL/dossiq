<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Case-assistant chat panel (case-assistant-via-hermiq) for the manifest
  CaseDetail overview. Conversational assistance is delegated to Hermiq
  (fleet rule: AI functionality lives in Hermiq); this panel only renders the
  transcript and posts messages to procest's thin /api/assistant/converse
  consumer endpoint, which enriches with authorized case context and forwards.

  Availability-gated like InitiatorSection renders-nothing-when-empty: when
  Hermiq is not installed/enabled (GET /api/assistant/availability → false)
  the panel renders NOTHING — no permanently-erroring chrome.

  @spec openspec/specs/case-assistant-via-hermiq/spec.md
-->
<template>
	<div v-if="available" class="case-assistant" data-testid="case-assistant-panel">
		<div ref="transcript" class="case-assistant__transcript">
			<p v-if="transcript.length === 0" class="case-assistant__empty">
				{{
					t(
						'procest',
						'Ask a question about this case. Answers are based only on case data you can already see.',
					)
				}}
			</p>
			<div
				v-for="(entry, idx) in transcript"
				:key="idx"
				class="case-assistant__message"
				:class="`case-assistant__message--${entry.role}`">
				<p>{{ entry.content }}</p>
			</div>
			<div v-if="loading" class="case-assistant__loading">
				<NcLoadingIcon :size="20" />
				<span>{{ t('procest', 'The assistant is thinking…') }}</span>
			</div>
			<p v-if="errorMessage" class="case-assistant__error" role="alert">
				{{ errorMessage }}
			</p>
		</div>
		<div class="case-assistant__composer">
			<NcTextField
				:model-value="draft"
				:label="t('procest', 'Ask the assistant')"
				:placeholder="t('procest', 'Ask a question about this case…')"
				:disabled="loading"
				data-testid="case-assistant-input"
				@update:model-value="(v) => (draft = v)"
				@keydown.enter.prevent="onSend" />
			<NcButton
				type="primary"
				:disabled="!sendAllowed"
				:aria-label="t('procest', 'Send')"
				data-testid="case-assistant-send"
				@click="onSend">
				{{ t('procest', 'Send') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import {
	fetchAssistantAvailability,
	converse,
} from '../../../services/assistantApi.js'
import {
	makeTranscriptEntry,
	canSend,
	assistantErrorMessage,
} from '../../../utils/assistantHelpers.js'

export default {
	name: 'CaseAssistantPanel',
	components: { NcButton, NcTextField, NcLoadingIcon },
	data() {
		return {
			available: false,
			transcript: [],
			draft: '',
			loading: false,
			errorMessage: '',
		}
	},
	computed: {
		/** @spec openspec/specs/case-assistant-via-hermiq/spec.md */
		caseId() {
			return this.$route?.params?.id || null
		},
		/** @spec openspec/specs/case-assistant-via-hermiq/spec.md */
		sendAllowed() {
			return canSend(this.draft, this.loading)
		},
	},
	async mounted() {
		// Feature gate: Hermiq absent/disabled → render nothing.
		this.available = await fetchAssistantAvailability()
	},
	methods: {
		t,
		/**
		 * Send the drafted message and append the assistant's reply.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
		 */
		async onSend() {
			if (!this.sendAllowed || !this.caseId) {
				return
			}
			const message = this.draft.trim()
			this.draft = ''
			this.errorMessage = ''
			this.transcript.push(makeTranscriptEntry('user', message))
			this.loading = true
			try {
				const response = await converse(this.caseId, message)
				this.transcript.push(
					makeTranscriptEntry('assistant', response.reply || ''),
				)
			} catch (e) {
				this.errorMessage = assistantErrorMessage(e)
			} finally {
				this.loading = false
				this.$nextTick(() => this.scrollToEnd())
			}
		},
		/**
		 * Keep the newest message in view.
		 *
		 * @return {void}
		 * @spec exclude Presentation-only scroll nudge; no behavioural contract.
		 */
		scrollToEnd() {
			const el = this.$refs.transcript
			if (el) {
				el.scrollTop = el.scrollHeight
			}
		},
	},
}
</script>

<style scoped>
.case-assistant {
	display: flex;
	flex-direction: column;
	gap: 8px;
	height: 100%;
	min-height: 0;
}

.case-assistant__transcript {
	flex: 1 1 auto;
	min-height: 120px;
	max-height: 320px;
	overflow-y: auto;
	padding: 4px;
}

.case-assistant__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.case-assistant__message {
	padding: 8px 12px;
	margin: 4px 0;
	border-radius: var(--border-radius-large);
	max-width: 85%;
}

.case-assistant__message--user {
	background-color: var(--color-primary-element-light);
	margin-inline-start: auto;
}

.case-assistant__message--assistant {
	background-color: var(--color-background-dark);
	margin-inline-end: auto;
}

.case-assistant__message p {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.case-assistant__loading {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	padding: 4px;
}

.case-assistant__error {
	color: var(--color-error);
	padding: 4px;
}

.case-assistant__composer {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.case-assistant__composer > :first-child {
	flex: 1 1 auto;
}
</style>

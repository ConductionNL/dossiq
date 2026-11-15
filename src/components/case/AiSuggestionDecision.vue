<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What a person does with one AI suggestion: use it, adapt it and use that, or
  reject it. Using copies the text. The parent reports the decision to the AI
  oversight trail; this component only asks.

  @spec openspec/specs/ai-oversight-dispatch/spec.md
-->
<template>
	<div class="ai-suggestion-decision" :data-testid="testid">
		<p
			v-if="decided"
			class="ai-suggestion-decision__done"
			:data-testid="`${testid}-done`">
			{{ decided }}
		</p>
		<template v-else>
			<template v-if="editing">
				<label :for="`${testid}-draft`">
					{{ t('dossiq', 'Your version') }}
				</label>
				<textarea
					:id="`${testid}-draft`"
					v-model="draft"
					rows="4"
					:data-testid="`${testid}-draft`" />
			</template>
			<div class="ai-suggestion-decision__actions">
				<NcButton
					v-if="editing"
					variant="primary"
					:data-testid="`${testid}-use-adapted`"
					@click="decide('modified')">
					{{ t('dossiq', 'Use my version') }}
				</NcButton>
				<template v-else>
					<NcButton
						variant="primary"
						:data-testid="`${testid}-use`"
						@click="decide('accepted')">
						{{ t('dossiq', 'Use this') }}
					</NcButton>
					<NcButton :data-testid="`${testid}-adapt`" @click="startEditing">
						{{ t('dossiq', 'Adapt') }}
					</NcButton>
				</template>
				<NcButton
					variant="tertiary"
					:data-testid="`${testid}-reject`"
					@click="decide('rejected')">
					{{ t('dossiq', 'Reject') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'AiSuggestionDecision',
	components: { NcButton },

	props: {
		/**
		 * The suggestion as shown.
		 *
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		text: {
			type: String,
			required: true,
		},

		/** Test id prefix. */
		testid: {
			type: String,
			default: 'ai-suggestion-decision',
		},
	},

	emits: ['decide'],

	data() {
		return {
			editing: false,
			draft: '',
			decided: '',
		}
	},

	watch: {
		/**
		 * A new suggestion starts undecided.
		 *
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		text() {
			this.editing = false
			this.draft = ''
			this.decided = ''
		},
	},

	methods: {
		t,

		/**
		 * Open the suggestion for editing.
		 *
		 * @return {void}
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		startEditing() {
			this.draft = this.text
			this.editing = true
		},

		/**
		 * Take a decision, copy what is used, and tell the parent.
		 *
		 * @param {string} userAction accepted, modified or rejected.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		async decide(userAction) {
			const used = userAction === 'modified' ? this.draft : this.text
			this.$emit('decide', {
				userAction,
				actualValue: userAction === 'modified' ? this.draft : undefined,
			})
			this.decided = {
				accepted: t('dossiq', 'Used. Copied to your clipboard.'),
				modified: t(
					'dossiq',
					'Your version is used. Copied to your clipboard.',
				),

				rejected: t('dossiq', 'Rejected.'),
			}[userAction]
			if (userAction === 'rejected') {
				return
			}

			try {
				await navigator.clipboard.writeText(used)
			} catch {
				this.decided =
					userAction === 'modified'
						? t('dossiq', 'Your version is used.')
						: t('dossiq', 'Used.')
			}
		},
	},
}
</script>

<style scoped>
.ai-suggestion-decision {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.ai-suggestion-decision__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.ai-suggestion-decision__done {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
</style>

<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Answer a question asked on the case and publish the answer.

  Lists the questions of this case that are not published or rejected yet,
  takes the answer, stores it on the question and walks the question's own
  lifecycle: `answer` (unless it is answered already), then `publish`, then
  stamps when it was published. The lifecycle lives on the schema, so the
  server refuses a step the question's state does not allow; this dialog does
  not decide that. Generic over the schema: the manifest names the question
  schema and the field that points at the case.

  @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Publish an answer')"
		data-testid="answer-publish-dialog"
		@closing="$emit('close')">
		<div class="answer-publish">
			<p v-if="loading" data-testid="answer-publish-loading">
				{{ t('dossiq', 'Loading the open questions …') }}
			</p>
			<p v-else-if="questions.length === 0" data-testid="answer-publish-empty">
				{{ t('dossiq', 'There is no open question to answer on this case.') }}
			</p>
			<template v-else>
				<NcSelect
					v-model="questionId"
					data-testid="answer-publish-question"
					:inputLabel="t('dossiq', 'Question')"
					:options="questionOptions"
					:reduce="(option) => option.id"
					label="label" />
				<NcTextArea
					v-model="answer"
					data-testid="answer-publish-answer"
					:label="t('dossiq', 'Answer')"
					resize="vertical" />
				<p class="answer-publish__note">
					{{ t('dossiq', 'A published answer is visible to every party and can no longer be changed.') }}
				</p>
			</template>
			<p
				v-if="error"
				class="answer-publish__error"
				role="alert"
				data-testid="answer-publish-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="answer-publish-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="answer-publish-submit"
				:disabled="!canPublish"
				variant="primary"
				@click="publish">
				<template #icon>
					<CommentCheckOutline :size="20" />
				</template>
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import CommentCheckOutline from 'vue-material-design-icons/CommentCheckOutline.vue'

/** Question states that are finished: nothing left to answer. */
const CLOSED_STATES = ['published', 'rejected']

/** The state from which only `publish` remains. */
const ANSWERED_STATE = 'answered'

/** How long a question's text may be in the picker before it is cut. */
const LABEL_LENGTH = 90

export default {
	name: 'AnswerPublishDialog',

	components: { CommentCheckOutline, NcButton, NcDialog, NcSelect, NcTextArea },

	props: {
		/** The case uuid the questions point at. */
		caseId: { type: String, required: true },
		/** The register of the question schema. */
		register: { type: String, default: 'dossiq' },
		/** The question schema; it declares the `answer` and `publish` transitions. */
		schema: { type: String, default: 'tenderQuestion' },
		/** The field of a question that holds the case uuid. */
		caseField: { type: String, default: 'tender' },
	},

	emits: ['close', 'done'],

	data() {
		return {
			questions: [],
			questionId: null,
			answer: '',
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The picker options: the question text, cut to one line.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		questionOptions() {
			return this.questions.map((question) => ({
				id: question.id,
				label: this.labelOf(question),
			}))
		},

		/**
		 * The chosen question record.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		selected() {
			return this.questions.find((question) => question.id === this.questionId) ?? null
		},

		/**
		 * The primary button's label, telling while the answer is being published.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		submitLabel() {
			return this.saving ? t('dossiq', 'Publishing …') : t('dossiq', 'Publish answer')
		},

		/**
		 * Whether there is a question and an answer to publish.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		canPublish() {
			return this.saving === false && this.selected !== null && this.answer.trim() !== ''
		},
	},

	watch: {
		/**
		 * Start from the answer already written on the chosen question.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		selected() {
			this.answer = String(this.selected?.answer ?? '')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * The URL of the question collection, or of one question.
		 *
		 * @param {string} [id] The question id.
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		objectUrl(id = '') {
			const base = `/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}`
			return generateUrl(id === '' ? base : `${base}/${encodeURIComponent(id)}`)
		},

		/**
		 * One line naming a question in the picker.
		 *
		 * @param {object} question The question record.
		 * @return {string}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		labelOf(question) {
			const text = String(question.question ?? '').replace(/\s+/g, ' ').trim()
			if (text.length <= LABEL_LENGTH) {
				return text
			}
			return text.slice(0, LABEL_LENGTH - 1) + '…'
		},

		/**
		 * Read the questions of this case that are still open, oldest first.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(this.objectUrl(), {
					params: { [this.caseField]: this.caseId, _order: { submittedAt: 'asc' }, _limit: 100 },
				})
				const rows = Array.isArray(data?.results) ? data.results : []
				this.questions = rows
					.map((row) => ({ ...row, id: row.id ?? row['@self']?.id ?? row.uuid }))
					.filter((row) => row.id && CLOSED_STATES.includes(row.state) === false)
				if (this.questions.length === 1) {
					this.questionId = this.questions[0].id
				}
			} catch {
				this.questions = []
				this.error = t('dossiq', 'The questions of this case could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Store the answer, walk the question to published and stamp the moment.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
		 */
		async publish() {
			if (this.canPublish === false) {
				return
			}
			const question = this.selected
			this.saving = true
			this.error = ''
			try {
				await axios.patch(this.objectUrl(question.id), { answer: this.answer.trim() })
				const transition = generateUrl(
					`/apps/openregister/api/objects/${encodeURIComponent(question.id)}/transition`,
				)
				if (question.state !== ANSWERED_STATE) {
					await axios.post(transition, { action: 'answer' })
				}
				await axios.post(transition, { action: 'publish' })
				const { data } = await axios.patch(this.objectUrl(question.id), {
					publishedAt: new Date().toISOString(),
				})
				showSuccess(t('dossiq', 'Answer published.'))
				this.$emit('done', data)
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('dossiq', 'The answer could not be published.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.answer-publish {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.answer-publish__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.answer-publish__error {
	color: var(--color-error-text);
}
</style>

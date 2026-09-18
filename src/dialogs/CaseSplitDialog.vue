<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Dividing this case into two.

  Two complaints arrive on one form and turn out to be about two different
  departments. Copying the case leaves both halves holding everything and the
  handler cleaning up by hand. A split MOVES: the documents and parties ticked
  here leave this case and land on the new one.

  The lists are ticked, not dragged, and nothing is ticked to begin with. A
  split cannot be undone by unticking afterwards, and a preselected division is
  a division that gets confirmed.

  A party can be marked as belonging to BOTH halves. That is not a duplicate: a
  role is a party's place in a case, and a party relevant to both halves has a
  place in both.

  The refusals are the server's and are shown verbatim beside their code,
  because "documents may not be divided on this case type" sends the handler to
  the case type administrator and a bare no sends them to the wrong person.

  @spec openspec/changes/case-split-surface/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Split this case in two')"
		data-testid="case-split-dialog"
		size="large"
		@closing="$emit('close')">
		<div class="case-split-dialog">
			<p class="case-split-dialog__explainer">
				{{
					t(
						'dossiq',
						'What you tick moves to the new case and leaves this one. What you leave alone stays here.',
					)
				}}
			</p>

			<NcTextField
				v-model="title"
				data-testid="case-split-title"
				:label="t('dossiq', 'Title of the new case')" />

			<section
				v-for="kind in kinds"
				:key="kind.id"
				class="case-split-dialog__kind">
				<h3>{{ kind.label }}</h3>
				<p
					v-if="rows[kind.id].length === 0"
					class="case-split-dialog__empty">
					{{ t('dossiq', 'Nothing of this kind on the case.') }}
				</p>
				<ul v-else class="case-split-dialog__rows">
					<li v-for="row in rows[kind.id]" :key="row.id">
						<NcCheckboxRadioSwitch
							:modelValue="chosen[kind.id].includes(row.id)"
							:data-testid="`case-split-${kind.id}-${row.id}`"
							@update:modelValue="(v) => toggle(kind.id, row.id, v)">
							{{ row.label }}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
			</section>

			<p
				v-if="error"
				class="case-split-dialog__error"
				data-testid="case-split-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-split-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-split-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Split') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseSplitDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},

	props: {
		/**
		 * The case being split. Absent when the manifest opened the dialog: an
		 * `open-modal` action carries no object context, so the route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			title: '',
			allowed: [],
			rows: { documents: [], parties: [], tasks: [] },
			chosen: { documents: [], parties: [], tasks: [] },
			error: '',
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/case-split-surface/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/case-split-surface/specs/case-management/spec.md */
		kinds() {
			const labels = {
				documents: t('dossiq', 'Documents'),
				parties: t('dossiq', 'Parties'),
				tasks: t('dossiq', 'Objects'),
			}

			return this.allowed.map((id) => ({ id, label: labels[id] || id }))
		},

		/** @spec openspec/changes/case-split-surface/specs/case-management/spec.md */
		canConfirm() {
			return (
				this.busy === false
				&& this.kinds.some((k) => this.chosen[k.id].length > 0)
			)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * What is on the case, per kind.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
		 */
		async load() {
			// 🔴 THE SERVER SAYS WHAT MAY BE DIVIDED, NOT THIS FILE. The case
			// type declares it and `CaseSplitPolicy` reads the declaration; a
			// picker that listed every schema itself would offer checkboxes
			// for parts the server is about to refuse, and a handler who ticks
			// one has wasted the split rather than learnt the rule.
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/split`,
					),
				)
				this.allowed = data?.allowed || []
				for (const part of this.allowed) {
					this.rows[part] = (data?.parts?.[part] || []).map((row) => ({
						id: String(row?.id ?? ''),
						label: String(row?.label ?? row?.id ?? ''),
					}))
				}
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('dossiq', 'What this case holds could not be read.')
			}
		},

		/**
		 * @param {string} kind Which kind.
		 * @param {string} id The row.
		 * @param {boolean} on Whether it is ticked.
		 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
		 */
		toggle(kind, id, on) {
			const without = this.chosen[kind].filter((r) => r !== id)
			this.chosen[kind] = on ? [...without, id] : without
		},

		/**
		 * Ask the server to split, and keep the dialog open on a refusal.
		 *
		 * The refusal is shown verbatim: a case type that forbids dividing
		 * documents and one that forbids dividing parties are two different
		 * answers with two different ways out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
		 */
		async confirm() {
			if (!this.canConfirm || !this.targetCaseId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/split`,
					),
					{
						title: this.title.trim(),
						documents: this.chosen.documents,
						parties: this.chosen.parties,
						tasks: this.chosen.tasks,
					},
				)
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('dossiq', 'The case was not split.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-split-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-split-dialog__kind h3 {
	margin: 8px 0 4px;
	font-size: 1em;
}

.case-split-dialog__rows {
	display: flex;
	flex-direction: column;
	gap: 4px;
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 200px;
	overflow-y: auto;
}

.case-split-dialog__empty {
	color: var(--color-text-maxcontrast);
}

.case-split-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>

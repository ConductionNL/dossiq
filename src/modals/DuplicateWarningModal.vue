<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Shows the cases that already look like the one being filed, before it exists.

  The matches come from OpenRegister and are rendered, never recomputed: a
  panel that scored anything itself would drift from the nightly sweep and from
  the write path, and a handler would be warned about one thing and refused for
  another.

  Under a case type that blocks, a handler is offered the matched cases and no
  way through. A coordinator is offered a way through and asked why, because the
  next handler reading two near-identical cases needs to know which of them was
  meant.

  Modal isolation per ADR-004: lives in src/modals/.

  @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
-->
<template>
	<NcModal
		size="normal"
		:name="t('dossiq', 'This case may already exist')"
		@close="$emit('close')">
		<div class="duplicate-warning-modal">
			<NcNoteCard
				:type="blocked ? 'error' : 'warning'"
				data-testid="duplicate-warning-note">
				{{ headline }}
			</NcNoteCard>

			<ul class="duplicate-warning-modal__matches">
				<li
					v-for="row in rows"
					:key="row.uuid"
					class="duplicate-warning-modal__match"
					data-testid="duplicate-warning-match">
					<a
						v-if="row.readable"
						:href="caseUrl(row.uuid)"
						class="duplicate-warning-modal__link"
						data-testid="duplicate-warning-link">
						{{ row.title }}
					</a>
					<span v-else class="duplicate-warning-modal__link">
						{{ row.title }}
					</span>
					<span class="duplicate-warning-modal__meta">
						{{ row.identifier }}
					</span>
					<span class="duplicate-warning-modal__meta">
						{{ matchedOn(row) }} ({{ percentage(row) }}%)
					</span>
				</li>
			</ul>

			<NcTextField
				v-if="needsReason"
				:label="t('dossiq', 'Why file this case anyway')"
				:modelValue="reason"
				data-testid="duplicate-warning-reason"
				@update:modelValue="reason = $event" />

			<p
				v-if="blocked && !mayContinue"
				class="duplicate-warning-modal__hint"
				data-testid="duplicate-warning-blocked">
				{{
					t(
						'dossiq',
						'This case type does not let the same case be filed twice. Open the case it matches, or ask a coordinator.',
					)
				}}
			</p>

			<div class="duplicate-warning-modal__actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="mayContinue"
					variant="primary"
					:disabled="!canFile"
					data-testid="duplicate-warning-continue"
					@click="fileAnyway">
					{{ t('dossiq', 'File it anyway') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcNoteCard, NcTextField } from '@nextcloud/vue'
import {
	affordancesFor,
	matchedFields,
	matchPercentage,
	matchRow,
	mayFile,
} from '../utils/duplicateWarning.js'

export default {
	name: 'DuplicateWarningModal',
	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The scored matches, as OpenRegister answered them. */
		matches: {
			type: Array,
			default: () => [],
		},

		/** The matched cases that could be read, for their titles. */
		cases: {
			type: Array,
			default: () => [],
		},

		/** What the case type declared: warn or block. */
		policy: {
			type: String,
			default: 'warn',
		},

		/** Whether this account is in a declared override group. */
		mayOverride: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'file'],

	data() {
		return {
			reason: '',
		}
	},

	computed: {
		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		affordances() {
			return affordancesFor({
				matches: this.matches,
				checked: true,
				policy: this.policy,
				mayOverride: this.mayOverride,
			})
		},

		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		blocked() {
			return this.affordances.blocked
		},

		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		mayContinue() {
			return this.affordances.mayContinue
		},

		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		needsReason() {
			return this.affordances.needsReason
		},

		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		canFile() {
			return mayFile({
				mayContinue: this.mayContinue,
				needsReason: this.needsReason,
				reason: this.reason,
			})
		},

		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		rows() {
			return this.matches.map((match) => {
				const row = matchRow(match, this.cases)

				return {
					...row,
					// The label is decided here and not in the helper, because a
					// string handed to `t()` through a variable is invisible to
					// the extractor: it never reaches `l10n/en.json` and never
					// gets translated, with nothing failing anywhere.
					title: row.readable
						? row.title
						: t('dossiq', 'A case you may not open'),
					match,
				}
			})
		},

		/** @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md */
		headline() {
			return n(
				'dossiq',
				'%n open case looks like this one.',
				'%n open cases look like this one.',
				this.matches.length,
			)
		},
	},

	methods: {
		t,
		n,

		/**
		 * The case page of one match.
		 *
		 * @param {string} uuid The case uuid.
		 * @return {string} The link.
		 *
		 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
		 */
		caseUrl(uuid) {
			return generateUrl(`/apps/dossiq/cases/${encodeURIComponent(uuid)}`)
		},

		/**
		 * What made this case match.
		 *
		 * @param {object} row One rendered row.
		 * @return {string} The fields, as words.
		 *
		 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
		 */
		matchedOn(row) {
			const labels = {
				requester: t('dossiq', 'Requester'),
				title: t('dossiq', 'Subject'),
				permitApplicationRef: t('dossiq', 'Permit reference'),
				caseType: t('dossiq', 'Case type'),
			}
			const fields = matchedFields(row.match)

			if (fields.length === 0) {
				return t('dossiq', 'Matched on the score, not on a single field')
			}

			return fields.map((field) => labels[field] || field).join(', ')
		},

		/**
		 * How strongly this case matched.
		 *
		 * @param {object} row One rendered row.
		 * @return {number} 0 to 100.
		 *
		 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
		 */
		percentage(row) {
			return matchPercentage(row.match)
		},

		/**
		 * File the case over the warning, carrying what it was filed over.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
		 */
		fileAnyway() {
			this.$emit('file', {
				reason: this.reason.trim(),
				over: this.matches.map((match) => String(match?.uuid || '')).filter(Boolean),
			})
		},
	},
}
</script>

<style scoped>
.duplicate-warning-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.duplicate-warning-modal__matches {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.duplicate-warning-modal__match {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.duplicate-warning-modal__link {
	font-weight: bold;
	color: var(--color-main-text);
}

.duplicate-warning-modal__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.duplicate-warning-modal__hint {
	color: var(--color-text-maxcontrast);
}

.duplicate-warning-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

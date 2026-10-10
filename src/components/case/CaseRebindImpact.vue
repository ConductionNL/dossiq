<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What a case type change does to the answers on this case, in three groups.

  - Removed: answers the target type has no field for, or whose value does
    not fit the field of the same name. Each shows its value, and can be moved
    onto a free field whose type takes it.
  - Carried over: answers that land on a target field, source -> target, with
    the value they land with.
  - Needed now: fields the target type requires that are still empty, each
    asked with a widget for its kind.

  Every row comes from the server's `impact`, the same computation the rebind
  applies. This component decides nothing: it shows the impact and reports the
  coordinator's choices (a move, an answer, the confirmed loss) upwards.

  @spec openspec/specs/zaaktype-versioning/spec.md
-->
<template>
	<div class="rebind-impact" data-testid="case-rebind-impact">
		<h3 class="rebind-impact__title">
			{{ t('dossiq', 'What happens to the answers on this case') }}
		</h3>

		<section class="rebind-impact__group" data-testid="case-rebind-dropped">
			<h4>{{ t('dossiq', 'Removed from this case') }}</h4>
			<p v-if="impact.dropped.length === 0" class="rebind-impact__empty">
				{{ t('dossiq', 'Nothing is removed.') }}
			</p>
			<div
				v-for="row in impact.dropped"
				:key="row.name"
				class="rebind-impact__row"
				:data-testid="`case-rebind-dropped-${row.name}`">
				<p>
					<strong>{{ row.name }}</strong
					>: {{ row.value }}
				</p>
				<p class="rebind-impact__note">
					{{ droppedReason(row) }}
				</p>
				<NcSelect
					v-if="row.candidates.length > 0"
					:modelValue="null"
					:inputLabel="t('dossiq', 'Move to field')"
					:options="row.candidates"
					:data-testid="`case-rebind-move-${row.name}`"
					@update:modelValue="(target) => move(row.name, target)" />
			</div>
			<NcCheckboxRadioSwitch
				v-if="impact.dropped.length > 0"
				:modelValue="dropConfirmed"
				data-testid="case-rebind-confirm-drop"
				@update:modelValue="(v) => $emit('update:dropConfirmed', v)">
				{{
					t(
						'dossiq',
						'I understand these values leave the case. The case history keeps them.',
					)
				}}
			</NcCheckboxRadioSwitch>
		</section>

		<section class="rebind-impact__group" data-testid="case-rebind-ported">
			<h4>{{ t('dossiq', 'Carried over') }}</h4>
			<p v-if="impact.ported.length === 0" class="rebind-impact__empty">
				{{ t('dossiq', 'Nothing carries over.') }}
			</p>
			<div
				v-for="row in impact.ported"
				:key="`${row.source}-${row.target}`"
				class="rebind-impact__row"
				:data-testid="`case-rebind-ported-${row.source}`">
				<p>
					<strong>{{ row.source }} → {{ row.target }}</strong
					>:
					{{ row.newValue }}
				</p>
				<p v-if="row.mapping === 'converted'" class="rebind-impact__note">
					{{
						t('dossiq', '{from} becomes {to}', {
							from: kindLabel(row.sourceKind),
							to: kindLabel(row.targetKind),
						})
					}}
				</p>
				<p v-if="row.mapping === 'remapped'" class="rebind-impact__note">
					{{ t('dossiq', 'Moved by you') }}
					<NcButton
						variant="tertiary"
						:data-testid="`case-rebind-unmove-${row.source}`"
						@click="unmove(row.source)">
						{{ t('dossiq', 'Undo move') }}
					</NcButton>
				</p>
			</div>
		</section>

		<section class="rebind-impact__group" data-testid="case-rebind-required">
			<h4>{{ t('dossiq', 'Needed now') }}</h4>
			<p v-if="!statusChosen" class="rebind-impact__empty">
				{{
					t(
						'dossiq',
						'Pick a status first to see what the new type requires.',
					)
				}}
			</p>
			<p v-else-if="impact.required.length === 0" class="rebind-impact__empty">
				{{ t('dossiq', 'Nothing extra is needed.') }}
			</p>
			<RebindPropertyField
				v-for="row in impact.required"
				:key="row.name"
				:field="row"
				:modelValue="answers[row.name] ?? ''"
				@update:modelValue="(v) => answer(row.name, v)" />
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import RebindPropertyField from './RebindPropertyField.vue'

export default {
	name: 'CaseRebindImpact',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		RebindPropertyField,
	},

	props: {
		/** The server's impact: `dropped`, `ported`, `required`. */
		impact: {
			type: Object,
			required: true,
		},

		/** The target type's title, for the reasons a value is removed. */
		targetTitle: {
			type: String,
			default: '',
		},

		/** Whether a landing status is chosen. */
		statusChosen: {
			type: Boolean,
			default: false,
		},

		/** Removed answer name => target field name. */
		remap: {
			type: Object,
			default: () => ({}),
		},

		/** Required field name => answer. */
		answers: {
			type: Object,
			default: () => ({}),
		},

		/** Whether the coordinator accepted the removed values. */
		dropConfirmed: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:remap', 'update:answers', 'update:dropConfirmed'],

	methods: {
		t,

		/**
		 * Why an answer is removed.
		 *
		 * @param {object} row The dropped row.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		droppedReason(row) {
			if (row.reason === 'type') {
				return t(
					'dossiq',
					'The value does not fit the field of the same name on {type}.',
					{ type: this.targetTitle },
				)
			}
			return t('dossiq', '{type} has no field with this name.', {
				type: this.targetTitle,
			})
		},

		/**
		 * The words for a field kind.
		 *
		 * @param {string} kind The kind the server reported.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		kindLabel(kind) {
			const labels = {
				text: t('dossiq', 'text'),
				number: t('dossiq', 'number'),
				integer: t('dossiq', 'whole number'),
				boolean: t('dossiq', 'yes or no'),
				date: t('dossiq', 'date'),
				'date-time': t('dossiq', 'date and time'),
				email: t('dossiq', 'email address'),
				url: t('dossiq', 'web address'),
				choice: t('dossiq', 'choice from a list'),
			}
			return labels[kind] || t('dossiq', 'structured value')
		},

		/**
		 * Move a removed answer onto a field.
		 *
		 * @param {string} source The answer.
		 * @param {string} target The field.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		move(source, target) {
			if (!target) {
				return
			}
			this.$emit('update:remap', { ...this.remap, [source]: target })
		},

		/**
		 * Take back a move.
		 *
		 * @param {string} source The answer.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		unmove(source) {
			const next = { ...this.remap }
			delete next[source]
			this.$emit('update:remap', next)
		},

		/**
		 * Record an answer to a required field.
		 *
		 * @param {string} name The field.
		 * @param {string} value The answer.
		 *
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		answer(name, value) {
			this.$emit('update:answers', { ...this.answers, [name]: value })
		},
	},
}
</script>

<style scoped>
.rebind-impact {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.rebind-impact__group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.rebind-impact__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border-inline-start: 4px solid var(--color-border);
	padding-inline-start: 8px;
}

.rebind-impact__note,
.rebind-impact__empty {
	color: var(--color-text-maxcontrast);
}
</style>

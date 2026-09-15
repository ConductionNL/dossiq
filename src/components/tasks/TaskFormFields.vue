<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The form a task asks for, rendered where the handler already is.

  A task type declares its form on the case type and dossiq writes that
  declaration onto the task; OpenRegister resolves it against the live schema
  on every read and answers the field list, each field saying whether it can be
  rendered and why not. This component renders that answer and nothing else: it
  derives no field list of its own, because a second derivation would
  eventually ask for a field the completion refuses.

  🔴 A BROKEN FORM IS SHOWN AS BROKEN, NOT AS AN EMPTY ONE. A field the schema
  dropped or locked comes back with `renderable: false` and a reason. Leaving
  it out would produce a form that completes successfully and stores a verslag
  with a hole in it, which is the failure the engine's own resolver is written
  to avoid.

  @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
-->
<template>
	<div class="task-form-fields" :data-testid="testId">
		<p
			v-if="broken"
			class="task-form-fields__broken"
			:data-testid="`${testId}-broken`">
			{{ brokenMessage }}
		</p>
		<p
			v-else-if="external"
			class="task-form-fields__external"
			:data-testid="`${testId}-external`">
			{{ t('dossiq', 'This task is finished in its own form.') }}
		</p>
		<div
			v-for="field in fields"
			v-else
			:key="field.field"
			class="task-form-fields__field">
			<label :for="`${testId}-${field.field}`">
				{{ field.field }}<span v-if="field.required" aria-hidden="true">*</span>
			</label>
			<input
				v-if="field.renderable !== false"
				:id="`${testId}-${field.field}`"
				:value="answers[field.field] ?? ''"
				:required="field.required === true"
				:data-testid="`${testId}-${field.field}`"
				type="text"
				@input="write(field.field, $event.target.value)">
			<span v-else class="task-form-fields__unrenderable">
				{{ field.reason || t('dossiq', 'This field cannot be shown here.') }}
			</span>
		</div>
	</div>
</template>

<script>
export default {
	name: 'TaskFormFields',

	props: {
		/** The form as the engine describes it: `{kind, state, error, fields}`. */
		form: {
			type: Object,
			required: true,
		},

		/**
		 * The answers so far, keyed by field.
		 *
		 * Mutated in place rather than emitted: the pane holds one answer set
		 * per open task and hands the same object to the completion, so an
		 * event round trip would be a second copy that can be one keystroke
		 * behind the one that is sent.
		 */
		answers: {
			type: Object,
			required: true,
		},

		/** The test id prefix, so two forms on one page stay addressable. */
		testId: {
			type: String,
			default: 'task-form',
		},
	},

	computed: {
		/** @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md */
		fields() {
			return Array.isArray(this.form?.fields) ? this.form.fields : []
		},

		/** @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md */
		external() {
			return this.form?.kind === 'external'
		},

		/**
		 * Whether the form cannot be rendered at all.
		 *
		 * `unresolvable` is a declaration whose flow version is gone;
		 * `broken` is a field list the live schema no longer supports. Both
		 * are shown with the engine's own sentence rather than as an empty
		 * form: an empty form invites a completion that stores nothing.
		 *
		 * @return {boolean} True when it cannot be rendered.
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		broken() {
			const state = String(this.form?.state ?? '')
			return state === 'unresolvable' || (state === 'broken' && this.fields.length === 0)
		},

		/** @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md */
		brokenMessage() {
			return (
				String(this.form?.error ?? '').trim()
				|| t('dossiq', 'This task asks for a form that cannot be shown.')
			)
		},
	},

	methods: {
		/**
		 * Record one answer.
		 *
		 * @param {string} field The field.
		 * @param {string} value What was typed.
		 * @return {void}
		 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
		 */
		write(field, value) {
			this.answers[field] = value
		},
	},
}
</script>

<style scoped lang="scss">
.task-form-fields {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: var(--default-grid-baseline) 0;

	&__field {
		display: flex;
		flex-direction: column;

		label {
			color: var(--color-text-maxcontrast);
		}
	}

	&__broken,
	&__unrenderable {
		margin: 0;
		color: var(--color-warning-text, var(--color-text-maxcontrast));
	}

	&__external {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}
}
</style>

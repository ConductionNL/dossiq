<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The offer: the templates of one kind, where that kind is created.
  -
  - 🔑 WHAT MOUNTS THIS, AND WHAT DOES NOT, NAMED RATHER THAN ASSUMED. The
  - spec asks for a template to be offered wherever its kind is created, and
  - dossiq owns one of those four surfaces:
  -
  -   - result: mounted, in the close form, wherever a case is given its result.
  -   - task: NOT mounted. A task is an engine row written through
  -     lib/Service/Task/EngineTaskGateway.php, and the dialog that creates one
  -     belongs to OpenRegister's flow-task surface, not to this repo.
  -   - note: NOT mounted. Notes are OpenRegister-native and rendered by
  -     CnNotesTab.vue; dossiq's NotesController only exposes mention().
  -   - approval: NOT mounted. Approval runs through the transition engine's
  -     own dialog.
  -
  - Those three are one prop away: mount this component and pass the kind. They
  - are written down here rather than left to be discovered, because a picker
  - that exists and is mounted nowhere reads exactly like one that is offered
  - everywhere.
  -
  - @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
  -
  - @visual exclude A two-control strip inside another dialog, whose list is empty until content templates exist in a live register. Its option mapping and its empty behaviour are unit-tested in tests/vitest/templatePicker.spec.js.
-->
<template>
	<div v-if="options.length" class="template-picker">
		<NcSelect
			v-model="chosen"
			:options="options"
			:inputLabel="label"
			:placeholder="t('dossiq', 'Start from a template')"
			:loading="loading"
			label="name"
			trackBy="id"
			data-testid="template-picker"
			@update:modelValue="choose" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { listContentTemplates } from '../services/templateApi.js'
import { templateOptions } from '../utils/templatePicker.js'

export default {
	name: 'TemplatePicker',
	components: { NcSelect },

	props: {
		/** The kind of thing being created: task, note, approval, result or document. */
		kind: {
			type: String,
			required: true,
		},

		/** The case type the handler is working on, which scopes the offer. */
		caseType: {
			type: String,
			default: '',
		},

		/** The case being worked on, so the server can check the reader may see it. */
		caseId: {
			type: String,
			default: '',
		},

		/** What the picker is called, so each surface can name its own kind. */
		label: {
			type: String,
			default: '',
		},
	},

	emits: ['apply'],

	data() {
		return {
			chosen: null,
			options: [],
			loading: false,
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,

		/**
		 * Read the templates of this kind that this case type may use.
		 *
		 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
		 */
		async reload() {
			this.loading = true
			try {
				const data = await listContentTemplates(this.kind, {
					caseType: this.caseType,
					case: this.caseId,
				})
				this.options = templateOptions(data)
			} catch {
				// An empty offer, never a stale one. The picker hides itself
				// when there is nothing to offer, which is the right answer
				// for "the library could not be read" as well: a dropdown of
				// templates from the last case type would be worse than none.
				this.options = []
				showError(t('dossiq', 'Could not read the templates'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Hand the chosen template's presets to whoever mounted this.
		 *
		 * @param {object} option The chosen option.
		 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
		 */
		choose(option) {
			if (!option) {
				return
			}

			this.$emit('apply', { id: option.id, presets: option.presets, body: option.body })
		},
	},
}
</script>

<style scoped>
.template-picker {
	margin-block-end: 12px;
}
</style>

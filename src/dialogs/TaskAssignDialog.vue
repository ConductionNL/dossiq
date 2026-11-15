<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Assign a task to another user or to a team, from the task page
	(REQ-TASK-003; capability-rows-say-the-whole-capability task 3.3).

	The page offered Pick up and Hand back only, both about the reader. This
	asks the task ENGINE, which decides who may: a colleague through its
	`assign` verb, a team through its `offer` verb with the group as the
	candidate pool. The engine offers a pool only to a task nobody holds, and
	its refusal says so; that sentence is shown as-is, never replaced by a
	guess, because "hand it back first" and "you may not" ask for different
	next steps.

	The task is read from the ROUTE when the `taskId` prop arrives as the
	unresolved `@objectId` token, as every open-modal dialog on a detail page
	must (open-modal forwards props verbatim).

	@spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Assign task')"
		data-testid="task-assign-dialog"
		@closing="$emit('close')">
		<div class="task-assign">
			<fieldset class="task-assign__kind">
				<legend>{{ t('dossiq', 'Assign to') }}</legend>
				<NcCheckboxRadioSwitch
					type="radio"
					name="task-assign-kind"
					value="user"
					:modelValue="kind"
					data-testid="task-assign-kind-user"
					@update:modelValue="setKind('user')">
					{{ t('dossiq', 'A colleague') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					type="radio"
					name="task-assign-kind"
					value="group"
					:modelValue="kind"
					data-testid="task-assign-kind-group"
					@update:modelValue="setKind('group')">
					{{ t('dossiq', 'A team') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcTextField
				v-model="query"
				data-testid="task-assign-search"
				:label="kind === 'group' ? t('dossiq', 'Team') : t('dossiq', 'Colleague')"
				:placeholder="t('dossiq', 'Name or id')"
				@update:modelValue="search" />

			<ul v-if="results.length > 0" class="task-assign__results">
				<li v-for="result in results" :key="result.id">
					<NcCheckboxRadioSwitch
						type="radio"
						name="task-assign-target"
						:value="result.id"
						:modelValue="target"
						data-testid="task-assign-result"
						@update:modelValue="target = result.id">
						{{ result.label }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<p v-else-if="searched" class="task-assign__hint">
				{{ t('dossiq', 'Nobody found with those words.') }}
			</p>

			<p v-if="kind === 'group'" class="task-assign__hint">
				{{ t('dossiq', 'The task waits in the team\'s pool until someone in it picks it up.') }}
			</p>

			<p
				v-if="error"
				class="task-assign__error"
				data-testid="task-assign-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="task-assign-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="task-assign-confirm"
				:disabled="target === '' || busy || resolvedTaskId === ''"
				@click="confirm">
				{{ t('dossiq', 'Assign') }}
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
import { searchGroups, searchUsers } from '../services/userSearch.js'
import { assignRequest } from '../utils/taskAssign.js'

export default {
	name: 'TaskAssignDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

	props: {
		/** The task uuid, or the unresolved `@objectId` token. */
		taskId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'assigned'],

	data() {
		return {
			kind: 'user',
			query: '',
			results: [],
			searched: false,
			target: '',
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * The task this dialog assigns: the prop when it holds an id, else the route's.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
		 */
		resolvedTaskId() {
			const given = String(this.taskId || '')
			if (given !== '' && !given.startsWith('@')) {
				return given
			}
			return String(this.$route?.params?.id || '')
		},
	},

	methods: {
		t,

		/**
		 * Switch between a colleague and a team, forgetting the other's pick.
		 *
		 * @param {string} kind `user` or `group`.
		 * @return {void}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
		 */
		setKind(kind) {
			if (this.kind === kind) {
				return
			}
			this.kind = kind
			this.target = ''
			this.results = []
			this.searched = false
			this.error = ''
			if (this.query.trim().length >= 2) {
				this.search()
			}
		},

		/**
		 * Find the colleagues or teams the reader may mean.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
		 */
		async search() {
			const term = this.query.trim()
			if (term.length < 2) {
				this.results = []
				this.searched = false
				return
			}
			const kind = this.kind
			const found = kind === 'group' ? await searchGroups(term) : await searchUsers(term)
			if (kind !== this.kind) {
				return
			}
			this.results = found
			this.searched = true
			if (!found.some((entry) => entry.id === this.target)) {
				this.target = ''
			}
		},

		/**
		 * Ask the engine to assign the task, and show its refusal as it said it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
		 */
		async confirm() {
			if (this.target === '' || this.resolvedTaskId === '') {
				return
			}
			this.busy = true
			this.error = ''
			const { url, body } = assignRequest(this.resolvedTaskId, this.kind, this.target)
			try {
				await axios.post(generateUrl(url), body)
				this.$emit('assigned', { kind: this.kind, id: this.target })
				// The task page reads the task again, so its assignee and verbs follow the engine.
				emit('cn:page:refresh')
				this.$emit('close')
			} catch (error) {
				const said = error?.response?.data?.error
				this.error = typeof said === 'string' && said !== ''
					? said
					: t('dossiq', 'The task could not be assigned.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.task-assign {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.task-assign__kind {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 4);
	border: 0;
	padding: 0;
	margin: 0;
}

.task-assign__results {
	list-style: none;
	margin: 0;
	padding: 0;
}

.task-assign__hint {
	color: var(--color-text-maxcontrast);
}

.task-assign__error {
	color: var(--color-error-text);
}
</style>

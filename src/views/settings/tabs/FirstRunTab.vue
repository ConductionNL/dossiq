<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  FirstRunTab — the minimum this instance needs before it can take a case.

  Reported, never gated. `register-check` is the only step the setup wizard
  requires, because without a register nothing works at all. Everything here is
  named, read live, and left to the administrator: an instance somebody
  deliberately leaves half configured is a legitimate instance, and an instance
  nobody can see the state of is not.

  Each item links to the screen that satisfies it, and re-reads itself when the
  administrator comes back, because the status endpoint asks the tree rather
  than reading a stored flag.

  @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
-->
<template>
	<div class="first-run-tab">
		<p class="first-run-tab__description">
			{{
				t(
					'dossiq',
					'What this instance still needs before it can take a case. Nothing here blocks the app: you can leave an item open and carry on.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<ul class="first-run-tab__list" data-testid="first-run-readiness">
				<li v-for="item in readiness" :key="item.id" class="first-run-tab__item">
					<span class="first-run-tab__state" :class="item.done ? 'is-done' : 'is-open'">
						{{ item.done ? t('dossiq', 'Done') : t('dossiq', 'Still open') }}
					</span>
					<span class="first-run-tab__body">
						<a v-if="item.screen" :href="item.screen" class="first-run-tab__title">{{ item.title }}</a>
						<span v-else class="first-run-tab__title">{{ item.title }}</span>
						<span class="first-run-tab__hint">{{ item.body }}</span>
						<span v-if="item.failure" class="first-run-tab__failure">
							{{ t('dossiq', 'This could not be read: {reason}', { reason: item.failure }) }}
						</span>
					</span>
				</li>
			</ul>

			<NcNoteCard v-if="brokenSteps.length" type="warning" data-testid="first-run-broken-steps">
				{{
					t(
						'dossiq',
						'These tour steps point at a screen that is gone, so they teach nobody: {steps}',
						{ steps: brokenSteps.map((step) => `${step.step} (${step.surface})`).join(', ') },
					)
				}}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'FirstRunTab',

	components: { NcLoadingIcon, NcNoteCard },

	data() {
		return {
			loading: true,
			error: '',
			readiness: [],
			brokenSteps: [],
		}
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		/**
		 * Read the status, which asks the tree rather than a stored flag.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
		 */
		async reload() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/dossiq/api/setup/status'))
				this.readiness = Array.isArray(data?.readiness) ? data.readiness : []
				this.brokenSteps = (Array.isArray(data?.tourSteps) ? data.tourSteps : [])
					.filter((step) => step.state === 'missing')
			} catch {
				this.error = t('dossiq', 'The first run status could not be read.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.first-run-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.first-run-tab__item {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding-block: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
}

.first-run-tab__state {
	flex: 0 0 auto;
	min-width: 6em;
	font-weight: bold;
}

.first-run-tab__state.is-done {
	color: var(--color-success-text, var(--color-success));
}

.first-run-tab__state.is-open {
	color: var(--color-text-maxcontrast);
}

.first-run-tab__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.first-run-tab__hint,
.first-run-tab__failure {
	color: var(--color-text-maxcontrast);
}

.first-run-tab__failure {
	color: var(--color-error-text, var(--color-error));
}
</style>

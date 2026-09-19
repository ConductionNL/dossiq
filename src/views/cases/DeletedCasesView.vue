<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The deleted lens on the case list.

  A case that vanishes from the list and reappears nowhere is
  indistinguishable from a case that was destroyed, so a handler who deleted
  the wrong bezwaar cannot tell whether to panic. This page lists the cases
  that are still recoverable and says, as a date rather than as a duration
  nobody can compute, when each window ends.

  OpenRegister owns the recycle state, the window and the purge. This page
  reads them. Restoring is one act; destroying is a second one, and it needs
  the role the case type declares.

  @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
-->
<template>
	<div class="deleted-cases">
		<div class="deleted-cases__header">
			<h2>{{ t('dossiq', 'Deleted cases') }}</h2>
			<p class="deleted-cases__lead">
				{{
					t(
						'dossiq',
						'A deleted case can be recovered until the date below. After that it can be destroyed, which cannot be undone.',
					)
				}}
			</p>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="rows.length === 0"
			class="deleted-cases__empty"
			:name="t('dossiq', 'Nothing was deleted')"
			:description="
				t(
					'dossiq',
					'Deleted cases land here, with the date each one can still be recovered until.',
				)
			" />

		<table v-else class="deleted-cases__table" data-testid="deleted-cases-table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', 'Case number') }}</th>
					<th scope="col">{{ t('dossiq', 'Case') }}</th>
					<th scope="col">{{ t('dossiq', 'Deleted by') }}</th>
					<th scope="col">{{ t('dossiq', 'Recover until') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.id"
					:data-testid="`deleted-case-${row.id}`">
					<td>{{ row.identifier }}</td>
					<td>{{ row.title }}</td>
					<td>{{ row.deletedBy }}</td>
					<td data-testid="window-ends-on">
						{{ row.windowEndsOn }}
						<span v-if="row.lapsed" class="deleted-cases__lapsed">
							{{ t('dossiq', 'the window has passed') }}
						</span>
						<span v-else class="deleted-cases__remaining">
							{{
								t('dossiq', '{days} days left', {
									days: row.daysRemaining || 0,
								})
							}}
						</span>
					</td>
					<td class="deleted-cases__row-actions">
						<NcButton
							variant="secondary"
							:disabled="busy === row.id"
							:data-testid="`restore-${row.id}`"
							@click="restore(row)">
							{{ t('dossiq', 'Restore') }}
						</NcButton>
						<NcButton
							variant="error"
							:disabled="busy === row.id"
							:data-testid="`destroy-${row.id}`"
							@click="destroy(row)">
							{{ t('dossiq', 'Destroy') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import {
	destroyCase,
	listDeletedCases,
	restoreCase,
} from '../../services/caseRecycleApi.js'

/**
 * The sentence each server refusal turns into.
 *
 * The server sends a short static code and never a message, so the page owns
 * the wording and a translator can move it. An unknown code falls back to one
 * honest sentence rather than printing the code at a reader.
 *
 * @param {string} code The refusal code.
 * @return {string} The sentence.
 */
function refusalSentence(code) {
	const sentences = {
		case_not_deleted: t('dossiq', 'This case is not in the recovery window.'),
		destroy_role_undeclared: t(
			'dossiq',
			'This case type names no role that may destroy a case. Declare one on the case type first.',
		),
		destroy_role_missing: t(
			'dossiq',
			'You do not hold the role this case type requires to destroy a case.',
		),
		retention_clocks_disagree: t(
			'dossiq',
			'The lawful purpose of this case has ended while its archive period has not. Somebody has to decide which rule wins.',
		),
		recovery_window_open: t(
			'dossiq',
			'This case can still be recovered, so it is not destroyed yet.',
		),
	}

	return sentences[code] || t('dossiq', 'The case does not allow this.')
}

export default {
	name: 'DeletedCasesView',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			rows: [],
			loading: true,
			busy: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the deleted cases.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
		 */
		async load() {
			this.loading = true
			try {
				this.rows = await listDeletedCases()
			} catch {
				showError(t('dossiq', 'Could not read the deleted cases.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get one case back.
		 *
		 * @param {object} row The row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
		 */
		async restore(row) {
			this.busy = row.id
			try {
				await restoreCase(row.id)
				showSuccess(t('dossiq', 'The case is back.'))
				await this.load()
			} catch (error) {
				showError(refusalSentence(error?.response?.data?.code))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * Destroy one case. A second act, and it cannot be undone.
		 *
		 * @param {object} row The row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
		 */
		async destroy(row) {
			this.busy = row.id
			try {
				await destroyCase(row.id)
				showSuccess(
					t(
						'dossiq',
						'The case is destroyed, and the record of it stays.',
					),
				)
				await this.load()
			} catch (error) {
				showError(refusalSentence(error?.response?.data?.code))
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.deleted-cases {
	padding: 16px;
}

.deleted-cases__lead {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 16px;
}

.deleted-cases__table {
	width: 100%;
	border-collapse: collapse;
}

.deleted-cases__table th,
.deleted-cases__table td {
	text-align: start;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.deleted-cases__lapsed,
.deleted-cases__remaining {
	color: var(--color-text-maxcontrast);
	display: block;
	font-size: 0.9em;
}

.deleted-cases__row-actions {
	display: flex;
	gap: 8px;
}
</style>

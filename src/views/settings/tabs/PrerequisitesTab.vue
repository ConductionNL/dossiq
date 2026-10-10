<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What dossiq needs, and what this instance has.

  Every row is read on the server at page load, from `Prerequisites::check()`.
  Nothing here asks the browser anything: a PHP extension is not visible from
  a browser, and an app the reader cannot see is not the same as an app that
  is not installed.

  A missing prerequisite is REPORTED and never silently worked around
  (ADR-102). Only Open Register blocks; everything else says what it would
  have added, so an administrator can decide whether they want it.

  @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
-->
<template>
	<div class="prerequisites" data-testid="prerequisites-block">
		<NcNoteCard
			v-if="missingRequired.length > 0"
			type="error"
			data-testid="prerequisites-blocking">
			{{ t('dossiq', 'Dossiq cannot run until this is installed:') }}
			{{ missingRequired.map((row) => row.name || row.id).join(', ') }}
		</NcNoteCard>

		<h4>{{ t('dossiq', 'Platform') }}</h4>
		<ul class="prerequisites__list">
			<li data-testid="prerequisite-php">
				<span :class="markClass(php.present)">{{ mark(php.present) }}</span>
				{{ t('dossiq', 'PHP') }} {{ php.required }}
				{{ t('dossiq', 'or higher') }}
				<span class="prerequisites__detail">
					({{ t('dossiq', 'this instance runs') }} {{ php.running }})
				</span>
			</li>
			<li data-testid="prerequisite-nextcloud">
				<span :class="markClass(nextcloud.present)">
					{{ mark(nextcloud.present) }}
				</span>
				{{ t('dossiq', 'Nextcloud') }} {{ nextcloud.min }}
				{{ t('dossiq', 'to') }} {{ nextcloud.max }}
				<span v-if="nextcloud.running" class="prerequisites__detail">
					({{ t('dossiq', 'this instance runs') }} {{ nextcloud.running }})
				</span>
			</li>
		</ul>

		<h4>{{ t('dossiq', 'PHP extensions') }}</h4>
		<ul class="prerequisites__list">
			<li
				v-for="row in extensions"
				:key="row.name"
				:data-testid="`prerequisite-ext-${row.name}`">
				<span :class="markClass(row.present)">{{ mark(row.present) }}</span>
				{{ row.name }}
				<span class="prerequisites__detail">{{ row.why }}</span>
			</li>
		</ul>

		<h4>{{ t('dossiq', 'Apps dossiq needs') }}</h4>
		<ul class="prerequisites__list">
			<li
				v-for="row in required"
				:key="row.id"
				:data-testid="`prerequisite-app-${row.id}`">
				<span :class="markClass(row.present)">{{ mark(row.present) }}</span>
				{{ row.name || row.id }}
				<span class="prerequisites__detail">{{ row.unlocks }}</span>
			</li>
		</ul>

		<h4>{{ t('dossiq', 'Apps dossiq works with') }}</h4>
		<ul class="prerequisites__list">
			<li
				v-for="row in optional"
				:key="row.id"
				:data-testid="`prerequisite-app-${row.id}`">
				<span :class="markClass(row.present)">{{ mark(row.present) }}</span>
				{{ row.name || row.id }}
				<span class="prerequisites__detail">{{ row.unlocks }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'PrerequisitesTab',

	components: { NcNoteCard },

	data() {
		// An absent state renders an empty block rather than throwing. The
		// settings page carries a dozen other sections, and one of them
		// failing to read its state should not take the rest of the page.
		const state = loadState('dossiq', 'prerequisites', {})

		return {
			php: state.php ?? { required: '', running: '', present: false },
			nextcloud: state.nextcloud ?? {
				min: '',
				max: '',
				running: '',
				present: false,
			},

			extensions: state.extensions ?? [],
			required: state.apps?.required ?? [],
			optional: state.apps?.optional ?? [],
		}
	},

	computed: {
		/**
		 * @return {Array} The required apps this instance does not have.
		 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
		 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
		 */
		missingRequired() {
			return this.required.filter((row) => row.present !== true)
		},
	},

	methods: {
		t,

		/**
		 * The word beside a row, which is what a screen reader announces.
		 *
		 * Text and not a colour: "present" and "missing" are the whole
		 * message, and a green dot alone fails WCAG 1.4.1.
		 *
		 * @param {boolean} present Whether the item is there.
		 * @return {string} The word.
		 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
		 */
		mark(present) {
			return present === true ? t('dossiq', 'present') : t('dossiq', 'missing')
		},

		/**
		 * The class carrying the colour that repeats the word.
		 *
		 * @param {boolean} present Whether the item is there.
		 * @return {string} The class.
		 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
		 */
		markClass(present) {
			return present === true
				? 'prerequisites__mark prerequisites__mark--present'
				: 'prerequisites__mark prerequisites__mark--missing'
		},
	},
}
</script>

<style scoped>
.prerequisites__list {
	list-style: none;
	margin: 0 0 16px 0;
	padding: 0;
}

.prerequisites__list li {
	padding: 2px 0;
}

/*
 * Fill and ink in matching pairs. Since Nextcloud 32 `--color-success` and
 * `--color-error` are light FILLS; used as ink they were near-invisible on the
 * page background (round-4 cloud check). Each fill has a `-text` partner
 * that meets WCAG AA on it, in the light and the dark theme.
 */
.prerequisites__mark {
	display: inline-block;
	box-sizing: border-box;
	min-width: 72px;
	margin-inline-end: 8px;
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-weight: bold;
	text-align: center;
}

.prerequisites__mark--present {
	background: var(--color-success);
	color: var(--color-success-text);
}

.prerequisites__mark--missing {
	background: var(--color-error);
	color: var(--color-error-text);
}

.prerequisites__detail {
	color: var(--color-text-maxcontrast);
}
</style>

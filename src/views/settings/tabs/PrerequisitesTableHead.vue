<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The header every table of the Prerequisites block shares: what the rows
  are, what each does, and whether it is here.

  @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
-->
<template>
	<thead>
		<tr>
			<th scope="col">
				{{ label }}
			</th>
			<th scope="col">
				{{ t('dossiq', 'What it does') }}
			</th>
			<th scope="col" class="prerequisites__here">
				{{ t('dossiq', 'Here') }}
			</th>
		</tr>
	</thead>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PrerequisitesTableHead',

	props: {
		/**
		 * What the table's rows are, as its first column heading.
		 */
		label: {
			type: String,
			required: true,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.prerequisites__here {
	inline-size: 120px;
}
</style>

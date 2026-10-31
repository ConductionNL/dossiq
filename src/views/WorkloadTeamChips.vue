<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<WorkloadSummaryBar part="chips" />
</template>

<script>
import WorkloadSummaryBar from './WorkloadSummaryBar.vue'

/**
 * The team's name chips with their open-case counts, at the start of My Work's toolbar row (board DqAanMijToegewezen).
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-assigned-to-me-draws-the-teams-name-chips-on-the-toolbar-row
 */
export default {
	name: 'WorkloadTeamChips',

	components: { WorkloadSummaryBar },
}
</script>

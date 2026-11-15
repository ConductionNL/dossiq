# Proposal: flow-test-with-a-case

Board DqFlow draws "Testen met een zaak" on the flow page. The flow page declares cases as the records a flow is tried
with (`config.testSubject`). The library button tries the saved flow through OpenRegister's dry-run, which changes and
sends nothing, and shows each step beside the canvas (nextcloud-vue feat/flow-page-dry-run, openregister#4753).

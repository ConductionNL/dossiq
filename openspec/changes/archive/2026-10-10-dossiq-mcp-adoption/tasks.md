# Tasks — dossiq-mcp-adoption

## 1. Declare the dialect (13 schemas, 22 tools, read-only)

- [x] 1.1 `lib/Settings/dossiq_register.json`: add `configuration["x-openregister-mcp"]` to `case`, `task`, `caseType`, `statusType`, `statusRecord` — verbs, filters and agent-facing descriptions exactly as design.md §D1. Create the `configuration` object where absent; never drop an existing key. `python3 -m json.tool` after each edit.
- [x] 1.2 `lib/Settings/dossiq_register.json`: same for `decision`, `result` (get only), `resultType`, `document`, `caseDocument` (search only).
- [x] 1.3 `lib/Settings/dossiq_register.json`: same for `bezwaar` and `complaint` (**get only** — no `search`, AVG: `klager` is an embedded citizen record).
- [x] 1.4 `lib/Settings/register.d/60-termijnbewaking.json`: same for `termijnInstance`.
- [x] 1.5 Assert every declared `search.filters` entry is a real property of its schema and that no `create`/`update`/`delete` verb and no identifying filter (`initiatorSourceId`, `initiatorDisplayName`, `initiatorType`, `requester`) appears anywhere.

## 2. Provider surgery (both tools derivable ⇒ delete the class)

- [x] 2.1 Delete `lib/Mcp/DossiqToolProvider.php` (and the now-empty `lib/Mcp/` directory). Both its tools are derivable CRUD, so nothing moves to a service: add **no** `#[McpTool]` method and **no** `IMcpScannableServices` class — an empty scannable-services class would be a dead seam.
- [x] 2.2 `lib/AppInfo/Application.php`: remove the `'mcpProvider' => DossiqToolProvider::class` Bootstrap key and the `use OCA\Dossiq\Mcp\DossiqToolProvider;` import.
- [x] 2.3 Delete `tests/Unit/Mcp/DossiqToolProviderTest.php`, `tests/Stubs/Mcp/IMcpToolProvider.php`, its `require` in `tests/bootstrap.php`, and the `OCA\OpenRegister\Mcp\IMcpToolProvider` `referencedClass` suppression in `psalm.xml`.
## 3. Specs, quality, changelog

- [x] 3.1 Sync the delta into `openspec/specs/mcp-integration/spec.md` at archive time; ensure no `@spec` tag anywhere points at a change path (gate-46).
- [ ] 3.2 `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) clean on the touched files; PHPUnit shows zero new failures against a self-measured baseline.
- [x] 3.3 CHANGELOG entry: declarative MCP adoption, 13 curated schemas, `dossiq.listProcesses` / `dossiq.getProcessDetails` removed (BREAKING, MCP surface).

## 4. Verify on a live instance

- [ ] 4.1 Re-run the register import repair step, then read the imported schemas back from OpenRegister and assert `configuration["x-openregister-mcp"]` survived — the `case` schema is also defined in `register.d/dso-omgevingsloket.json`, so prove the union merge did not drop the block (verify from OpenRegister, not from the file). (live pass, decision 139)
- [ ] 4.2 `tools/list` for `dossiq`: exactly 20 tools (amended from 22, see Built 2026-10-10), all read-only; `dossiq.listProcesses` and `dossiq.getProcessDetails` are GONE (a surviving hand-written tool would shadow its derived twin permanently); `dossiq.case.search` and `dossiq.statusRecord.search` are present. (live pass, decision 139)
- [ ] 4.3 As a non-privileged user, invoke `dossiq.case.search` and confirm the result set equals what that user can already read in the UI — the provider's assignee/role ACL is gone and OpenRegister RBAC is now the only gate (REQ-MCP-105). If register RBAC is not configured, fix that before shipping. (live pass, decision 139)
- [ ] 4.4 Confirm `dossiq.case.search` rejects `initiatorSourceId` as an undeclared filter (no BSN lookup surface) and that `dossiq.complaint.search` does not exist. (live pass, decision 139)

## Built 2026-10-10 (lane L6)

- 1.1 to 1.4: the blocks sit on 12 schemas, not 13. `task` is gone (the case task moved onto OpenRegister's engine `Task`, which is not a register object), and `bezwaar` / `termijnInstance` are `objectionProceeding` / `deadlineInstance` since #849. The `deadlineInstance` block is in `lib/Settings/register.d/60-termijnbewaking.json`; the rest in `lib/Settings/dossiq_register.json`. Delta spec amended to 12 schemas and 20 tools.
- 1.5: `tests/Unit/Mcp/DossiqMcpDialectTest.php` reads the register merged by the real `RegisterFragmentMerger`, pins the set, the verbs, the read-only posture and the filters, runs OpenRegister's `McpAnnotationValidator` when it is loadable, and proves its own checker catches a write verb, an unknown filter and an identifying filter. Run locally against OpenRegister origin/development's validator: 12 of 12 blocks accepted.
- 2.1 to 2.3: provider, `lib/Mcp/Tool/DossiqCaseReader.php` and `DossiqCaseAuthorizer.php` (used only by the provider), their tests, the stub, its bootstrap include and the psalm suppression are deleted. The registration lived in `lib/AppInfo/Registrar/AppHostRegistrar.php`, not Application.php. `DeclaredToolAssumesNoTypingTest` now reads the declared dialect; `NoSecondPermissionEvaluatorTest` lost the two allowlist entries whose files are gone (it fails on a stale entry).
- 3.2: run at the PR checkpoint (see the PR body).

## Re-verified 2026-09-09

Unstarted, and the defect it names is live. `lib/Mcp/DossiqToolProvider.php` is still present
and still registered at `AppHostRegistrar.php:134`; `x-openregister-mcp` has zero hits under
`lib/`. While the hand-written provider exists it permanently shadows any derived twin, so the
declarative route cannot be tried side by side with it.

Keep as backlog: ADR-063 directive, worked design, and it validates. Triage it together with
`hermiq-ai-tooling`, whose own gate T0 blocks on this change.

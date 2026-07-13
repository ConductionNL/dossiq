# Tasks — procest-mcp-adoption

## 1. Declare the dialect (13 schemas, 22 tools, read-only)

- [ ] 1.1 `lib/Settings/procest_register.json`: add `configuration["x-openregister-mcp"]` to `case`, `task`, `caseType`, `statusType`, `statusRecord` — verbs, filters and agent-facing descriptions exactly as design.md §D1. Create the `configuration` object where absent; never drop an existing key. `python3 -m json.tool` after each edit.
- [ ] 1.2 `lib/Settings/procest_register.json`: same for `decision`, `result` (get only), `resultType`, `document`, `caseDocument` (search only).
- [ ] 1.3 `lib/Settings/procest_register.json`: same for `bezwaar` and `complaint` (**get only** — no `search`, AVG: `klager` is an embedded citizen record).
- [ ] 1.4 `lib/Settings/register.d/60-termijnbewaking.json`: same for `termijnInstance`.
- [ ] 1.5 Assert every declared `search.filters` entry is a real property of its schema and that no `create`/`update`/`delete` verb and no identifying filter (`initiatorSourceId`, `initiatorDisplayName`, `initiatorType`, `requester`) appears anywhere.

## 2. Provider surgery (both tools derivable ⇒ delete the class)

- [ ] 2.1 Delete `lib/Mcp/ProcestToolProvider.php` (and the now-empty `lib/Mcp/` directory). Both its tools are derivable CRUD, so nothing moves to a service: add **no** `#[McpTool]` method and **no** `IMcpScannableServices` class — an empty scannable-services class would be a dead seam.
- [ ] 2.2 `lib/AppInfo/Application.php`: remove the `'mcpProvider' => ProcestToolProvider::class` Bootstrap key and the `use OCA\Procest\Mcp\ProcestToolProvider;` import.
- [ ] 2.3 Delete `tests/Unit/Mcp/ProcestToolProviderTest.php`, `tests/Stubs/Mcp/IMcpToolProvider.php`, its `require` in `tests/bootstrap.php`, and the `OCA\OpenRegister\Mcp\IMcpToolProvider` `referencedClass` suppression in `psalm.xml`.
## 3. Specs, quality, changelog

- [ ] 3.1 Sync the delta into `openspec/specs/mcp-integration/spec.md` at archive time; ensure no `@spec` tag anywhere points at a change path (gate-46).
- [ ] 3.2 `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) clean on the touched files; PHPUnit shows zero new failures against a self-measured baseline.
- [ ] 3.3 CHANGELOG entry: declarative MCP adoption, 13 curated schemas, `procest.listProcesses` / `procest.getProcessDetails` removed (BREAKING, MCP surface).

## 4. Verify on a live instance

- [ ] 4.1 Re-run the register import repair step, then read the imported schemas back from OpenRegister and assert `configuration["x-openregister-mcp"]` survived — the `case` schema is also defined in `register.d/dso-omgevingsloket.json`, so prove the union merge did not drop the block (verify from OpenRegister, not from the file).
- [ ] 4.2 `tools/list` for `procest`: exactly 22 tools, all read-only; `procest.listProcesses` and `procest.getProcessDetails` are GONE (a surviving hand-written tool would shadow its derived twin permanently); `procest.case.search` and `procest.statusRecord.search` are present.
- [ ] 4.3 As a non-privileged user, invoke `procest.case.search` and confirm the result set equals what that user can already read in the UI — the provider's assignee/role ACL is gone and OpenRegister RBAC is now the only gate (REQ-MCP-105). If register RBAC is not configured, fix that before shipping.
- [ ] 4.4 Confirm `procest.case.search` rejects `initiatorSourceId` as an undeclared filter (no BSN lookup surface) and that `procest.complaint.search` does not exist.

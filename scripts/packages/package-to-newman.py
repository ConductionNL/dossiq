#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Write the Newman read-back collection for one case definition package.

The packages workflow (.github/workflows/packages.yml) installs every shipped
package on a clean instance and runs every collection under
tests/integration/packages/. This script writes one: for each case type in
the package it reads back the case type, its first and final status, its
first role, property definition, document type and result type when it has
them (every role), and its flow, each by the fixed id the package gives it; then the
ledger row of the first case type.

A case type that ships a list layout (`x-index`) or names its default flow
(`workflowDefinition`) has both read back. Every row of a seed file that names
its schema by a dossiq settings key is read back, after the case types.

A package that ships a draft flow gets more: every flow is read back with its
route and its lifecycle (published or draft), and for each case type that has a
draft the flow a new case follows is read from the app and must be a published
one, never the draft.

It reads only the package, so a collection cannot drift from it: rerun it
whenever the package changes.

Usage:
  package-to-newman.py lib/Settings/packages/<id> tests/integration/packages
"""

import json
import os
import sys

OBJECTS = "{{baseUrl}}/index.php/apps/openregister/api/objects/dossiq"
ACTIVE_FLOW = "{{baseUrl}}/index.php/apps/dossiq/api/workflow-definitions/active"
FLOW_FIELDS = ("title", "caseType", "variant", "lifecycleStatus", "isActive", "isDraft")
SEED_FIELDS = ("name", "caseType", "status")
CASE_TYPE_FIELDS = ("title", "identifier", "processingDeadline", "confidentiality", "publicationRequired")
CHILDREN = (
    ("roleTypes", "roleType", "role"),
    ("propertyDefinitions", "propertyDefinition", "property"),
    ("documentTypes", "documentType", "document type"),
    ("resultTypes", "resultType", "result type"),
)


def as_list(value: object) -> list:
    if isinstance(value, list):
        return value
    return [value] if isinstance(value, dict) else []


def read(name: str, url: str, checks: list) -> dict:
    return {
        "name": name,
        "request": {"method": "GET", "header": [{"key": "Accept", "value": "application/json"}], "url": url},
        "event": [{"listen": "test", "script": {"type": "text/javascript", "exec": checks}}],
    }


def row_read(name: str, schema: str, row: dict, fields: tuple) -> dict:
    checks = ["pm.test('row is there (200)', () => pm.response.to.have.status(200));", "const row = pm.response.json();"]
    for field in fields:
        if field in row and isinstance(row[field], (str, int, bool)):
            checks.append(f"pm.test('{field} as shipped', () => pm.expect(row.{field}).to.eql({json.dumps(row[field], ensure_ascii=False)}));")
    return read(name, f"{OBJECTS}/{schema}/{row['id']}", checks)


def case_type_read(label: str, case_type: dict) -> dict:
    item = row_read(f"case type {label}", "caseType", case_type, CASE_TYPE_FIELDS)
    checks = item["event"][0]["script"]["exec"]
    columns = (case_type.get("x-index") or {}).get("columns")
    if columns:
        checks.append(f"pm.test('list layout as shipped', () => pm.expect(row['x-index'].columns).to.eql({json.dumps(columns, ensure_ascii=False)}));")
    if isinstance(case_type.get("workflowDefinition"), str):
        checks.append(f"pm.test('default flow as shipped', () => pm.expect(row.workflowDefinition).to.eql({json.dumps(case_type['workflowDefinition'])}));")
    return item


def schema_slug(settings_key: str) -> str:
    """`inspection_checklist_template_schema` is stored under `inspectionChecklistTemplate`."""
    words = settings_key.removesuffix("_schema").split("_")
    return words[0] + "".join(word.capitalize() for word in words[1:])


def seed_reads(package_dir: str, labels: dict) -> list:
    items = []
    seed_dir = os.path.join(package_dir, "seed")
    for name in sorted(os.listdir(seed_dir)) if os.path.isdir(seed_dir) else []:
        with open(os.path.join(seed_dir, name), encoding="utf-8") as handle:
            seed = json.load(handle)
        if not isinstance(seed.get("schema"), str):
            # A seed in another register or another app has its own address; this script reads dossiq's.
            sys.exit(f"{name}: the seed names its schema by app, register and slug, which this script does not read back")
        stem = os.path.splitext(name)[0]
        for row in seed.get("rows", []):
            owner = labels.get(row.get("caseType"))
            prefix = f"{owner}: " if owner else ""
            items.append(row_read(f"{prefix}{stem} {row.get('name', row['id'])}", schema_slug(seed["schema"]), row, SEED_FIELDS))
    return items


def followed_read(label: str, case_type: dict, mine: list) -> dict:
    drafts = [f["id"] for f in mine if f.get("lifecycleStatus") == "draft"]
    pinned = case_type.get("workflowDefinition")
    published = [pinned] if pinned else [f["id"] for f in mine if f.get("lifecycleStatus") == "published"]
    checks = [
        "pm.test('a case of this type has a flow to follow (200)', () => pm.response.to.have.status(200));",
        "const definition = pm.response.json().definition;",
        "pm.test('the flow a case follows is published', () => pm.expect(definition.lifecycleStatus).to.eql('published'));",
        f"pm.test('the flow a case follows is not a draft of the package', () => pm.expect({json.dumps(drafts)}).to.not.include(definition.id));",
    ]
    if published:
        checks.append(f"pm.test('the flow a case follows is the published one of the package', () => pm.expect({json.dumps(published)}).to.include(definition.id));")
    item = read(f"{label}: the flow a case follows", f"{ACTIVE_FLOW}/{case_type['id']}", checks)
    item["request"]["header"].append({"key": "OCS-APIRequest", "value": "true"})
    return item


def lifecycle_reads(case_types: list, flows: list) -> list:
    items = []
    for case_type in case_types:
        label = case_type.get("identifier") or case_type["id"]
        mine = [f for f in flows if f.get("caseType") == case_type["id"]]
        for flow in mine:
            kind = "draft flow" if flow.get("lifecycleStatus") == "draft" else "flow"
            items.append(row_read(f"{label}: {kind} {flow['title']}", "workflowTemplate", flow, FLOW_FIELDS))
        if any(f.get("lifecycleStatus") == "draft" for f in mine):
            items.append(followed_read(label, case_type, mine))
    return items


def main() -> None:
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    package_dir, out_dir = sys.argv[1], sys.argv[2]
    with open(os.path.join(package_dir, "package.json"), encoding="utf-8") as handle:
        manifest = json.load(handle)
    with open(os.path.join(package_dir, "case-type.json"), encoding="utf-8") as handle:
        definition = json.load(handle)
    flows = []
    flow_dir = os.path.join(package_dir, "flows")
    for name in sorted(os.listdir(flow_dir)) if os.path.isdir(flow_dir) else []:
        with open(os.path.join(flow_dir, name), encoding="utf-8") as handle:
            flows.append(json.load(handle))

    package_id, version = manifest["id"], manifest["version"]
    items = []
    case_types = as_list(definition.get("caseType"))
    has_draft = any(f.get("lifecycleStatus") == "draft" for f in flows)
    for case_type in case_types:
        label = case_type.get("identifier") or case_type["id"]
        items.append(case_type_read(label, case_type))
        statuses = sorted((s for s in definition.get("statusTypes", []) if s.get("caseType") == case_type["id"]),
                          key=lambda s: s.get("order", 0))
        if statuses:
            items.append(row_read(f"{label}: first status", "statusType", statuses[0], ("name", "caseType")))
            finals = [s for s in statuses if s.get("isFinal")]
            if finals:
                items.append(row_read(f"{label}: final status", "statusType", finals[-1], ("name", "caseType")))
        for key, schema, noun in CHILDREN:
            rows = [r for r in definition.get(key, []) if r.get("caseType") == case_type["id"]]
            # Every role, since a role is what a guard names; the first of the rest.
            for row in rows if key == "roleTypes" else rows[:1]:
                items.append(row_read(f"{label}: {noun} {row.get('name', '')}".rstrip(), schema, row, ("name", "caseType")))
        for flow in (f for f in flows if f.get("caseType") == case_type["id"] and has_draft is False):
            items.append(row_read(f"{label}: flow", "workflowTemplate", flow, ("title", "caseType")))

    items.extend(seed_reads(package_dir, {c["id"]: c.get("identifier") or c["id"] for c in case_types}))
    if has_draft is True:
        items.extend(lifecycle_reads(case_types, flows))

    first = case_types[0]["id"]
    items.append(read("ledger records the package", f"{OBJECTS}/shippedOrigin?targetObject={first}&_limit=5", [
        "pm.test('ledger reachable (200)', () => pm.response.to.have.status(200));",
        "const body = pm.response.json();",
        "const rows = Array.isArray(body) ? body : (body.results || []);",
        f"const row = rows.find(r => r.targetObject === '{first}');",
        "pm.test('a ledger row names the package and its version', () => {",
        "    pm.expect(row, 'ledger row for the case type').to.be.an('object');",
        f"    pm.expect(row.set).to.eql('{package_id}');",
        f"    pm.expect(row.setVersion).to.eql('{version}');",
        "});",
    ]))

    collection = {
        "info": {
            "name": f"dossiq package: {package_id}",
            "description": f"Reads every kind of row the {package_id} package writes back through the OpenRegister objects API, "
                           f"after the packages workflow installed it on a clean instance (hydra ADR-118). Ids are the fixed ids in "
                           f"lib/Settings/packages/{package_id}/. Written by scripts/packages/package-to-newman.py.",
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "auth": {"type": "basic", "basic": [
            {"key": "username", "value": "{{adminUser}}", "type": "string"},
            {"key": "password", "value": "{{adminPass}}", "type": "string"},
        ]},
        "variable": [
            {"key": "baseUrl", "value": "http://localhost:8080"},
            {"key": "adminUser", "value": "admin"},
            {"key": "adminPass", "value": "admin"},
        ],
        "item": items,
    }
    path = os.path.join(out_dir, f"{package_id}.postman_collection.json")
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(collection, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    print(f"{path}: {len(items)} request(s)")


if __name__ == "__main__":
    main()

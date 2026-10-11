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

It reads only the package, so a collection cannot drift from it: rerun it
whenever the package changes.

Usage:
  package-to-newman.py lib/Settings/packages/<id> tests/integration/packages
"""

import json
import os
import sys

OBJECTS = "{{baseUrl}}/index.php/apps/openregister/api/objects/dossiq"
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
    for case_type in case_types:
        label = case_type.get("identifier") or case_type["id"]
        items.append(row_read(f"case type {label}", "caseType", case_type, CASE_TYPE_FIELDS))
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
        for flow in (f for f in flows if f.get("caseType") == case_type["id"]):
            items.append(row_read(f"{label}: flow", "workflowTemplate", flow, ("title", "caseType")))

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

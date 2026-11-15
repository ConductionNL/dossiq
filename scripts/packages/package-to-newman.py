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
from dataclasses import dataclass
from typing import Any

OBJECTS = "{{baseUrl}}/index.php/apps/openregister/api/objects/dossiq"
ACTIVE_FLOW = "{{baseUrl}}/index.php/apps/dossiq/api/workflow-definitions/active"
FLOW_FIELDS = ("title", "caseType", "variant", "lifecycleStatus", "isActive", "isDraft")
SEED_FIELDS = ("name", "caseType", "status")
CASE_TYPE_FIELDS = ("title", "identifier", "processingDeadline", "confidentiality", "publicationRequired")
NAME_FIELDS = ("name", "caseType")
CHILDREN = (
    ("roleTypes", "roleType", "role"),
    ("propertyDefinitions", "propertyDefinition", "property"),
    ("documentTypes", "documentType", "document type"),
    ("resultTypes", "resultType", "result type"),
)
AUTH = {"type": "basic", "basic": [
    {"key": "username", "value": "{{adminUser}}", "type": "string"},
    {"key": "password", "value": "{{adminPass}}", "type": "string"},
]}
VARIABLES = [
    {"key": "baseUrl", "value": "http://localhost:8080"},
    {"key": "adminUser", "value": "admin"},
    {"key": "adminPass", "value": "admin"},
]


@dataclass(frozen=True)
class Target:
    """A named GET request."""
    name: str
    url: str


@dataclass(frozen=True)
class RowRead:
    """A read-back of one row: its request name, its schema and the fields it checks."""
    name: str
    schema: str
    fields: tuple


@dataclass(frozen=True)
class Package:
    """What a package directory holds."""
    path: str
    definition: dict
    flows: list

    @property
    def case_types(self) -> list:
        return as_list(self.definition.get("caseType"))

    @property
    def has_draft(self) -> bool:
        return any(is_draft(f) for f in self.flows)


@dataclass(frozen=True)
class Owned:
    """One case type of a package, with the label its requests carry."""
    label: str
    case_type: dict
    package: Package


@dataclass(frozen=True)
class Release:
    """The package id and version the ledger row must name."""
    package_id: str
    version: str


@dataclass(frozen=True)
class Seed:
    """One seed file: its file name and its content."""
    name: str
    data: dict


def as_list(value: object) -> list:
    if isinstance(value, list):
        return value
    return [value] if isinstance(value, dict) else []


def is_draft(flow: dict) -> bool:
    return flow.get("lifecycleStatus") == "draft"


def label_of(case_type: dict) -> str:
    return case_type.get("identifier") or case_type["id"]


def load_json(path: str) -> Any:
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def read(target: Target, checks: list) -> dict:
    return {
        "name": target.name,
        "request": {"method": "GET", "header": [{"key": "Accept", "value": "application/json"}], "url": target.url},
        "event": [{"listen": "test", "script": {"type": "text/javascript", "exec": checks}}],
    }


def row_read(spec: RowRead, row: dict) -> dict:
    checks = ["pm.test('row is there (200)', () => pm.response.to.have.status(200));", "const row = pm.response.json();"]
    for field in spec.fields:
        if field in row and isinstance(row[field], (str, int, bool)):
            checks.append(f"pm.test('{field} as shipped', () => pm.expect(row.{field}).to.eql({json.dumps(row[field], ensure_ascii=False)}));")
    return read(Target(spec.name, f"{OBJECTS}/{spec.schema}/{row['id']}"), checks)


def case_type_read(label: str, case_type: dict) -> dict:
    item = row_read(RowRead(f"case type {label}", "caseType", CASE_TYPE_FIELDS), case_type)
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


def seed_files(package_dir: str):
    seed_dir = os.path.join(package_dir, "seed")
    for name in sorted(os.listdir(seed_dir)) if os.path.isdir(seed_dir) else []:
        yield Seed(name, load_json(os.path.join(seed_dir, name)))


def owner_prefix(row: dict, labels: dict) -> str:
    owner = labels.get(row.get("caseType"))
    return f"{owner}: " if owner else ""


def seed_items(seed: Seed, labels: dict) -> list:
    if not isinstance(seed.data.get("schema"), str):
        # A seed in another register or another app has its own address; this script reads dossiq's.
        sys.exit(f"{seed.name}: the seed names its schema by app, register and slug, which this script does not read back")
    stem = os.path.splitext(seed.name)[0]
    return [row_read(RowRead(f"{owner_prefix(row, labels)}{stem} {row.get('name', row['id'])}",
                             schema_slug(seed.data["schema"]), SEED_FIELDS), row)
            for row in seed.data.get("rows", [])]


def seed_reads(package_dir: str, labels: dict) -> list:
    items = []
    for seed in seed_files(package_dir):
        items += seed_items(seed, labels)
    return items


def rows_of(owned: Owned, key: str) -> list:
    return [r for r in owned.package.definition.get(key, []) if r.get("caseType") == owned.case_type["id"]]


def flows_of(owned: Owned) -> list:
    return [f for f in owned.package.flows if f.get("caseType") == owned.case_type["id"]]


def status_items(owned: Owned) -> list:
    statuses = sorted(rows_of(owned, "statusTypes"), key=lambda s: s.get("order", 0))
    if not statuses:
        return []
    items = [row_read(RowRead(f"{owned.label}: first status", "statusType", NAME_FIELDS), statuses[0])]
    finals = [s for s in statuses if s.get("isFinal")]
    if finals:
        items.append(row_read(RowRead(f"{owned.label}: final status", "statusType", NAME_FIELDS), finals[-1]))
    return items


def child_items(owned: Owned) -> list:
    items = []
    for key, schema, noun in CHILDREN:
        rows = rows_of(owned, key)
        # Every role, since a role is what a guard names; the first of the rest.
        for row in rows if key == "roleTypes" else rows[:1]:
            items.append(row_read(RowRead(f"{owned.label}: {noun} {row.get('name', '')}".rstrip(), schema, NAME_FIELDS), row))
    return items


def flow_items(owned: Owned) -> list:
    spec = RowRead(f"{owned.label}: flow", "workflowTemplate", ("title", "caseType"))
    return [row_read(spec, f) for f in owned.package.flows
            if f.get("caseType") == owned.case_type["id"] and owned.package.has_draft is False]


def case_type_items(owned: Owned) -> list:
    return [case_type_read(owned.label, owned.case_type), *status_items(owned), *child_items(owned), *flow_items(owned)]


def published_ids(owned: Owned, mine: list) -> list:
    pinned = owned.case_type.get("workflowDefinition")
    return [pinned] if pinned else [f["id"] for f in mine if f.get("lifecycleStatus") == "published"]


def followed_checks(drafts: list, published: list) -> list:
    checks = [
        "pm.test('a case of this type has a flow to follow (200)', () => pm.response.to.have.status(200));",
        "const definition = pm.response.json().definition;",
        "pm.test('the flow a case follows is published', () => pm.expect(definition.lifecycleStatus).to.eql('published'));",
        f"pm.test('the flow a case follows is not a draft of the package', () => pm.expect({json.dumps(drafts)}).to.not.include(definition.id));",
    ]
    if published:
        checks.append(f"pm.test('the flow a case follows is the published one of the package', () => pm.expect({json.dumps(published)}).to.include(definition.id));")
    return checks


def followed_read(owned: Owned) -> dict:
    mine = flows_of(owned)
    drafts = [f["id"] for f in mine if is_draft(f)]
    checks = followed_checks(drafts, published_ids(owned, mine))
    item = read(Target(f"{owned.label}: the flow a case follows", f"{ACTIVE_FLOW}/{owned.case_type['id']}"), checks)
    item["request"]["header"].append({"key": "OCS-APIRequest", "value": "true"})
    return item


def lifecycle_items(owned: Owned) -> list:
    mine = flows_of(owned)
    items = []
    for flow in mine:
        kind = "draft flow" if is_draft(flow) else "flow"
        items.append(row_read(RowRead(f"{owned.label}: {kind} {flow['title']}", "workflowTemplate", FLOW_FIELDS), flow))
    if any(is_draft(f) for f in mine):
        items.append(followed_read(owned))
    return items


def package_items(package: Package) -> list:
    case_types = package.case_types
    items = []
    for case_type in case_types:
        items += case_type_items(Owned(label_of(case_type), case_type, package))
    items.extend(seed_reads(package.path, {c["id"]: label_of(c) for c in case_types}))
    if package.has_draft is True:
        for case_type in case_types:
            items += lifecycle_items(Owned(label_of(case_type), case_type, package))
    return items


def ledger_read(release: Release, first: str) -> dict:
    return read(Target("ledger records the package", f"{OBJECTS}/shippedOrigin?targetObject={first}&_limit=5"), [
        "pm.test('ledger reachable (200)', () => pm.response.to.have.status(200));",
        "const body = pm.response.json();",
        "const rows = Array.isArray(body) ? body : (body.results || []);",
        f"const row = rows.find(r => r.targetObject === '{first}');",
        "pm.test('a ledger row names the package and its version', () => {",
        "    pm.expect(row, 'ledger row for the case type').to.be.an('object');",
        f"    pm.expect(row.set).to.eql('{release.package_id}');",
        f"    pm.expect(row.setVersion).to.eql('{release.version}');",
        "});",
    ])


def collection(package_id: str, items: list) -> dict:
    info = {
        "name": f"dossiq package: {package_id}",
        "description": f"Reads every kind of row the {package_id} package writes back through the OpenRegister objects API, "
                       f"after the packages workflow installed it on a clean instance (hydra ADR-118). Ids are the fixed ids in "
                       f"lib/Settings/packages/{package_id}/. Written by scripts/packages/package-to-newman.py.",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    }
    return {"info": info, "auth": AUTH, "variable": VARIABLES, "item": items}


def load_package(package_dir: str) -> Package:
    definition = load_json(os.path.join(package_dir, "case-type.json"))
    flow_dir = os.path.join(package_dir, "flows")
    names = sorted(os.listdir(flow_dir)) if os.path.isdir(flow_dir) else []
    return Package(package_dir, definition, [load_json(os.path.join(flow_dir, name)) for name in names])


def write_json(path: str, data: object) -> None:
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=2)
        handle.write("\n")


def main() -> None:
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    package_dir, out_dir = sys.argv[1], sys.argv[2]
    manifest = load_json(os.path.join(package_dir, "package.json"))
    package = load_package(package_dir)
    release = Release(manifest["id"], manifest["version"])
    items = package_items(package)
    items.append(ledger_read(release, package.case_types[0]["id"]))
    path = os.path.join(out_dir, f"{release.package_id}.postman_collection.json")
    write_json(path, collection(release.package_id, items))
    print(f"{path}: {len(items)} request(s)")


if __name__ == "__main__":
    main()

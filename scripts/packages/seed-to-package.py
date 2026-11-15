#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Convert a seeder's case type payload into a case definition package.

Hydra ADR-118: a procedure is configuration, shipped as a package under
lib/Settings/packages/<id>/ and written by the package installer. The old
seeders resolved status and role NAMES into ids at install time, with random
UUIDs, so every instance held different ids and a second run could not
recognise its own rows. This script does that resolution once, offline, and
writes fixed UUIDs into the package, so the installer only copies rows.

It is a conversion tool, kept out of lib/ on purpose: nothing at runtime
reads it. Run it again only to regenerate a package from the payload it was
converted from; edit the package by hand after that.

Input: one or more JSON files (or `git show <ref>:<path>`) holding a list of
case types, or one case type, under --key, in the seeder shape: each with
nested `statusTypes`, `roleTypes`, `propertyDefinitions`, `documentTypes`,
`resultTypes` and an optional `workflowTemplate` whose steps and transitions
name statuses by `statusName`, `fromStatusName` and `toStatusName`. An
`initialStatusName` becomes `initialStatus`, the id of that status. A case
type without an id gets a UUIDv5 of its identifier.

Resolution follows the runtime resolver it replaces
(Besluitvorming/WorkflowReferenceResolver) exactly: step and transition
status names become status ids, a roleGuard or action
`config.roleName` gains `config.roleId`, and steps and transitions are
stored JSON-encoded because the workflowTemplate schema types them as
strings. One change from the runtime: a `*` source status, which no
reader honours, becomes one transition from every open status. Every id is a UUIDv5 of the case type id and the row's name, so a
rerun produces the same package.

Usage:
  seed-to-package.py --source lib/Settings/x.json --key caseTypes \\
      --id my-package --title "My package" --version 1.0.0 \\
      --install on-request --out lib/Settings/packages/my-package \\
      [--git-ref origin/development] [--only id1,id2] [--published]
"""

import argparse
import json
import os
import subprocess
import sys
import uuid
from dataclasses import dataclass, field
from typing import Any

NAMESPACE = uuid.uuid5(uuid.NAMESPACE_URL, "https://conduction.nl/dossiq/case-definition-packages")
CHILD_KINDS = {
    "statusTypes": "statusType",
    "roleTypes": "roleType",
    "propertyDefinitions": "propertyDefinition",
    "documentTypes": "documentType",
    "resultTypes": "resultType",
}
CHILD_KEYS = (*CHILD_KINDS, "workflowTemplate", "initialStatusName")
COMPACT: dict[str, Any] = {"ensure_ascii": False, "separators": (",", ":")}


@dataclass(frozen=True)
class Owner:
    """The case type a child row belongs to, and the kind of that row."""
    case_type_id: str
    kind: str


@dataclass(frozen=True)
class Entry:
    """One case type of the payload, with the fixed id it gets."""
    case_type: dict
    case_type_id: str
    identifier: str


@dataclass(frozen=True)
class FlowNames:
    """What a flow's status and role names resolve to."""
    case_type_id: str
    statuses: dict
    roles: dict
    open_statuses: list
    published: bool

    @property
    def where(self) -> str:
        return f"{self.case_type_id} workflowTemplate"


@dataclass(frozen=True)
class Edge:
    """One transition from one source status, before its names are resolved."""
    body: dict
    source: str
    target: str


@dataclass
class Package:
    """The rows and flows collected so far."""
    published: bool
    collections: dict = field(default_factory=lambda: {"caseType": [], **{key: [] for key in CHILD_KINDS}})
    flows: dict = field(default_factory=dict)


def fixed_id(*parts: str) -> str:
    return str(uuid.uuid5(NAMESPACE, "/".join(parts)))


def load(source: str, git_ref: str | None) -> object:
    if git_ref:
        text = subprocess.run(["git", "show", f"{git_ref}:{source}"], check=True, capture_output=True, text=True).stdout
    else:
        with open(source, encoding="utf-8") as handle:
            text = handle.read()
    return json.loads(text)


def check_names(rows: list, owner: Owner) -> None:
    seen = set()
    for row in rows:
        name = str(dict(row).get("name", ""))
        if name == "":
            sys.exit(f"{owner.case_type_id}: a {owner.kind} row has no name, so nothing can refer to it.")
        if name in seen:
            sys.exit(f"{owner.case_type_id}: two {owner.kind} rows are named '{name}'.")
        seen.add(name)


def child_row(row: dict, owner: Owner) -> dict:
    row = dict(row)
    row["id"] = str(row.get("id") or fixed_id(owner.case_type_id, owner.kind, str(row.get("name", ""))))
    row["caseType"] = owner.case_type_id
    return {"id": row.pop("id"), **row}


def children(rows: list, owner: Owner) -> tuple[list, dict]:
    check_names(rows, owner)
    out = [child_row(row, owner) for row in rows]
    return out, {str(row.get("name", "")): row["id"] for row in out}


def status_ref(name: str, names: FlowNames) -> str:
    if name == "*":
        return "*"
    if name not in names.statuses:
        sys.exit(f"{names.where}: no status is named '{name}'.")
    return names.statuses[name]


def with_role_id(entry: dict, names: FlowNames) -> dict:
    config = entry.get("config")
    if isinstance(config, dict) and "roleName" in config:
        if config["roleName"] not in names.roles:
            sys.exit(f"{names.where}: no role is named '{config['roleName']}'.")
        entry = {**entry, "config": {**config, "roleId": names.roles[config["roleName"]]}}
    return entry


def flow_step(step: dict, names: FlowNames) -> dict:
    step = dict(step)
    name = str(step.pop("statusName", ""))
    return {"id": fixed_id(names.case_type_id, "step", name, str(step.get("order", "")), str(step.get("title", ""))),
            **step, "status": status_ref(name, names)}


def flow_transition(edge: Edge, names: FlowNames) -> dict:
    body = edge.body
    return {
        "id": fixed_id(names.case_type_id, "transition", edge.source, edge.target, str(body.get("label", ""))),
        **body,
        "fromStatus": status_ref(edge.source, names),
        "toStatus": status_ref(edge.target, names),
        "guards": [with_role_id(g, names) for g in body.get("guards", [])],
        "automaticActions": [with_role_id(a, names) for a in body.get("automaticActions", [])],
    }


def expand_transition(transition: dict, names: FlowNames) -> list:
    transition = dict(transition)
    source = str(transition.pop("fromStatusName", ""))
    target = str(transition.pop("toStatusName", ""))
    # No reader honours a `*` source (CaseType/CaseTypeReachability), so a
    # wildcard becomes one transition from every open status but the target.
    sources = [source] if source != "*" else [name for name in names.open_statuses if name != target]
    return [flow_transition(Edge(transition, each, target), names) for each in sources]


def flow(template: dict, names: FlowNames) -> dict:
    steps = [flow_step(step, names) for step in template.get("steps", [])]
    transitions = [row for each in template.get("transitions", []) for row in expand_transition(each, names)]
    row = {"id": fixed_id(names.case_type_id, "workflowTemplate"), **template, "caseType": names.case_type_id}
    if names.published:
        row["lifecycleStatus"] = "published"
    row["steps"] = json.dumps(steps, **COMPACT)
    row["transitions"] = json.dumps(transitions, **COMPACT)
    return row


def write(path: str, data: object) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=2)
        handle.write("\n")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--source", required=True, action="append", help="Repeat for several files.")
    parser.add_argument("--key", required=True, help="Top-level key holding the case type list.")
    parser.add_argument("--git-ref", help="Read --source from this git ref instead of the working tree.")
    parser.add_argument("--only", help="Comma-separated case type identifiers to keep, in order.")
    parser.add_argument("--id", required=True)
    parser.add_argument("--title", required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--install", choices=["auto", "on-request"], required=True)
    parser.add_argument("--description", default="")
    parser.add_argument("--published", action="store_true", help="Mark each flow lifecycleStatus=published.")
    parser.add_argument("--out", required=True)
    return parser.parse_args()


def case_types_in(source: str, args: argparse.Namespace) -> list:
    payload = load(source, args.git_ref)
    found = payload.get(args.key) if isinstance(payload, dict) else None
    if isinstance(found, dict):
        found = [found]
    if not isinstance(found, list) or found == []:
        sys.exit(f"{source}: '{args.key}' holds no case type.")
    return found


def absent(wanted: list, by_identifier: dict) -> list:
    return [w for w in wanted if w not in by_identifier]


def keep_only(case_types: list, args: argparse.Namespace) -> list:
    if not args.only:
        return case_types
    wanted = args.only.split(",")
    by_identifier = {str(c.get("identifier")): c for c in case_types}
    missing = absent(wanted, by_identifier)
    if missing:
        sys.exit(f"{', '.join(args.source)}: no case type with identifier {', '.join(missing)}.")
    return [by_identifier[w] for w in wanted]


def read_case_types(args: argparse.Namespace) -> list:
    case_types = []
    for source in args.source:
        case_types += case_types_in(source, args)
    return keep_only(case_types, args)


def entry_of(case_type: dict) -> Entry:
    identifier = str(case_type.get("identifier") or "")
    case_type_id = str(case_type.get("id") or "")
    if case_type_id == "":
        if identifier == "":
            sys.exit("a case type has neither an id nor an identifier, so it cannot get a fixed id.")
        case_type_id = fixed_id("caseType", identifier)
    return Entry(case_type, case_type_id, identifier)


def add_children(entry: Entry, package: Package) -> dict:
    ids = {}
    for key, kind in CHILD_KINDS.items():
        rows, ids[key] = children(entry.case_type.get(key, []), Owner(entry.case_type_id, kind))
        package.collections[key] += rows
    return ids


def is_copied(item: tuple, case_type_id: str) -> bool:
    key, value = item
    if key in CHILD_KEYS or key == "id":
        return False
    # `uuid` repeats `id`; the caseType schema has no such property.
    return not (key == "uuid" and value == case_type_id)


def initial_status(entry: Entry, statuses: dict) -> dict:
    # `initialStatus` may name a status rather than hold its id; the
    # bundle form says `initialStatusName`.
    case_type = entry.case_type
    initial = case_type.get("initialStatusName", case_type.get("initialStatus"))
    if initial in statuses:
        return {"initialStatus": statuses[initial]}
    if "initialStatusName" in case_type:
        sys.exit(f"{entry.case_type_id}: initialStatusName '{initial}' names no status.")
    return {}


def case_type_row(entry: Entry, statuses: dict) -> dict:
    kept = {k: v for k, v in entry.case_type.items() if is_copied((k, v), entry.case_type_id)}
    return {"id": entry.case_type_id, **kept, **initial_status(entry, statuses)}


def open_statuses(case_type: dict) -> list:
    return [str(s.get("name")) for s in case_type.get("statusTypes", []) if not s.get("isFinal")]


def add_case_type(case_type: dict, package: Package) -> None:
    entry = entry_of(case_type)
    ids = add_children(entry, package)
    package.collections["caseType"].append(case_type_row(entry, ids["statusTypes"]))
    template = case_type.get("workflowTemplate")
    if isinstance(template, dict):
        names = FlowNames(entry.case_type_id, ids["statusTypes"], ids["roleTypes"],
                          open_statuses(case_type), package.published)
        package.flows[entry.identifier or entry.case_type_id] = flow(template, names)


def manifest(args: argparse.Namespace, package: Package) -> dict:
    parts = ["case-type"] + (["flows"] if package.flows else [])
    row = {"id": args.id, "title": args.title, "version": args.version, "requires": [], "parts": parts, "install": args.install}
    if args.description:
        row["description"] = args.description
    return row


def write_package(args: argparse.Namespace, package: Package) -> None:
    collections = package.collections
    if len(collections["caseType"]) == 1:
        collections["caseType"] = collections["caseType"][0]
    write(os.path.join(args.out, "package.json"), manifest(args, package))
    write(os.path.join(args.out, "case-type.json"), {k: v for k, v in collections.items() if v})
    for name, row in package.flows.items():
        write(os.path.join(args.out, "flows", f"{name}.json"), row)


def main() -> None:
    args = parse_args()
    case_types = read_case_types(args)
    package = Package(args.published)
    for case_type in case_types:
        add_case_type(case_type, package)
    write_package(args, package)
    counts = ", ".join(f"{len(package.collections[key])} {key}" for key in CHILD_KINDS)
    print(f"{args.out}: {len(case_types)} case type(s), {counts}, {len(package.flows)} flow(s)")


if __name__ == "__main__":
    main()

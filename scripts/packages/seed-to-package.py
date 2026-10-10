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

Input: a JSON file (or `git show <ref>:<path>`) holding a list of case types
in the seeder shape, each with nested `statusTypes`, `roleTypes` and an
optional `workflowTemplate` whose steps and transitions name statuses by
`statusName`, `fromStatusName` and `toStatusName`.

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

NAMESPACE = uuid.uuid5(uuid.NAMESPACE_URL, "https://conduction.nl/dossiq/case-definition-packages")
CHILD_KEYS = ("statusTypes", "roleTypes", "workflowTemplate")


def fixed_id(*parts: str) -> str:
    return str(uuid.uuid5(NAMESPACE, "/".join(parts)))


def load(source: str, git_ref: str | None) -> object:
    if git_ref:
        text = subprocess.run(["git", "show", f"{git_ref}:{source}"], check=True, capture_output=True, text=True).stdout
    else:
        with open(source, encoding="utf-8") as handle:
            text = handle.read()
    return json.loads(text)


def children(rows: list, case_type_id: str, kind: str) -> tuple[list, dict]:
    out, by_name = [], {}
    for row in rows:
        row = dict(row)
        name = str(row.get("name", ""))
        if name == "":
            sys.exit(f"{case_type_id}: a {kind} row has no name, so nothing can refer to it.")
        if name in by_name:
            sys.exit(f"{case_type_id}: two {kind} rows are named '{name}'.")
        row["id"] = str(row.get("id") or fixed_id(case_type_id, kind, name))
        row["caseType"] = case_type_id
        by_name[name] = row["id"]
        out.append({"id": row.pop("id"), **row})
    return out, by_name


def status_ref(name: str, statuses: dict, where: str) -> str:
    if name == "*":
        return "*"
    if name not in statuses:
        sys.exit(f"{where}: no status is named '{name}'.")
    return statuses[name]


def with_role_id(entry: dict, roles: dict, where: str) -> dict:
    config = entry.get("config")
    if isinstance(config, dict) and "roleName" in config:
        if config["roleName"] not in roles:
            sys.exit(f"{where}: no role is named '{config['roleName']}'.")
        entry = {**entry, "config": {**config, "roleId": roles[config["roleName"]]}}
    return entry


def flow(template: dict, case_type_id: str, statuses: dict, roles: dict, published: bool, open_statuses: list) -> dict:
    where = f"{case_type_id} workflowTemplate"
    steps = []
    for step in template.get("steps", []):
        step = dict(step)
        name = str(step.pop("statusName", ""))
        steps.append({"id": fixed_id(case_type_id, "step", name, str(step.get("order", "")), str(step.get("title", ""))),
                      **step, "status": status_ref(name, statuses, where)})
    transitions = []
    for transition in template.get("transitions", []):
        transition = dict(transition)
        source = str(transition.pop("fromStatusName", ""))
        target = str(transition.pop("toStatusName", ""))
        # No reader honours a `*` source (CaseType/CaseTypeReachability), so a
        # wildcard becomes one transition from every open status but the target.
        sources = [source] if source != "*" else [name for name in open_statuses if name != target]
        for each in sources:
            transitions.append({
                "id": fixed_id(case_type_id, "transition", each, target, str(transition.get("label", ""))),
                **transition,
                "fromStatus": status_ref(each, statuses, where),
                "toStatus": status_ref(target, statuses, where),
                "guards": [with_role_id(g, roles, where) for g in transition.get("guards", [])],
                "automaticActions": [with_role_id(a, roles, where) for a in transition.get("automaticActions", [])],
            })
    row = {"id": fixed_id(case_type_id, "workflowTemplate"), **template, "caseType": case_type_id}
    if published:
        row["lifecycleStatus"] = "published"
    compact = {"ensure_ascii": False, "separators": (",", ":")}
    row["steps"] = json.dumps(steps, **compact)
    row["transitions"] = json.dumps(transitions, **compact)
    return row


def write(path: str, data: object) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=2)
        handle.write("\n")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--source", required=True)
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
    args = parser.parse_args()

    payload = load(args.source, args.git_ref)
    case_types = payload.get(args.key) if isinstance(payload, dict) else None
    if not isinstance(case_types, list) or case_types == []:
        sys.exit(f"{args.source}: '{args.key}' holds no list of case types.")
    if args.only:
        wanted = args.only.split(",")
        by_identifier = {str(c.get("identifier")): c for c in case_types}
        missing = [w for w in wanted if w not in by_identifier]
        if missing:
            sys.exit(f"{args.source}: no case type with identifier {', '.join(missing)}.")
        case_types = [by_identifier[w] for w in wanted]

    collections = {"caseType": [], "statusTypes": [], "roleTypes": []}
    flows = {}
    for case_type in case_types:
        case_type_id = str(case_type.get("id") or "")
        if case_type_id == "":
            sys.exit(f"case type '{case_type.get('identifier')}' has no fixed id; give it one in the source first.")
        statuses, status_ids = children(case_type.get("statusTypes", []), case_type_id, "statusType")
        roles, role_ids = children(case_type.get("roleTypes", []), case_type_id, "roleType")
        # `uuid` repeats `id`; the caseType schema has no such property.
        row = {k: v for k, v in case_type.items() if k not in CHILD_KEYS and not (k == "uuid" and v == case_type_id)}
        # `initialStatus` may name a status rather than hold its id.
        if row.get("initialStatus") in status_ids:
            row["initialStatus"] = status_ids[row["initialStatus"]]
        collections["caseType"].append(row)
        collections["statusTypes"] += statuses
        collections["roleTypes"] += roles
        template = case_type.get("workflowTemplate")
        if isinstance(template, dict):
            flows[str(case_type.get("identifier") or case_type_id)] = flow(template, case_type_id, status_ids, role_ids, args.published,
                [str(s.get("name")) for s in statuses if not s.get("isFinal")])

    if len(collections["caseType"]) == 1:
        collections["caseType"] = collections["caseType"][0]
    parts = ["case-type"] + (["flows"] if flows else [])
    manifest = {"id": args.id, "title": args.title, "version": args.version, "requires": [], "parts": parts, "install": args.install}
    if args.description:
        manifest["description"] = args.description

    write(os.path.join(args.out, "package.json"), manifest)
    write(os.path.join(args.out, "case-type.json"), {k: v for k, v in collections.items() if v})
    for name, row in flows.items():
        write(os.path.join(args.out, "flows", f"{name}.json"), row)
    print(f"{args.out}: {len(case_types)} case type(s), {len(collections['statusTypes'])} status(es), "
          f"{len(collections['roleTypes'])} role(s), {len(flows)} flow(s)")


if __name__ == "__main__":
    main()

"""Enumerate every @e2e citation in an app's e2e suite, with gate-19 verdicts.

Broader than gate-19's own regexes on purpose: the audit population includes
anchorless citations and citations into openspec/changes/**, neither of which
the gate can parse. Those are reported with gate19_credits_it=no.
"""
import csv
import importlib.util
import re
import sys
from pathlib import Path

GATE = Path(sys.argv[1])           # check_e2e_coverage.py
APP = Path(sys.argv[2]).resolve()  # app root
OUT = Path(sys.argv[3])

spec = importlib.util.spec_from_file_location("g", GATE)
g = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g)

if not hasattr(g, "covering_ref"):
    # Pre-#753 gate: an anchor is credited only by exact match.
    def _cr(ref, pool, declared=None):
        return ref if ref in pool else None
    g.covering_ref = _cr

# Any @e2e directive target: a path-ish or `a::b` token, optional #anchor.
E2E_ANY = re.compile(r"@e2e\s+(?P<target>[^\s*]+)")

spec_root = APP / "openspec" / "specs"
all_scen = []
for md in g.spec_files(spec_root):
    all_scen.extend(g.parse_spec_scenarios(md))
declared = {s["ref"] for s in all_scen}
scen_by_ref = {s["ref"]: s for s in all_scen}
excluded_refs = {s["ref"] for s in all_scen if s["excluded"] and not s["bare_exclude"]}

live, dead = g.collect_ref_status(APP)
scope = g._PlaywrightScope(APP)

rows = []
e2e_dir = APP / "tests" / "e2e"
for p in sorted(e2e_dir.rglob("*")):
    if not p.is_file():
        continue
    if not (p.suffix in (".ts", ".js") and (
            p.stem.endswith(".spec") or p.stem.endswith(".test")
            or ".spec." in p.name or ".test." in p.name)):
        continue
    rel = str(p.relative_to(APP))
    text = p.read_text(encoding="utf-8")
    doc = g._TestFile(text)
    file_runs = scope.runs(rel)
    for m in E2E_ANY.finditer(text):
        target = m.group("target").rstrip(".,;)`'\"")
        if target.lower().startswith("exclude"):
            continue
        # A target that names neither a path nor a `spec::slug` is prose.
        if "/" not in target and "::" not in target:
            continue
        if not doc.is_directive(m.start()):
            kind = "prose-mention"
        else:
            kind = "directive"
        node = doc.owner(m.end())
        if node is not None:
            h0, h1 = node.header
            import re as _re
            _T = _re.compile(r"""(['"`])((?:\\.|(?!\1).)*)\1""", _re.S)
            tm = _T.search(text[h0:h1])
            title = tm.group(2).replace("\\'", "'").replace('\\"', '"') if tm else ""
            decl = node.fn + ("." + ".".join(node.segments) if node.segments else "")
            off = node.switched_off
            par = node.parent
            while par is not None:
                off = off or par.switched_off
                par = par.parent
        else:
            title, decl, off = "", "", True
        runs = file_runs and g._ref_is_live(doc, m.end())
        # classify the citation target
        path, _, anchor = target.partition("#")
        short = "::" in target and not target.startswith("openspec/")
        if short:
            path, anchor = "", ""
            spec_name, _, slug = target.partition("::")
            ref = f"{spec_name}::{slug}"
            path, anchor = f"openspec/specs/{spec_name}/spec.md", slug
            resolution = "short form (spec::slug)"
            exists = spec_name in {g.spec_name_for(x) for x in g.spec_files(spec_root)}
        else:
            tgt = APP / path
            exists = tgt.is_file()
            if not anchor:
                ref = ""
                resolution = "no anchor: cites a whole spec file" if exists \
                    else "no anchor, and the file does not exist"
            elif path.startswith("openspec/changes/"):
                ref = ""
                resolution = ("cites openspec/changes/**, which gate-19 does not parse"
                              if exists else
                              "cites openspec/changes/**, and the file does not exist")
            elif path.startswith("openspec/specs/"):
                mm = g._E2E_PATH_RE.search(m.group(0))
                if mm:
                    sname = mm.group("spec") or mm.groupdict().get("flatspec")
                    ref = f"{sname}::{mm.group('slug')}"
                else:
                    ref = ""
                if not exists:
                    resolution = "the cited spec file does not exist"
                elif ref and g.covering_ref(ref, declared, declared) is not None:
                    resolution = "resolves to a scenario"
                elif ref and any(g.covering_ref(d, {ref}, declared) is not None for d in declared):
                    resolution = "resolves to a scenario"
                else:
                    resolution = "the anchor matches no scenario in that spec"
            else:
                ref = ""
                resolution = "cites a path outside openspec/specs"

        # does gate-19 credit it? the ref must be live AND address a declared scenario
        credits = "no"
        matched_scen = ""
        if ref:
            for d in declared:
                if g.covering_ref(d, {ref}, declared) is not None:
                    matched_scen = d
                    break
            if matched_scen and ref in live:
                credits = "yes"
            elif matched_scen and matched_scen in excluded_refs:
                credits = "no (scenario is @e2e exclude)"
            elif matched_scen:
                credits = "no (test does not run)"
        rows.append({
            "citation_spec_path": path,
            "anchor": anchor,
            "short_ref": ref if short else "",
            "test_file": rel,
            "test_name": title,
            "decl": decl,
            "directive": kind,
            "runs": "yes" if runs else "no",
            "file_runs": "yes" if file_runs else "no",
            "resolution": resolution,
            "gate19_credits_it": credits,
            "gate_ref": ref,
            "scenario_excluded": "yes" if matched_scen in excluded_refs else "no",
            "line": text[:m.start()].count("\n") + 1,
        })

with OUT.open("w", newline="") as fh:
    w = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
    w.writeheader()
    w.writerows(rows)
print(f"{len(rows)} rows -> {OUT}")

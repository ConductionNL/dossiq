# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Golden tests for scripts/packages/seed-to-package.py and package-to-newman.py.

Each scenario runs a script on the fixtures and compares its exit code, its
output, the last line of its error stream and every file it writes, byte for
byte, with golden.json. Set PACKAGE_SCRIPTS_RECORD=1 to rewrite golden.json,
then run once more without it: a recording run compares nothing.

  python3 -m unittest discover -s tests/scripts/packages -p 'test_*.py'
"""

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
FIXTURES = HERE / "fixtures"
GOLDEN = HERE / "golden.json"
SEED = ROOT / "scripts" / "packages" / "seed-to-package.py"
NEWMAN = ROOT / "scripts" / "packages" / "package-to-newman.py"
PACKAGE = ["--key", "caseTypes", "--id", "fixture", "--title", "Fixture", "--version", "1.0.0"]
SEED_ERRORS = [
    "unnamed-row", "duplicate-row", "unknown-step-status", "unknown-transition-status",
    "unknown-role", "unknown-action-role", "unknown-initial", "no-id", "empty-list", "not-a-dict",
]


def run(script: Path, args: list, cwd: Path) -> dict:
    """Run one script and collect what it printed and wrote under cwd/out."""
    done = subprocess.run([sys.executable, str(script), *args], cwd=cwd, capture_output=True, text=True)
    out = cwd / "out"
    files = {}
    if out.is_dir():
        for path in sorted(p for p in out.rglob("*") if p.is_file()):
            files[str(path.relative_to(out))] = path.read_text(encoding="utf-8")
    lines = done.stderr.strip().splitlines()
    record = {"rc": done.returncode, "stdout": done.stdout, "stderr": lines[-1] if lines else "", "files": files}
    return json.loads(json.dumps(record).replace(str(cwd), "<tmp>").replace(str(FIXTURES), "<fixtures>"))


def seed_scenarios() -> dict:
    lst, single = str(FIXTURES / "seed-list.json"), str(FIXTURES / "seed-single.json")
    scenarios = {
        "seed: list, draft flows": ["--source", lst, *PACKAGE, "--install", "auto", "--out", "out"],
        "seed: two sources, published, description": [
            "--source", lst, "--source", single, *PACKAGE, "--install", "on-request",
            "--description", "Two sources", "--published", "--out", "out"],
        "seed: single case type": ["--source", single, *PACKAGE, "--install", "auto", "--out", "out"],
        "seed: only, reordered": ["--source", lst, *PACKAGE, "--install", "auto", "--only", "third,first", "--out", "out"],
        "seed: only, no flows": ["--source", lst, *PACKAGE, "--install", "auto", "--only", "third", "--out", "out"],
        "seed: only, missing": ["--source", lst, *PACKAGE, "--install", "auto", "--only", "first,nope,gone", "--out", "out"],
        "seed: other key": ["--source", lst, "--key", "other", "--id", "x", "--title", "x", "--version", "1",
                            "--install", "auto", "--out", "out"],
        "seed: no arguments": [],
    }
    for name in SEED_ERRORS:
        source = str(FIXTURES / "errors" / f"{name}.json")
        scenarios[f"seed: error {name}"] = ["--source", source, *PACKAGE, "--install", "auto", "--out", "out"]
    return scenarios


def write_packages(root: Path) -> None:
    """Write the fixture packages of packages.json under root, one directory each.

    They live in one file because eslint reads every package.json under tests/
    as an npm manifest.
    """
    packages = json.loads((FIXTURES / "packages.json").read_text(encoding="utf-8"))
    for name, files in packages.items():
        (root / name).mkdir(parents=True)
        for rel, content in files.items():
            (root / name / rel).parent.mkdir(parents=True, exist_ok=True)
            (root / name / rel).write_text(json.dumps(content, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def newman_scenarios() -> dict:
    names = ("flow-package", "draft-package", "pinned-draft-package", "foreign-seed", "no-manifest")
    scenarios = {f"newman: {name}": [f"packages/{name}", "out"] for name in names}
    scenarios["newman: wrong argument count"] = ["packages/flow-package"]
    return scenarios


def run_all() -> dict:
    results = {}
    for name, args in seed_scenarios().items():
        with tempfile.TemporaryDirectory() as tmp:
            results[name] = run(SEED, args, Path(tmp))
    for name, args in newman_scenarios().items():
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "out").mkdir()
            write_packages(Path(tmp) / "packages")
            results[name] = run(NEWMAN, args, Path(tmp))
    results.update(chained())
    results["seed: git ref"] = from_git_ref()
    return results


def chained() -> dict:
    """seed-to-package writes a package; package-to-newman reads it back."""
    results = {}
    for name, extra in (("chain: draft flows", []), ("chain: published flows", ["--published"])):
        with tempfile.TemporaryDirectory() as tmp:
            args = ["--source", str(FIXTURES / "seed-list.json"), *PACKAGE, "--install", "auto", *extra, "--out", "pkg"]
            subprocess.run([sys.executable, str(SEED), *args], cwd=tmp, check=True, capture_output=True)
            (Path(tmp) / "out").mkdir()
            results[name] = run(NEWMAN, ["pkg", "out"], Path(tmp))
    return results


def from_git_ref() -> dict:
    """--git-ref reads the source from a commit, not from the working tree."""
    with tempfile.TemporaryDirectory() as tmp:
        git = ["git", "-c", "user.name=t", "-c", "user.email=t@example.org", "-c", "commit.gpgsign=false"]
        subprocess.run(["git", "init", "-q"], cwd=tmp, check=True)
        (Path(tmp) / "seed.json").write_text((FIXTURES / "seed-single.json").read_text(encoding="utf-8"), encoding="utf-8")
        subprocess.run(["git", "add", "seed.json"], cwd=tmp, check=True)
        subprocess.run([*git, "commit", "-q", "-m", "seed"], cwd=tmp, check=True)
        (Path(tmp) / "seed.json").write_text("not json", encoding="utf-8")
        args = ["--source", "seed.json", "--git-ref", "HEAD", *PACKAGE, "--install", "auto", "--out", "out"]
        return run(SEED, args, Path(tmp))


class PackageScriptsGoldenTest(unittest.TestCase):
    """The scripts write exactly what golden.json holds."""

    def test_scripts_match_golden(self) -> None:
        results = run_all()
        if os.environ.get("PACKAGE_SCRIPTS_RECORD") == "1":
            GOLDEN.write_text(json.dumps(results, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            self.skipTest("recorded golden.json; run again without PACKAGE_SCRIPTS_RECORD to compare")
        golden = json.loads(GOLDEN.read_text(encoding="utf-8"))
        self.assertEqual(sorted(golden), sorted(results))
        for name, expected in golden.items():
            with self.subTest(scenario=name):
                self.assertEqual(expected, results[name])


if __name__ == "__main__":
    unittest.main()

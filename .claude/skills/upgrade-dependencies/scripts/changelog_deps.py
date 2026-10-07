#!/usr/bin/env python3
"""Print a Markdown "Dependencies" table for CHANGELOG.md from the manifests at two git refs.

Usage:  changelog_deps.py <from-ref> <to-ref>      e.g. changelog_deps.py v2.1.2 HEAD

Compares docker/app/Dockerfile, docker/wordpress/Dockerfile, app/composer.json,
wordpress/composer.json (require + require-dev + the Przelewy24 package entry) and
app/package.json. Only changed, added or removed entries are printed.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]


def show(ref: str, path: str) -> str | None:
    proc = subprocess.run(["git", "show", f"{ref}:{path}"], cwd=ROOT, capture_output=True, text=True)
    return proc.stdout if proc.returncode == 0 else None


def composer_entries(text: str | None) -> dict[str, str]:
    if text is None:
        return {}
    data = json.loads(text)
    entries = {**data.get("require", {}), **data.get("require-dev", {})}
    for repo in data.get("repositories", []):
        if repo.get("type") == "package":
            pkg = repo["package"]
            entries[pkg["name"]] = pkg["version"]
    entries.pop("php", None)
    return entries


def npm_entries(text: str | None) -> dict[str, str]:
    if text is None:
        return {}
    data = json.loads(text)
    return {**data.get("dependencies", {}), **data.get("devDependencies", {})}


def image_entries(app: str | None, wp: str | None) -> dict[str, str]:
    out: dict[str, str] = {}
    if app:
        if m := re.search(r"dunglas/frankenphp:(\d+\.\d+\.\d+)-builder-php(\d+\.\d+\.\d+)", app):
            out["FrankenPHP"], out["PHP"] = m.group(1), m.group(2)
        if m := re.search(r"FROM node:(\S+)", app):
            out["Node"] = m.group(1)
    if wp and (m := re.search(r"dunglas/frankenphp:(\d+\.\d+\.\d+)-php(\d+\.\d+\.\d+)", wp)):
        out.setdefault("FrankenPHP", m.group(1))
        out.setdefault("PHP", m.group(2))
    return out


def diff(old: dict[str, str], new: dict[str, str]) -> list[tuple[str, str, str]]:
    rows = []
    for name in sorted(set(old) | set(new)):
        if old.get(name) != new.get(name):
            rows.append((name, old.get(name, "—"), new.get(name, "— (removed)")))
    return rows


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 1
    a, b = sys.argv[1], sys.argv[2]

    sections = [
        ("Runtime", image_entries(show(a, "docker/app/Dockerfile"), show(a, "docker/wordpress/Dockerfile")),
         image_entries(show(b, "docker/app/Dockerfile"), show(b, "docker/wordpress/Dockerfile"))),
        ("WordPress (Composer)", composer_entries(show(a, "wordpress/composer.json")), composer_entries(show(b, "wordpress/composer.json"))),
        ("Laravel (Composer)", composer_entries(show(a, "app/composer.json")), composer_entries(show(b, "app/composer.json"))),
        ("Frontend (npm)", npm_entries(show(a, "app/package.json")), npm_entries(show(b, "app/package.json"))),
    ]

    printed = False
    for title, old, new in sections:
        rows = diff(old, new)
        if not rows:
            continue
        printed = True
        print(f"#### {title}\n")
        print("| Package | From | To |\n|---|---|---|")
        for name, o, n in rows:
            print(f"| {name} | {o} | {n} |")
        print()
    if not printed:
        print("_No manifest changes between these refs._")
    return 0


if __name__ == "__main__":
    sys.exit(main())

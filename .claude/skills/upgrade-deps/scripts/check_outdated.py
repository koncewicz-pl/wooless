#!/usr/bin/env python3
"""Collect every outdated dependency in the Wooless stack and optionally bump the manifests.

Sources checked (run from the repository root):
  * docker/app/Dockerfile, docker/wordpress/Dockerfile  -> Docker Hub tags (FrankenPHP/PHP, Node)
  * app/composer.json        -> ./bin/app composer outdated --direct
  * wordpress/composer.json  -> ./bin/wordpress composer outdated --direct
  * app/package.json         -> ./bin/app npm outdated
  * przelewy24/woo-przelewy24 (a Composer "package" repository pointing at the vendor's ZIP)
                             -> the vendor's update manifest (JSON served as P24_WooCommerce_*.ini)

Usage:
  check_outdated.py                 # Markdown report on stdout
  check_outdated.py --json          # machine-readable report
  check_outdated.py --apply         # also rewrite constraints in the three manifests
  check_outdated.py --apply --hold vite --hold vue   # keep extra packages on their current major

Dockerfiles are never modified; the report prints the exact tags to put there.
With --apply the Przelewy24 package entry (version, dist url, shasum) and its pin are rewritten
after the ZIP has been downloaded and checked, because Composer cannot discover new versions of a
"package" repository on its own.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

# Packages that must stay on their current MAJOR version even when a newer major exists.
# tailwindcss: the project is written for Tailwind v3 (see app/CLAUDE.md); v4 is a migration, not a bump.
DEFAULT_HOLDS = {"tailwindcss"}

ROOT = Path(__file__).resolve().parents[4]
APP_COMPOSER = ROOT / "app" / "composer.json"
WP_COMPOSER = ROOT / "wordpress" / "composer.json"
APP_PACKAGE = ROOT / "app" / "package.json"
APP_DOCKERFILE = ROOT / "docker" / "app" / "Dockerfile"
WP_DOCKERFILE = ROOT / "docker" / "wordpress" / "Dockerfile"

HUB = "https://hub.docker.com/v2/repositories/{repo}/tags?page_size=100{extra}"

# Przelewy24 ships its WooCommerce plugin only as a ZIP on its own site, so wordpress/composer.json
# declares it as a "package" repository. The plugin's own updater reads this manifest; we read the
# same one. The installed plugin's WC_P24_UPDATE_URL constant wins over the fallback when present.
P24_PACKAGE = "przelewy24/woo-przelewy24"
P24_PLUGIN_MAIN = ROOT / "wordpress" / "web" / "app" / "plugins" / "woo-przelewy24" / "woocommerce-p24-gateway.php"
P24_MANIFEST_FALLBACK = (
    "https://www.przelewy24.pl/storage/app/media/do-pobrania/gotowe-wtyczki/woocommerce/P24_WooCommerce_11.ini"
)


# --------------------------------------------------------------------------- helpers
def run(cmd: list[str]) -> tuple[int, str, str]:
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    return proc.returncode, proc.stdout, proc.stderr


def ver_tuple(version: str) -> tuple[int, ...]:
    return tuple(int(p) for p in re.findall(r"\d+", version)[:3])


def is_semver(version: str) -> bool:
    return re.match(r"^v?\d+(\.\d+)*$", version.strip()) is not None


def fetch_hub_tags(repo: str, name_filter: str = "", pages: int = 2) -> list[str]:
    names: list[str] = []
    url = HUB.format(repo=repo, extra=f"&name={name_filter}" if name_filter else "")
    for _ in range(pages):
        try:
            with urllib.request.urlopen(url, timeout=20) as resp:
                data = json.load(resp)
        except (urllib.error.URLError, json.JSONDecodeError, TimeoutError) as exc:
            names.append(f"__error__:{exc}")
            break
        names.extend(t["name"] for t in data.get("results", []))
        url = data.get("next")
        if not url:
            break
    return names


# --------------------------------------------------------------------------- docker images
def check_docker() -> dict:
    result: dict = {"errors": []}

    app_text = APP_DOCKERFILE.read_text()
    wp_text = WP_DOCKERFILE.read_text()

    fp_app = re.search(r"dunglas/frankenphp:(\d+\.\d+\.\d+)-builder-php(\d+\.\d+\.\d+)", app_text)
    fp_wp = re.search(r"dunglas/frankenphp:(\d+\.\d+\.\d+)-php(\d+\.\d+\.\d+)", wp_text)
    node = re.search(r"FROM node:(\d+\.\d+\.\d+)", app_text)

    if not (fp_app and fp_wp and node):
        result["errors"].append("Could not parse FROM lines in the Dockerfiles; check them by hand.")
        return result

    current_fp, current_php = fp_app.group(1), fp_app.group(2)
    if (fp_wp.group(1), fp_wp.group(2)) != (current_fp, current_php):
        result["errors"].append(
            f"Dockerfiles disagree: app uses frankenphp {current_fp}/php {current_php}, "
            f"wordpress uses {fp_wp.group(1)}/php {fp_wp.group(2)}. Keep them in sync."
        )

    # FrankenPHP: newest release and, per PHP minor, the newest PHP patch it ships.
    tags = fetch_hub_tags("dunglas/frankenphp", name_filter="-builder-php8.", pages=3)
    errors = [t for t in tags if t.startswith("__error__")]
    if errors:
        result["errors"].append(f"Docker Hub (frankenphp): {errors[0][10:]}")
    combos = [
        (m.group(1), m.group(2))
        for t in tags
        if (m := re.fullmatch(r"(\d+\.\d+\.\d+)-builder-php(\d+\.\d+\.\d+)", t))
    ]
    latest_fp = max((c[0] for c in combos), key=ver_tuple, default=None)
    php_by_minor: dict[str, str] = {}
    for fp, php in combos:
        if fp != latest_fp:
            continue
        minor = ".".join(php.split(".")[:2])
        if minor not in php_by_minor or ver_tuple(php) > ver_tuple(php_by_minor[minor]):
            php_by_minor[minor] = php
    current_minor = ".".join(current_php.split(".")[:2])
    newest_minor = max(php_by_minor, key=ver_tuple, default=None)

    result["frankenphp"] = {
        "current": current_fp,
        "latest": latest_fp,
    }
    result["php"] = {
        "current": current_php,
        "same_minor_latest": php_by_minor.get(current_minor),
        "newest_minor_latest": php_by_minor.get(newest_minor) if newest_minor else None,
        "all_minors": php_by_minor,
    }
    if latest_fp:
        result["suggested_tags"] = {
            "same_php_minor": {
                "app": f"dunglas/frankenphp:{latest_fp}-builder-php{php_by_minor.get(current_minor, current_php)}",
                "wordpress": f"dunglas/frankenphp:{latest_fp}-php{php_by_minor.get(current_minor, current_php)}",
            },
            "newest_php_minor": {
                "app": f"dunglas/frankenphp:{latest_fp}-builder-php{php_by_minor.get(newest_minor)}",
                "wordpress": f"dunglas/frankenphp:{latest_fp}-php{php_by_minor.get(newest_minor)}",
            } if newest_minor else None,
        }

    # Node: newest patch of the current major, newest even (LTS-line) major, newest overall.
    node_tags = fetch_hub_tags("library/node", pages=2)
    errors = [t for t in node_tags if t.startswith("__error__")]
    if errors:
        result["errors"].append(f"Docker Hub (node): {errors[0][10:]}")
    versions = [t for t in node_tags if re.fullmatch(r"\d+\.\d+\.\d+", t)]
    current_node = node.group(1)
    current_major = int(current_node.split(".")[0])
    by_major: dict[int, str] = {}
    for v in versions:
        major = int(v.split(".")[0])
        if major not in by_major or ver_tuple(v) > ver_tuple(by_major[major]):
            by_major[major] = v
    even_majors = [m for m in by_major if m % 2 == 0]
    result["node"] = {
        "current": current_node,
        "same_major_latest": by_major.get(current_major),
        "newest_even_major_latest": by_major[max(even_majors)] if even_majors else None,
        "newest_overall": max(versions, key=ver_tuple, default=None),
    }
    return result


# --------------------------------------------------------------------------- composer
def composer_outdated(runner: str, manifest: Path) -> dict:
    code, out, err = run([f"./bin/{runner}", "composer", "outdated", "--direct", "--format=json"])
    if code != 0 or not out.strip():
        return {"error": (err or out).strip() or f"./bin/{runner} composer outdated failed (is the stack up? ./up.sh)"}

    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        return {"error": f"Unparseable composer output: {out[:300]}"}

    manifest_data = json.loads(manifest.read_text())
    constraints = {**manifest_data.get("require", {}), **manifest_data.get("require-dev", {})}

    packages = []
    for pkg in data.get("installed", []):
        name = pkg["name"]
        constraint = constraints.get(name)
        if constraint is None:
            continue
        installed, latest = pkg["version"], pkg.get("latest") or pkg["version"]
        semver = is_semver(installed) and is_semver(latest)
        packages.append(
            {
                "name": name,
                "constraint": constraint,
                "installed": installed,
                "latest": latest,
                "major": semver and ver_tuple(latest)[:1] != ver_tuple(installed)[:1],
                "abandoned": bool(pkg.get("abandoned")),
                "status": pkg.get("latest-status"),
                "non_semver": not semver,
                "new_constraint": composer_constraint(constraint, latest) if semver else constraint,
            }
        )
    return {"packages": packages}


def composer_constraint(current: str, latest: str) -> str:
    """Mirror the style used in this repo: caret constraints are '^major.minor', pins are exact."""
    v = ver_tuple(latest)
    if current.startswith("^"):
        return f"^{v[0]}.{v[1]}" if len(v) > 1 else f"^{v[0]}"
    if current.startswith("~"):
        return f"~{v[0]}.{v[1]}" if len(v) > 1 else f"~{v[0]}"
    if re.fullmatch(r"\d+(\.\d+)*", current):
        return latest.lstrip("v")
    return current  # ranges like '>=8.3' are left for a human


# --------------------------------------------------------------------------- npm
def npm_outdated() -> dict:
    code, out, err = run(["./bin/app", "npm", "outdated", "--json"])
    # npm exits 1 when anything is outdated; only an empty stdout means it really failed.
    if not out.strip():
        if code == 0:
            return {"packages": []}
        return {"error": err.strip() or "./bin/app npm outdated failed (is the stack up? ./up.sh)"}
    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        return {"error": f"Unparseable npm output: {out[:300]}"}

    manifest = json.loads(APP_PACKAGE.read_text())
    constraints = {**manifest.get("dependencies", {}), **manifest.get("devDependencies", {})}

    packages = []
    for name, info in data.items():
        constraint = constraints.get(name)
        if constraint is None:
            continue
        current, latest = info.get("current") or "?", info["latest"]
        packages.append(
            {
                "name": name,
                "constraint": constraint,
                "installed": current,
                "latest": latest,
                "major": current != "?" and ver_tuple(latest)[:1] != ver_tuple(current)[:1],
                "new_constraint": npm_constraint(constraint, latest),
            }
        )
    packages.sort(key=lambda p: p["name"])
    return {"packages": packages}


def npm_constraint(current: str, latest: str) -> str:
    if current[:1] in "^~":
        return f"{current[0]}{latest}"
    if re.fullmatch(r"\d+(\.\d+)*", current):
        return latest
    return current


# --------------------------------------------------------------------------- przelewy24 vendor zip
def p24_manifest_url() -> str:
    if P24_PLUGIN_MAIN.exists():
        m = re.search(r"WC_P24_UPDATE_URL',\s*'([^']+)'", P24_PLUGIN_MAIN.read_text(errors="replace"))
        if m:
            return m.group(1).split("?")[0]  # the plugin appends a cache-busting ?t=<timestamp>
    return P24_MANIFEST_FALLBACK


def p24_repository_entry(manifest_data: dict) -> dict | None:
    for repo in manifest_data.get("repositories", []):
        if repo.get("type") == "package" and repo.get("package", {}).get("name") == P24_PACKAGE:
            return repo["package"]
    return None


def check_p24() -> dict:
    manifest_data = json.loads(WP_COMPOSER.read_text())
    entry = p24_repository_entry(manifest_data)
    if entry is None:
        return {"error": f'No "package" repository for {P24_PACKAGE} in wordpress/composer.json'}
    current = entry["version"]
    pin = manifest_data.get("require", {}).get(P24_PACKAGE, current)

    url = p24_manifest_url()
    try:
        req = urllib.request.Request(url, headers={"Accept": "application/json", "Cache-Control": "no-cache"})
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = json.load(resp)
    except (urllib.error.URLError, json.JSONDecodeError, TimeoutError) as exc:
        return {"error": f"Przelewy24 manifest {url}: {exc}"}

    latest = str(data.get("version", "")).lstrip("v")
    if not latest or not data.get("package"):
        return {"error": f"Przelewy24 manifest {url} has no version/package fields: {str(data)[:200]}"}

    return {
        "name": P24_PACKAGE,
        "constraint": pin,
        "installed": current,
        "latest": latest,
        "major": ver_tuple(latest)[:1] != ver_tuple(current)[:1],
        "abandoned": False,
        "new_constraint": latest,
        "outdated": ver_tuple(latest) > ver_tuple(current),
        "manifest_url": url,
        "package_url": data["package"],
        "tested_woocommerce": data.get("tested"),
        "current_dist_url": entry.get("dist", {}).get("url"),
    }


def resolve_p24_dist(package_url: str, expected_version: str) -> dict:
    """Follow the vendor's redirect to the versioned ZIP, download it and verify it before trusting it.

    The ZIP file name changes between releases (woocommerce-10-..., woocommerce-11-...), so the
    final URL must come from the redirect rather than be guessed.
    """
    import hashlib
    import io
    import zipfile

    with urllib.request.urlopen(package_url, timeout=60) as resp:
        final_url = resp.geturl()
        payload = resp.read()

    if not final_url.lower().endswith(".zip"):
        raise RuntimeError(f"Vendor redirect did not end at a .zip file: {final_url}")

    archive = zipfile.ZipFile(io.BytesIO(payload))
    names = archive.namelist()
    roots = {n.split("/")[0] for n in names}
    if roots != {"woo-przelewy24"}:
        raise RuntimeError(f"Unexpected ZIP layout, top-level entries: {sorted(roots)}")
    header = archive.read("woo-przelewy24/woocommerce-p24-gateway.php").decode(errors="replace")
    m = re.search(r"Version:\s*(\S+)", header)
    if not m or m.group(1) != expected_version:
        raise RuntimeError(f"ZIP declares version {m.group(1) if m else '?'}, manifest says {expected_version}")

    return {"url": final_url, "shasum": hashlib.sha1(payload).hexdigest(), "size": len(payload)}


def apply_p24(info: dict, dist: dict) -> list[str]:
    """Rewrite the package entry (version, dist url, shasum) and the require pin by text substitution."""
    text = WP_COMPOSER.read_text()
    changed: list[str] = []

    start = text.index(f'"name": "{P24_PACKAGE}"')
    m = re.compile(r'("version"\s*:\s*")' + re.escape(info["installed"]) + '(")').search(text, start)
    if m:
        text = text[: m.start()] + m.group(1) + info["latest"] + m.group(2) + text[m.end() :]
        changed.append(f'package version: {info["installed"]} -> {info["latest"]}')

    if info.get("current_dist_url") and info["current_dist_url"] in text:
        text = text.replace(info["current_dist_url"], dist["url"], 1)
        changed.append(f'dist url -> {dist["url"]}')

    text, n = re.subn(r'("shasum"\s*:\s*")[0-9a-f]*(")', rf'\g<1>{dist["shasum"]}\g<2>', text, count=1)
    if n:
        changed.append(f'dist shasum -> {dist["shasum"]}')

    text, n = re.subn(
        rf'("{re.escape(P24_PACKAGE)}"\s*:\s*"){re.escape(info["constraint"])}(")',
        rf'\g<1>{info["latest"]}\g<2>',
        text,
        count=1,
    )
    if n:
        changed.append(f'{P24_PACKAGE}: {info["constraint"]} -> {info["latest"]}')

    WP_COMPOSER.write_text(text)
    return changed


# --------------------------------------------------------------------------- apply
def apply_bumps(manifest: Path, packages: list[dict], holds: set[str]) -> list[str]:
    """Rewrite '"name": "old"' lines in place so the file keeps its indentation and key order."""
    text = manifest.read_text()
    changed: list[str] = []
    for pkg in packages:
        if pkg["name"] in holds and pkg["major"]:
            continue
        if pkg["new_constraint"] == pkg["constraint"]:
            continue
        pattern = re.compile(rf'("{re.escape(pkg["name"])}"\s*:\s*"){re.escape(pkg["constraint"])}(")')
        text, count = pattern.subn(rf"\g<1>{pkg['new_constraint']}\g<2>", text, count=1)
        if count:
            changed.append(f'{pkg["name"]}: {pkg["constraint"]} -> {pkg["new_constraint"]}')
    manifest.write_text(text)
    return changed


# --------------------------------------------------------------------------- report
def md_table(packages: list[dict], holds: set[str]) -> str:
    if not packages:
        return "_Everything is up to date._\n"
    lines = ["| Package | Constraint | Installed | Latest | New constraint | Notes |", "|---|---|---|---|---|---|"]
    for p in packages:
        notes = []
        if p["major"]:
            notes.append("**MAJOR**")
        if p["name"] in holds and p["major"]:
            notes.append("held (not applied)")
        if p.get("abandoned"):
            notes.append("ABANDONED")
        if p.get("non_semver"):
            notes.append("dev branch — constraint left alone, update via `composer update <name>`")
        lines.append(
            f"| {p['name']} | {p['constraint']} | {p['installed']} | {p['latest']} | {p['new_constraint']} | {', '.join(notes)} |"
        )
    return "\n".join(lines) + "\n"


def render_markdown(report: dict, holds: set[str]) -> str:
    out = ["# Outdated dependencies report\n"]

    d = report["docker"]
    out.append("## Docker base images\n")
    for e in d.get("errors", []):
        out.append(f"- ⚠ {e}")
    if "frankenphp" in d:
        fp, php, nd = d["frankenphp"], d["php"], d["node"]
        out.append(f"- FrankenPHP: `{fp['current']}` -> latest `{fp['latest']}`")
        out.append(
            f"- PHP: `{php['current']}` -> same minor `{php['same_minor_latest']}`, "
            f"newest minor `{php['newest_minor_latest']}`"
        )
        out.append(
            f"- Node: `{nd['current']}` -> same major `{nd['same_major_latest']}`, "
            f"newest even (LTS line) major `{nd['newest_even_major_latest']}`, newest overall `{nd['newest_overall']}`"
        )
        tags = d.get("suggested_tags", {})
        out.append("\nSuggested `FROM` tags:\n")
        out.append("```")
        out.append(f"# same PHP minor")
        out.append(f"docker/app/Dockerfile:        FROM {tags['same_php_minor']['app']}")
        out.append(f"docker/wordpress/Dockerfile:  FROM {tags['same_php_minor']['wordpress']}")
        if tags.get("newest_php_minor"):
            out.append(f"# newest PHP minor")
            out.append(f"docker/app/Dockerfile:        FROM {tags['newest_php_minor']['app']}")
            out.append(f"docker/wordpress/Dockerfile:  FROM {tags['newest_php_minor']['wordpress']}")
        out.append(f"docker/app/Dockerfile:        FROM node:{nd['newest_even_major_latest']} AS node")
        out.append("```")
    out.append("")

    for title, key in (
        ("Composer — app/composer.json", "app_composer"),
        ("Composer — wordpress/composer.json", "wordpress_composer"),
        ("npm — app/package.json", "app_npm"),
    ):
        out.append(f"## {title}\n")
        section = report[key]
        if "error" in section:
            out.append(f"⚠ {section['error']}\n")
        else:
            out.append(md_table(section["packages"], holds))

    out.append("## Przelewy24 plugin — vendor ZIP (package repository)\n")
    p24 = report["p24"]
    if "error" in p24:
        out.append(f"⚠ {p24['error']}\n")
    elif p24["outdated"]:
        out.append(md_table([p24], holds))
        out.append(
            f"Manifest: {p24['manifest_url']} (tested up to WooCommerce {p24['tested_woocommerce']}). "
            f"`--apply` follows {p24['package_url']} to the versioned ZIP and records its sha1.\n"
        )
    else:
        out.append(f"_Up to date ({p24['installed']}); manifest {p24['manifest_url']}._\n")

    if report.get("applied") is not None:
        out.append("## Applied changes\n")
        for manifest, changes in report["applied"].items():
            out.append(f"**{manifest}**")
            out.extend(f"- {c}" for c in changes) if changes else out.append("- (nothing)")
            out.append("")
    return "\n".join(out)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--json", action="store_true", help="print JSON instead of Markdown")
    parser.add_argument("--apply", action="store_true", help="rewrite constraints in composer.json / package.json")
    parser.add_argument("--hold", action="append", default=[], help="package to keep on its current major (repeatable)")
    parser.add_argument("--skip-docker", action="store_true", help="do not query Docker Hub")
    args = parser.parse_args()
    holds = DEFAULT_HOLDS | set(args.hold)

    report = {
        "docker": {} if args.skip_docker else check_docker(),
        "app_composer": composer_outdated("app", APP_COMPOSER),
        "wordpress_composer": composer_outdated("wordpress", WP_COMPOSER),
        "app_npm": npm_outdated(),
        "p24": check_p24(),
        "holds": sorted(holds),
        "applied": None,
    }

    if args.apply:
        report["applied"] = {}
        for key, manifest in (
            ("app_composer", APP_COMPOSER),
            ("wordpress_composer", WP_COMPOSER),
            ("app_npm", APP_PACKAGE),
        ):
            if "packages" in report[key]:
                report["applied"][str(manifest.relative_to(ROOT))] = apply_bumps(manifest, report[key]["packages"], holds)

        p24 = report["p24"]
        if "error" not in p24 and p24["outdated"]:
            wp_key = str(WP_COMPOSER.relative_to(ROOT))
            try:
                dist = resolve_p24_dist(p24["package_url"], p24["latest"])
                report["applied"].setdefault(wp_key, []).extend(apply_p24(p24, dist))
            except Exception as exc:  # network or layout problems: report, never half-apply
                report["applied"].setdefault(wp_key, []).append(f"{P24_PACKAGE}: NOT applied — {exc}")

    if args.json:
        print(json.dumps(report, indent=2))
    else:
        print(render_markdown(report, holds))

    failed = [k for k in ("app_composer", "wordpress_composer", "app_npm", "p24") if "error" in report[k]]
    return 2 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

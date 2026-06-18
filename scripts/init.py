#!/usr/bin/env python3
"""Initialise a plugin.

Usage:
    python3 scripts/init.py <slug> <Namespace> ["Plugin Name"] ["Short description"]

Replaces the placeholder tokens across every tracked file and renames the main
file to <slug>.php. Cross-platform (no sed/grep portability traps).
"""
import os
import sys
import subprocess

def tracked_files():
    try:
        out = subprocess.check_output(["git", "ls-files", "-z"])
        return [f for f in out.decode().split("\0") if f]
    except Exception:
        files = []
        for root, dirs, names in os.walk("."):
            dirs[:] = [d for d in dirs if d not in (".git", "vendor", "node_modules")]
            files += [os.path.join(root, n) for n in names]
        return files

def main():
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)
    slug = sys.argv[1]
    ns = sys.argv[2]
    name = sys.argv[3] if len(sys.argv) > 3 else ns
    short = sys.argv[4] if len(sys.argv) > 4 else name
    desc = sys.argv[5] if len(sys.argv) > 5 else short

    repl = [
        ("migrator_", slug.replace("-", "_") + "_"),
        ("migrator", slug),
        ("MIGRATOR", ns.upper()),
        ("Migrator", ns),
        ("Migrator", name),
        ("Back up your WordPress site and move it to a new host. One file, drag and drop, no technical setup.", short),
        ("Back up your WordPress site and move it to a new host. One file, drag and drop, no technical setup.", desc),
        ("Migrator in action.", name + " in action."),
    ]

    for path in tracked_files():
        if path.startswith("scripts/init.py"):
            continue
        try:
            with open(path, encoding="utf-8") as fh:
                s = original = fh.read()
        except (UnicodeDecodeError, FileNotFoundError, IsADirectoryError):
            continue
        for a, b in repl:
            s = s.replace(a, b)
        if s != original:
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(s)

    if os.path.exists("migrator.php"):
        os.rename("migrator.php", f"{slug}.php")

    print(f"Scaffolded '{slug}' (namespace {ns}). Next: composer install, implement src/, review the diff.")
    print("You can now delete scripts/init.py.")

if __name__ == "__main__":
    main()

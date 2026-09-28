#!/usr/bin/env python3
"""Fitness function for the documentation: every relative link points somewhere.

It reads every Markdown file tracked by Git and fails when:
  1. a link or an image points to a file or folder that does not exist;
  2. a link names an anchor that no heading of the target file produces.

External links (http, https, mailto) are left alone: they break for reasons the repository
cannot fix, and a CI that fails because a site is down teaches people to ignore it. Anchors
follow GitHub's rule: lowercase, punctuation out, spaces become hyphens, repeats get -1, -2.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parent.parent

FENCE = re.compile(r"^\s*(```|~~~)")
INLINE_CODE = re.compile(r"`[^`\n]*`")
MARKDOWN_LINK = re.compile(r"!?\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+\"[^\"]*\")?\s*\)")
HTML_LINK = re.compile(r"""(?:src|href)\s*=\s*["']([^"']+)["']""")
HEADING = re.compile(r"^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$")
EXTERNAL = ("http://", "https://", "mailto:", "tel:")


def main() -> int:
    files = markdown_files()
    anchors: dict[Path, set[str]] = {}
    problems = []
    for file in files:
        for line_number, target in links_in(file):
            problem = check(file, target, anchors)
            if problem is not None:
                problems.append(f"{file.relative_to(ROOT)}:{line_number}: {target} ({problem})")

    for problem in problems:
        print(problem)
    if problems:
        print(f"\n{len(problems)} broken links in {len(files)} Markdown files.")
        return 1
    print(f"Every relative link in {len(files)} Markdown files points somewhere.")
    return 0


def markdown_files() -> list[Path]:
    listed = subprocess.run(
        ["git", "ls-files", "*.md"], cwd=ROOT, capture_output=True, text=True, check=True
    ).stdout.split()
    return sorted(ROOT / name for name in listed)


def links_in(file: Path) -> list[tuple[int, str]]:
    """Every link target outside code: fenced blocks and inline code are examples, not links."""
    found = []
    in_fence = False
    for number, line in enumerate(file.read_text(encoding="utf-8").splitlines(), start=1):
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        prose = INLINE_CODE.sub("", line)
        for pattern in (MARKDOWN_LINK, HTML_LINK):
            found.extend((number, match.group(1)) for match in pattern.finditer(prose))
    return found


def check(file: Path, target: str, anchors: dict[Path, set[str]]) -> str | None:
    if target.startswith(EXTERNAL):
        return None
    path_part, _, anchor = target.partition("#")
    destination = file if path_part == "" else (file.parent / unquote(path_part)).resolve()
    if not destination.exists():
        return "no such file"
    if anchor == "" or destination.is_dir() or destination.suffix != ".md":
        return None
    if destination not in anchors:
        anchors[destination] = anchors_of(destination)
    if unquote(anchor).lower() not in anchors[destination]:
        return "no such heading"
    return None


def anchors_of(file: Path) -> set[str]:
    produced: set[str] = set()
    seen: dict[str, int] = {}
    in_fence = False
    for line in file.read_text(encoding="utf-8").splitlines():
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        heading = None if in_fence else HEADING.match(line)
        if heading is None:
            continue
        slug = slug_of(heading.group(2))
        repeats = seen.get(slug, 0)
        seen[slug] = repeats + 1
        produced.add(slug if repeats == 0 else f"{slug}-{repeats}")
    return produced


def slug_of(heading: str) -> str:
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", heading)  # a link keeps only its text
    text = re.sub(r"<[^>]+>", "", text)
    text = re.sub(r"[^\w\- ]", "", text.lower())
    return text.replace(" ", "-")


if __name__ == "__main__":
    sys.exit(main())

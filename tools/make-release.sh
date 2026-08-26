#!/usr/bin/env bash
# Build the release artifact the PKP plugin gallery expects: a .tar.gz whose
# single top-level directory is the product name (colophon/), holding only
# the installable plugin — no git metadata, no tooling, no gallery paperwork.
# A published package is immutable (the gallery pins its md5), so every fix
# is a new version: bump version.xml, tag, rebuild.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's/.*<release>\(.*\)<\/release>.*/\1/p' "$repo_root/version.xml")"
[ -n "$version" ] || { echo "version.xml has no <release>"; exit 1; }

out="colophon-$version.tar.gz"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir "$stage/colophon"
(cd "$repo_root" && tar -cf - \
    --exclude='./.git' \
    --exclude='./.github' \
    --exclude='./tools' \
    --exclude='./docs' \
    --exclude='./.gitignore' \
    .) | tar -xf - -C "$stage/colophon"

tar -czf "$out" -C "$stage" colophon
echo "built  $out"
echo "md5    $(md5 -q "$out" 2>/dev/null || md5sum "$out" | cut -d' ' -f1)"
echo "sha256 $(shasum -a 256 "$out" | cut -d' ' -f1)"

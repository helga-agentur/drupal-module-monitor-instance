#!/bin/sh
# instance-git-info: used as post-checkout, post-merge, post-rewrite and
# post-commit hook. Writes the git data read by the instance module.

# post-checkout also fires for single file checkouts, ignore those
[ "$3" = "0" ] && exit 0

out="$(git rev-parse --absolute-git-dir)/instance-git-info.json"

esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

cat > "$out.tmp" <<JSON && mv "$out.tmp" "$out"
{
  "head": "$(esc "$(git status | head -n 1)")",
  "tag": "$(esc "$(git describe --tags --abbrev=0 2>/dev/null)")",
  "commit": "$(esc "$(git rev-parse --short HEAD)")",
  "commitDate": "$(esc "$(git show -s --format=%cd --date=short HEAD)")",
  "remoteUrl": "$(esc "$(git config --get remote.origin.url)")",
  "generatedAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

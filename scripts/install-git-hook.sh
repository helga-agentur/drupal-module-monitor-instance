#!/bin/sh
# Installs scripts/git-info.sh as git hooks of the current repository
# and generates the file once. Run from the project root.

src="$(cd "$(dirname "$0")" && pwd)/git-info.sh"
hooks="$(git rev-parse --git-common-dir)/hooks"

for hook in post-checkout post-merge post-rewrite post-commit; do
  target="$hooks/$hook"
  if [ -e "$target" ] && ! grep -q 'instance-git-info' "$target"; then
    echo "Skipping $hook: a different hook exists." >&2
    continue
  fi
  cp "$src" "$target" && chmod +x "$target"
done

"$src"

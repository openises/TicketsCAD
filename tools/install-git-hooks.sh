#!/usr/bin/env bash
#
# tools/install-git-hooks.sh — point git at the repo's versioned hooks.
#
# Run once per clone:  bash tools/install-git-hooks.sh
#
# Uses core.hooksPath (git >= 2.9) so the hooks stay in version control —
# editing tools/git-hooks/* updates the hook for everyone on next pull,
# no re-install needed.

set -e
cd "$(git rev-parse --show-toplevel)"
chmod +x tools/git-hooks/* 2>/dev/null || true
git config core.hooksPath tools/git-hooks
echo "Git hooks installed: core.hooksPath -> tools/git-hooks"
echo "Active hooks: $(ls tools/git-hooks | tr '\n' ' ')"

#!/usr/bin/env bash
# PostToolUse hook: formats a PHP file with Pint right after Claude edits it.
# Reads the hook payload (JSON) on stdin and runs Pint through Sail on that
# file only. Skips silently when the file is not PHP, is outside the project,
# or the Sail containers are not running, so it never blocks an edit.

set -u

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"

file_path="$(python3 -c 'import json, sys
data = json.load(sys.stdin)
print(data.get("tool_input", {}).get("file_path", ""))')"

case "$file_path" in
    "$project_dir"/*.php) ;;
    *) exit 0 ;;
esac

[ -f "$file_path" ] || exit 0

cd "$project_dir" || exit 0

if [ -z "$(vendor/bin/sail ps --status running --services 2>/dev/null | grep -x laravel.test)" ]; then
    exit 0
fi

vendor/bin/sail bin pint --format agent "${file_path#"$project_dir"/}" >/dev/null 2>&1 || true

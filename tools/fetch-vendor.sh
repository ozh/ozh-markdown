#!/usr/bin/env bash
#
# Regenerate the vendored front end libraries.
#
# Runs on the host, where Node lives, not inside the dev container. This is a
# maintenance tool: the plugin ships the generated files and never needs Node
# to run. Re-run it to take a new upstream version, then commit the result.
#
# Usage: ./tools/fetch-vendor.sh

set -euo pipefail

MARKED_VERSION=18.0.10
CODEMIRROR_VERSION=5.65.21
PRISM_VERSION=1.30.0

# The language set the spec names. Dependencies are resolved automatically
# below, which matters: prism-php requires markup-templating, and a bundle
# missing it throws on the first highlight and silently disables highlighting
# for every language.
PRISM_LANGUAGES="markup css clike javascript php bash sql json yaml diff markdown ini"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

echo "Fetching into $WORK_DIR"
cd "$WORK_DIR"
npm init -y >/dev/null 2>&1
npm install --silent --no-audit --no-fund \
	"marked@${MARKED_VERSION}" \
	"codemirror@${CODEMIRROR_VERSION}" \
	"prismjs@${PRISM_VERSION}"

MODULES="$WORK_DIR/node_modules"
JS_DIR="$PLUGIN_DIR/assets/js/vendor"
CSS_DIR="$PLUGIN_DIR/assets/css/vendor"

mkdir -p "$JS_DIR" "$CSS_DIR"

echo "Copying marked and CodeMirror"
cp "$MODULES/marked/lib/marked.umd.js" "$JS_DIR/marked.min.js"
cp "$MODULES/codemirror/lib/codemirror.js" "$JS_DIR/codemirror.js"
cp "$MODULES/codemirror/lib/codemirror.css" "$CSS_DIR/codemirror.css"
cp "$MODULES/codemirror/mode/markdown/markdown.js" "$JS_DIR/codemirror-markdown.js"

echo "Resolving Prism language dependencies"
RESOLVED="$(node -e '
const components = require(process.argv[1] + "/prismjs/components.json");
const requested = process.argv[2].split(" ").filter(Boolean);
const meta = components.languages;
const resolved = new Set();

function add(language) {
	if (resolved.has(language)) {
		return;
	}

	const entry = meta[language];

	if (!entry) {
		throw new Error("unknown Prism language: " + language);
	}

	for (const dependency of [].concat(entry.require || [])) {
		add(dependency);
	}

	resolved.add(language);
}

requested.forEach(add);
process.stdout.write([...resolved].join(" "));
' "$MODULES" "$PRISM_LANGUAGES")"

echo "  language order: $RESOLVED"

{
	cat "$MODULES/prismjs/components/prism-core.min.js"
	for language in $RESOLVED; do
		echo ";"
		cat "$MODULES/prismjs/components/prism-${language}.min.js"
	done
} > "$JS_DIR/prism.js"

echo "Building the Prism stylesheet"
{
	echo "/* Prism light theme (prism.min.css), applied unconditionally so that a"
	echo "   browser reporting no colour preference still gets highlighting. */"
	cat "$MODULES/prismjs/themes/prism.min.css"
	echo
	echo "/* Prism dark theme (prism-tomorrow.min.css), overriding the light one."
	echo "   Selectors match the light theme's specificity, so source order wins."
	echo "   The theme's own @media print block is stripped, because nesting one"
	echo "   at-rule inside another is not reliably supported. */"
	echo "@media (prefers-color-scheme: dark) {"
	sed 's/@media print{[^}]*}//g' "$MODULES/prismjs/themes/prism-tomorrow.min.css"
	echo "}"
} > "$CSS_DIR/prism.css"

echo
echo "Done. Verify before committing:"
echo "  node --test tests/js/*.test.js"

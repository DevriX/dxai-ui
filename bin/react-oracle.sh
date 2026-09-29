#!/usr/bin/env bash
#
# Build and serve a Lovable design's REAL React app, as an independent oracle.
#
# Why this exists
# ---------------
# bin/design-oracle.php builds the "compiled oracle" by calling this plugin's own
# Jsx_Compiler. That makes it a sound fixed point for CSS and for the block
# conversion, but it cannot see a JSX-to-HTML loss: the loss lands on BOTH sides
# and the diff reports 0px while the page is wrong. Measured on ARA, the compiled
# oracle said 841/841 nodes exact, 0 lost, 0 extra — against the real React build
# the same page had 28 design nodes unmatched and 237 of its own unmatched.
#
# So the design side has to owe us nothing. This script installs the project's
# real dependencies and runs its real build: React, Radix, TanStack Router,
# Tailwind and Lovable's own published vite config, with no plugin code anywhere
# in it. TanStack Start renders on the server, so the served HTML is the
# design's actual DOM rather than an empty shell.
#
# What it changes about the build
# -------------------------------
# One thing, and only to make the comparison possible: asset filenames are
# emitted unhashed. design-oracle.cjs identifies an image by basename with
# WordPress's `-<digits>` dedup suffix stripped, and a Vite content hash is not
# digits, so every image would read as lost on one side and spurious on the
# other. Entry and chunk names are left alone — renaming those moved the SSR
# entry and TanStack's preview plugin died with ERR_MODULE_NOT_FOUND.
#
# usage:
#   bin/react-oracle.sh <zip-or-project-dir> [--port 5199] [--slug name] [--rebuild]
#   bin/react-oracle.sh --stop [--port 5199]
#
# then:
#   node bin/design-oracle.cjs --design-url http://127.0.0.1:5199/ \
#     --url <live-wp-url> --width 1280 --depth 20 --styles
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK_ROOT="$REPO/.verify/react-oracle"
PORT=5199
SLUG=""
SRC=""
REBUILD=0
STOP=0

while [ $# -gt 0 ]; do
	case "$1" in
		--port) PORT="$2"; shift 2 ;;
		--slug) SLUG="$2"; shift 2 ;;
		--rebuild) REBUILD=1; shift ;;
		--stop) STOP=1; shift ;;
		-h|--help) sed -n '2,40p' "${BASH_SOURCE[0]}"; exit 0 ;;
		*) SRC="$1"; shift ;;
	esac
done

# Kill whatever holds the port. Done by port rather than by pid file: a previous
# run that was interrupted leaves the server up with no file to find it by.
#
# `|| true` on the pipeline is load-bearing. Under `set -euo pipefail` grep
# exits 1 when the port is FREE, which is the normal case, and that aborted the
# whole script one line before it started the server — the build succeeded, no
# preview.log was ever created, and the only symptom was silence.
#
release_port() {
	local port="$1"
	local pids
	pids="$(netstat -ano 2>/dev/null | grep -E "TCP .*:${port}[[:space:]].*LISTENING" | awk '{print $5}' | sort -u || true)"
	[ -z "$pids" ] && return 0
	for pid in $pids; do
		taskkill //F //PID "$pid" >/dev/null 2>&1 || true
	done
	return 0
}

if [ "$STOP" = "1" ]; then
	release_port "$PORT"
	echo "react-oracle: released port $PORT"
	exit 0
fi

if [ -z "$SRC" ]; then
	echo "react-oracle: need a ZIP or an extracted project directory" >&2
	exit 2
fi

# ---------------------------------------------------------------- sources ---

if [ -z "$SLUG" ]; then
	SLUG="$(basename "$SRC" | sed -e 's/\.zip$//' -e 's/[^A-Za-z0-9]\+/-/g' -e 's/^-//' -e 's/-$//' | tr '[:upper:]' '[:lower:]')"
fi
WORK="$WORK_ROOT/$SLUG"
mkdir -p "$WORK"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if [ -d "$SRC" ]; then
	# An extracted project: it must be the directory holding package.json.
	if [ ! -f "$SRC/package.json" ]; then
		echo "react-oracle: no package.json in $SRC" >&2
		exit 2
	fi
	cp -r "$SRC"/. "$STAGE"/
elif [ -f "$SRC" ]; then
	command -v unzip >/dev/null 2>&1 || { echo "react-oracle: unzip not found" >&2; exit 2; }
	unzip -q "$SRC" -d "$STAGE"
	# Lovable ZIPs nest the project one level down often enough to handle here.
	if [ ! -f "$STAGE/package.json" ]; then
		found="$(find "$STAGE" -maxdepth 3 -name package.json -not -path '*/node_modules/*' | head -1)"
		[ -z "$found" ] && { echo "react-oracle: no package.json inside $SRC" >&2; exit 2; }
		inner="$(dirname "$found")"
		tmp2="$(mktemp -d)"
		cp -r "$inner"/. "$tmp2"/
		rm -rf "$STAGE"
		mv "$tmp2" "$STAGE"
	fi
else
	echo "react-oracle: $SRC is neither a file nor a directory" >&2
	exit 2
fi

# Sync sources into the work dir WITHOUT touching node_modules or dist — an
# install of this project takes minutes, so it is cached across runs.
for entry in "$STAGE"/*; do
	name="$(basename "$entry")"
	case "$name" in
		node_modules|dist) continue ;;
	esac
	rm -rf "$WORK/$name"
	cp -r "$entry" "$WORK/$name"
done
for hidden in "$STAGE"/.[!.]*; do
	[ -e "$hidden" ] || continue
	cp -r "$hidden" "$WORK/" 2>/dev/null || true
done

# ------------------------------------------------------------ vite config ---

# Which shape this project is. A TanStack Start project builds through Lovable's
# own published config and renders on the server; the classic
# `vite_react_shadcn_ts` template is a plain Vite SPA with an index.html, its own
# vite.config.ts and no server entry at all. Overwriting the second one's config
# with the first one's — which is what this script did unconditionally — asked
# npm for a package it does not depend on and failed the build before it started.
SHAPE="tanstack"
if [ ! -d "$WORK/src/routes" ] && [ -f "$WORK/index.html" ]; then
	SHAPE="spa"
fi
if ! grep -q "vite-tanstack-config" "$WORK/package.json" 2>/dev/null; then
	SHAPE="spa"
fi
echo "react-oracle: $SLUG looks like a $SHAPE project"

if [ "$SHAPE" = "spa" ]; then
	# The project's own config, plus the one override this oracle needs. Written
	# as a wrapper rather than a replacement: a classic Lovable config carries
	# the `@` alias and the SWC React plugin, and a design that imports
	# `@/components/...` — all of them do — does not build without them.
	cat > "$WORK/vite.oracle.config.ts" <<'SPAVITE'
// Written by bin/react-oracle.sh. The project's real config with asset
// filenames unhashed, so design-oracle.cjs can match an image by basename on
// both sides; its matcher strips WordPress's `-<digits>` dedup suffix and a
// Vite content hash is not digits.
import { defineConfig, mergeConfig, type UserConfig } from "vite";
import base from "./vite.config";

export default defineConfig(async (env) => {
  const resolved = typeof base === "function" ? await base(env) : base;

  return mergeConfig(resolved as UserConfig, {
    build: {
      // Vite inlines an asset under 4KB as a data: URI, and a data URI has no
      // filename — which is the only handle design-oracle.cjs has on a picture.
      // A small logo then read as a lost node on one side and a new one on the
      // other. Emitting it as a file changes nothing about how it renders.
      assetsInlineLimit: 0,
      rollupOptions: {
        output: {
          assetFileNames: "assets/[name][extname]",
        },
      },
    },
  });
});
SPAVITE
else

cat > "$WORK/vite.config.ts" <<'VITE'
// Written by bin/react-oracle.sh — the project's own published config, with two
// deliberate overrides. Everything else (React, Radix, TanStack, Tailwind, the
// plugin set) is exactly what the design was built with, which is the whole
// reason this oracle is independent of our PHP compiler.
//
// 1. assetFileNames: unhashed, so design-oracle.cjs can match an image on both
//    sides by basename. Its matcher strips WordPress's `-<digits>` dedup
//    suffix; a Vite content hash is not digits, so every image would read as
//    lost on one side and spurious on the other. Entry and chunk names are
//    deliberately NOT renamed — that moves dist/server/server.js, which
//    TanStack's preview plugin loads by path.
//
// 2. nitro: false, for determinism across designs. Lovable's config enables its
//    nitro deploy plugin when it "detects Lovable context", and that detection
//    differs between an extracted project directory and the original ZIP: ARA
//    built to dist/server/server.js while GTM — same framework, same version —
//    built to .output/ with its own wrangler config, and `vite preview` then
//    died with ERR_MODULE_NOT_FOUND because it only knows the first layout. An
//    oracle has to build the same way every time or the instrument varies with
//    its input. Deploy packaging is not part of what is being measured.
import { defineConfig } from "@lovable.dev/vite-tanstack-config";

export default defineConfig({
  nitro: false,
  vite: {
    build: {
      rollupOptions: {
        output: {
          assetFileNames: "assets/[name][extname]",
        },
      },
    },
  },
});
VITE

fi

# ---------------------------------------------------------------- install ---

cd "$WORK"

LOCK_SIG=""
for lock in package-lock.json npm-shrinkwrap.json package.json; do
	if [ -f "$lock" ]; then
		LOCK_SIG="$(md5sum "$lock" | cut -d' ' -f1)"
		break
	fi
done
STAMP="node_modules/.dxai-react-oracle"

if [ "$REBUILD" = "1" ] || [ ! -d node_modules ] || [ ! -f "$STAMP" ] || [ "$(cat "$STAMP" 2>/dev/null)" != "$LOCK_SIG" ]; then
	echo "react-oracle: installing dependencies for $SLUG (this takes a few minutes)"
	npm install --no-audit --no-fund
	printf '%s' "$LOCK_SIG" > "$STAMP"
else
	echo "react-oracle: dependencies cached for $SLUG"
fi

# ------------------------------------------------------------------ build ---

echo "react-oracle: building $SLUG"
rm -rf dist
if [ "$SHAPE" = "spa" ]; then
	npx vite build --config vite.oracle.config.ts
else
	npm run build
fi

# Deliberately NOT a check on the output layout. Two ZIPs of the same framework
# already produced two shapes: one built to dist/server/server.js after logging
# "No Lovable context detected — skipping nitro deploy plugin", the other
# enabled nitro and emitted .output/ with its own wrangler config. Asserting a
# path rejected a perfectly good build. What matters is whether the served HTML
# is the design's real DOM, and the probe below answers that directly.
if [ -d dist ] || [ -d .output ]; then
	echo "react-oracle: built ($( [ -d .output ] && echo '.output — nitro' || echo 'dist' ))"
else
	echo "react-oracle: build produced neither dist/ nor .output/" >&2
	exit 1
fi

# ------------------------------------------------------------------ serve ---

release_port "$PORT"
LOG="$WORK/preview.log"
: > "$LOG"
# Detached: the caller measures against it and stops it with --stop.
( npm run preview -- --port "$PORT" --strictPort >"$LOG" 2>&1 & ) >/dev/null 2>&1

for _ in $(seq 1 40); do
	code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/" || true)"
	if [ "$code" = "200" ]; then
		body="$(curl -s "http://127.0.0.1:$PORT/")"
		bytes="$(printf '%s' "$body" | wc -c)"
		# Serving 200 is not the same as serving the design, and measuring an
		# empty page as a design is the one failure that must never pass
		# silently. Where the answer lives depends on the shape: a TanStack
		# project renders on the server, so the response body IS the DOM; an SPA
		# ships a shell on purpose and only has a DOM once React has run, so it
		# is asked in a browser instead.
		if [ "$SHAPE" = "spa" ]; then
			if ! ready="$(node "$REPO/bin/react-oracle-ready.cjs" "http://127.0.0.1:$PORT/" 2>&1)"; then
				echo "react-oracle: served 200 but the page rendered nothing in a browser:" >&2
				echo "   $ready" >&2
				echo "   Measuring it would report the design as empty. Refusing rather than" >&2
				echo "   producing a false 0px." >&2
				exit 1
			fi
			elements="$(printf '%s' "$ready" | sed -n 's/.*elements=\([0-9]*\).*/\1/p')"
		else
			elements="$(printf '%s' "$body" | grep -oE '<(section|header|footer|main|article)\b' | wc -l)"
			if [ "$elements" -lt 2 ]; then
				echo "react-oracle: served 200 but only $elements layout element(s) — this looks like" >&2
				echo "   a client-rendered shell, not SSR output. Measuring it would report the" >&2
				echo "   design as empty. Refusing rather than producing a false 0px." >&2
				exit 1
			fi
		fi
		echo "react-oracle: serving $SLUG at http://127.0.0.1:$PORT/  (${bytes} bytes, ${elements} layout elements)"
		echo
		echo "  node bin/design-oracle.cjs --design-url http://127.0.0.1:$PORT/ \\"
		echo "    --url <live-wp-url> --width 1280 --depth 20 --styles"
		echo
		echo "  bin/react-oracle.sh --stop --port $PORT   # when done"
		exit 0
	fi
	# A 500 means the server is up but the handler failed — show why rather
	# than time out silently.
	if [ "$code" = "500" ]; then
		echo "react-oracle: preview returned 500" >&2
		curl -s "http://127.0.0.1:$PORT/" | sed -e 's/<[^>]*>//g' | grep -v '^[[:space:]]*$' | head -12 >&2
		exit 1
	fi
	sleep 1
done

echo "react-oracle: preview did not come up on port $PORT" >&2
tail -20 "$LOG" >&2
exit 1

#!/bin/sh
# Install the Brainitive CLI (macOS / Linux):
#
#   curl -fsSL https://github.com/armendzekjiri/brainitive/releases/latest/download/install.sh | sh
#   (short: curl -fsSL https://brainitive.app/install.sh | sh — forwards here)
#
# Downloads the latest release binary for this machine from GitHub, checks it against
# checksums.txt and puts it in /usr/local/bin (or ~/.local/bin when that isn't writable).
# Environment: BRAINITIVE_VERSION=1.47.0 pins a version; BRAINITIVE_INSTALL_DIR overrides the
# directory. Nothing else is touched. Source: cli/ in https://github.com/armendzekjiri/brainitive
set -eu

# Everything runs from main() at the very end, so a download that stops halfway runs nothing.
main() {
REPO="armendzekjiri/brainitive"
NAME="brainitive"

say() { printf '%s\n' "$*" >&2; }
fail() { say "install: $*"; exit 1; }

need() { command -v "$1" >/dev/null 2>&1 || fail "$1 is required"; }
need uname
need tar
if command -v curl >/dev/null 2>&1; then
  fetch() { curl -fsSL "$1" -o "$2"; }
elif command -v wget >/dev/null 2>&1; then
  fetch() { wget -qO "$2" "$1"; }
else
  fail "curl or wget is required"
fi

os=$(uname -s | tr '[:upper:]' '[:lower:]')
case "$os" in
  darwin|linux) ;;
  *) fail "unsupported OS: $os (Windows: irm https://github.com/armendzekjiri/brainitive/releases/latest/download/install.ps1 | iex)" ;;
esac
arch=$(uname -m)
case "$arch" in
  x86_64|amd64) arch=amd64 ;;
  arm64|aarch64) arch=arm64 ;;
  *) fail "unsupported architecture: $arch" ;;
esac

asset="${NAME}_${os}_${arch}.tar.gz"
if [ -n "${BRAINITIVE_VERSION:-}" ]; then
  base="https://github.com/$REPO/releases/download/v${BRAINITIVE_VERSION#v}"
else
  base="https://github.com/$REPO/releases/latest/download"
fi

tmp=$(mktemp -d 2>/dev/null || mktemp -d -t brainitive)
trap 'rm -rf "$tmp"' EXIT

say "Downloading $asset …"
fetch "$base/$asset" "$tmp/$asset" || fail "no release asset $asset at $base (is the release published?)"
fetch "$base/checksums.txt" "$tmp/checksums.txt" || fail "couldn't download checksums.txt"

if command -v sha256sum >/dev/null 2>&1; then
  actual=$(sha256sum "$tmp/$asset" | cut -d' ' -f1)
elif command -v shasum >/dev/null 2>&1; then
  actual=$(shasum -a 256 "$tmp/$asset" | cut -d' ' -f1)
else
  fail "sha256sum or shasum is required"
fi
expected=$(grep " [*]*$asset\$" "$tmp/checksums.txt" | head -n1 | cut -d' ' -f1)
[ -n "$expected" ] || fail "$asset is not listed in checksums.txt"
[ "$actual" = "$expected" ] || fail "checksum mismatch for $asset (expected $expected, got $actual)"

tar -xzf "$tmp/$asset" -C "$tmp"
[ -f "$tmp/$NAME" ] || fail "the archive has no $NAME binary"
chmod +x "$tmp/$NAME"

dir="${BRAINITIVE_INSTALL_DIR:-}"
if [ -z "$dir" ]; then
  if [ -w /usr/local/bin ]; then
    dir=/usr/local/bin
  elif [ -d /usr/local/bin ] && command -v sudo >/dev/null 2>&1 && [ -t 0 ]; then
    say "Installing to /usr/local/bin needs your password (sudo)."
    sudo install -m 0755 "$tmp/$NAME" "/usr/local/bin/$NAME"
    dir=/usr/local/bin
    say "Installed $("$dir/$NAME" version) to $dir/$NAME"
    exit 0
  else
    dir="$HOME/.local/bin"
  fi
fi
mkdir -p "$dir"
install -m 0755 "$tmp/$NAME" "$dir/$NAME" 2>/dev/null || cp "$tmp/$NAME" "$dir/$NAME"

say "Installed $("$dir/$NAME" version) to $dir/$NAME"
case ":$PATH:" in
  *":$dir:"*) ;;
  *)
    say ""
    say "$dir is not on your PATH. Add it to your shell profile:"
    say "  export PATH=\"$dir:\$PATH\""
    ;;
esac
say ""
say "Next: brainitive login            # Brainitive Cloud"
say "      brainitive login --server brainitive.example.com   # self-hosted"
}

main "$@"

#!/usr/bin/env bash
#
# Run a command with the Android toolchain on PATH: the JDK and the SDK.
#
# Usage: scripts/native/android-env.sh COMMAND [ARGS…]
#   e.g. scripts/native/android-env.sh npx cap run android
#
# Gradle needs JDK 21 (Capacitor 8). Homebrew's openjdk@21 is keg-only, and
# /usr/bin/java is Apple's stub that only says "Unable to locate a Java
# Runtime", so JAVA_HOME has to be set; a JAVA_HOME or ANDROID_HOME already
# in the environment wins.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/lib/log.sh
. "$REPO_ROOT/scripts/lib/log.sh"

[ $# -gt 0 ] || { sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 1; }

export JAVA_HOME="${JAVA_HOME:-/opt/homebrew/opt/openjdk@21/libexec/openjdk.jdk/Contents/Home}"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Library/Android/sdk}"
[ -x "$JAVA_HOME/bin/java" ] || die "no JDK at $JAVA_HOME
  install with: brew install openjdk@21 (or set JAVA_HOME)"
[ -d "$ANDROID_HOME/platforms" ] || die "no Android SDK at $ANDROID_HOME
  install Android Studio's SDK there (or set ANDROID_HOME)"

export ANDROID_SDK_ROOT="$ANDROID_HOME"
export PATH="$JAVA_HOME/bin:$ANDROID_HOME/platform-tools:$ANDROID_HOME/emulator:$PATH"
exec "$@"

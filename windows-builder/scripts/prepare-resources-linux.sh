#!/bin/bash
# Saffron POS — Prepare resources from Linux (Omarchy)
# This script copies the PHP application and downloads fonts
# for inclusion in the Windows build.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILDER_DIR="$(dirname "$SCRIPT_DIR")"
APP_SOURCE="$(dirname "$BUILDER_DIR")"
RESOURCES="$BUILDER_DIR/resources"

echo "============================================================"
echo "   Saffron POS — Linux Resource Preparation"
echo "============================================================"
echo
echo "Source: $APP_SOURCE"
echo "Target: $RESOURCES"
echo

# Check source
if [ ! -f "$APP_SOURCE/includes/config.php" ]; then
    echo "[FAIL] PHP application not found at: $APP_SOURCE"
    echo "       Ensure windows-builder is inside the POS project."
    exit 1
fi

# 1. Copy PHP application
echo "[1/3] Copying PHP application..."
mkdir -p "$RESOURCES/application"
# Use rsync to exclude the windows-builder directory itself
if command -v rsync &>/dev/null; then
    rsync -a --exclude='windows-builder' --exclude='node_modules' --exclude='dist' "$APP_SOURCE/" "$RESOURCES/application/"
else
    # Fallback: create tar and extract excluding unwanted dirs
    cd "$APP_SOURCE"
    tar cf - --exclude='./windows-builder' --exclude='./node_modules' --exclude='./dist' . | (cd "$RESOURCES/application" && tar xf -)
    cd "$BUILDER_DIR"
fi
echo "  [OK] Application copied"

# 2. Download fonts
echo "[2/3] Downloading fonts for offline use..."
FONTS_DIR="$RESOURCES/fonts"
mkdir -p "$FONTS_DIR"

FONT_URLS=(
    "https://fonts.gstatic.com/s/ibmplexsans/v19/zYXgKVElMYYaJe8bpLHnCwDKhdHeFaxOedc.woff2|IBMPlexSans-Regular.woff2"
    "https://fonts.gstatic.com/s/ibmplexsans/v19/zYX9KVElMYYaJe8bpLHnCwDKjSL9AIFsdP3pBms.woff2|IBMPlexSans-Medium.woff2"
    "https://fonts.gstatic.com/s/ibmplexsans/v19/zYX9KVElMYYaJe8bpLHnCwDKjWr8AIFsdP3pBms.woff2|IBMPlexSans-SemiBold.woff2"
    "https://fonts.gstatic.com/s/ibmplexsans/v19/zYX9KVElMYYaJe8bpLHnCwDKjQ76AIFsdP3pBms.woff2|IBMPlexSans-Bold.woff2"
    "https://fonts.gstatic.com/s/ibmplexmono/v19/-F63fjptAgt5VM-kVkqdyU8n5iQ.woff2|IBMPlexMono-Regular.woff2"
    "https://fonts.gstatic.com/s/ibmplexmono/v19/-F6qfjptAgt5VM-kVkqdyU8n3oQIwl1FgsAXHNlYzg.woff2|IBMPlexMono-SemiBold.woff2"
)

for entry in "${FONT_URLS[@]}"; do
    IFS='|' read -r url name <<< "$entry"
    if [ -f "$FONTS_DIR/$name" ]; then
        echo "  [SKIP] $name (already exists)"
    else
        echo -n "  Downloading $name... "
        if curl -sL -o "$FONTS_DIR/$name" "$url" 2>/dev/null; then
            echo "[OK]"
        else
            echo "[FAIL]"
        fi
    fi
done

# 3. Copy local font CSS override
echo "[3/3] Copying local font CSS..."
if [ -f "$RESOURCES/application/css/fonts-local.css" ]; then
    echo "  [OK] fonts-local.css already in place"
else
    echo "  [WARN] fonts-local.css not found, skipping"
fi

echo
echo "============================================================"
echo "   RESOURCES PREPARED SUCCESSFULLY"
echo "============================================================"
echo
echo "Next steps on Windows:"
echo "  1. Copy windows-builder/ to a Windows machine"
echo "  2. Place PHP zip in windows-builder/ (or run setup-php.bat)"
echo "  3. Place MariaDB zip in windows-builder/ (or run setup-mariadb.bat)"
echo "  4. Run bundle-all-resources.bat"
echo "  5. Run build-windows.bat"
echo "============================================================"

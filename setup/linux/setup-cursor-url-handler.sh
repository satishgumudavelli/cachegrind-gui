#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HANDLER_SRC="${SCRIPT_DIR}/cursor-url-handler"
BIN_DIR="${HOME}/.local/bin"
DESKTOP_DIR="${HOME}/.local/share/applications"
HANDLER_DST="${BIN_DIR}/cursor-url-handler"
DESKTOP_DST="${DESKTOP_DIR}/cursor-url-handler.desktop"
MIMEAPPS="${HOME}/.config/mimeapps.list"
OLD_DESKTOP="${DESKTOP_DIR}/cursor-url.desktop"

die() {
    echo "error: $*" >&2
    exit 1
}

command -v cursor >/dev/null 2>&1 || die "cursor CLI not found. Install Cursor and ensure 'cursor' is on PATH."

if [ ! -f "${HANDLER_SRC}" ]; then
    die "missing ${HANDLER_SRC}"
fi

mkdir -p "${BIN_DIR}" "${DESKTOP_DIR}"
cp "${HANDLER_SRC}" "${HANDLER_DST}"
chmod +x "${HANDLER_DST}"

cat > "${DESKTOP_DST}" <<EOF
[Desktop Entry]
Name=Cursor - URL Handler
Comment=The AI Code Editor.
GenericName=Text Editor
Exec=${HANDLER_DST} %u
Icon=co.anysphere.cursor
Type=Application
NoDisplay=true
StartupNotify=true
Categories=Utility;TextEditor;Development;IDE;
MimeType=x-scheme-handler/cursor;
Keywords=cursor;
EOF

if [ -f "${OLD_DESKTOP}" ]; then
    rm -f "${OLD_DESKTOP}"
    echo "removed duplicate handler: ${OLD_DESKTOP}"
fi

if command -v xdg-mime >/dev/null 2>&1; then
    xdg-mime default cursor-url-handler.desktop x-scheme-handler/cursor
fi

if command -v update-desktop-database >/dev/null 2>&1; then
    update-desktop-database "${DESKTOP_DIR}" >/dev/null 2>&1 || true
fi

if [ -f "${MIMEAPPS}" ]; then
    if grep -q '^x-scheme-handler/cursor=' "${MIMEAPPS}"; then
        sed -i 's|^x-scheme-handler/cursor=.*|x-scheme-handler/cursor=cursor-url-handler.desktop|' "${MIMEAPPS}"
    elif grep -q '^\[Default Applications\]' "${MIMEAPPS}"; then
        sed -i '/^\[Default Applications\]/a x-scheme-handler/cursor=cursor-url-handler.desktop' "${MIMEAPPS}"
    fi
fi

DEFAULT="$(xdg-mime query default x-scheme-handler/cursor 2>/dev/null || true)"
echo "installed: ${HANDLER_DST}"
echo "desktop:   ${DESKTOP_DST}"
echo "default:   ${DEFAULT:-unknown}"
echo
echo "test:"
echo "  xdg-open \"cursor://open?file=${SCRIPT_DIR}/../index.php&line=1\""

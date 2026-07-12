# PHP Bottleneck (Cachegrind GUI)

Web UI to browse Xdebug **Cachegrind** profiles, find slow functions, compare before/after runs, and jump to source in your editor.

**URL:** `http://localhost/bottleneck/` (or your vhost that serves `/var/www/html`)

---

## Requirements

- PHP 8+ with web server (Apache/nginx) serving `/var/www/html`
- Xdebug configured to write Cachegrind output, e.g.:

  ```ini
  xdebug.mode=profile
  xdebug.output_dir=/tmp
  ```

- Readable profile paths under allowed roots:
  - `/var/www/html`
  - `/tmp`

---

## Quick start

1. Generate a profile (trigger a slow request with Xdebug profiling on).
2. Open `http://localhost/bottleneck/`.
3. Browse to the `cachegrind.out.*` file (often under `/tmp`).
4. Click **Analyze** to see the slowest functions and call tree.
5. Use **Compare** with two profiles to see what changed.
6. Click **Open in editor** on a stack frame to jump to the file and line.

---

## Open in editor

The UI can open source via browser protocol handlers:

| Editor   | URL format                                      |
|----------|--------------------------------------------------|
| Cursor   | `cursor://open?file=/abs/path&line=42`          |
| VS Code  | `vscode://file/abs/path:42`                     |
| Sublime  | `subl://open?url=file://…&line=42`              |

**Cursor on Linux** needs a one-time OS setup so `cursor://` links work (see below).

---

## New Linux PC — Cursor URL handler setup

When you move to another machine, run the bundled installer:

```bash
bash /var/www/html/bottleneck/setup/linux/setup-cursor-url-handler.sh
```

**Prerequisites:**

- Cursor installed
- `cursor` CLI on PATH (`cursor --version` should work)

If the CLI is missing:

```bash
# Example: symlink after installing Cursor .deb
sudo ln -sf /usr/share/cursor/bin/cursor /usr/local/bin/cursor
```

**What the script does:**

1. Copies `setup/linux/cursor-url-handler` → `~/.local/bin/cursor-url-handler`
2. Registers `~/.local/share/applications/cursor-url-handler.desktop`
3. Sets `cursor://` as default via `xdg-mime`
4. Updates `~/.config/mimeapps.list`
5. Removes old duplicate `cursor-url.desktop` if present

**Verify:**

```bash
xdg-mime query default x-scheme-handler/cursor
# expected: cursor-url-handler.desktop

xdg-open "cursor://open?file=/var/www/html/bottleneck/index.php&line=1"
```

Cursor should open the file at line 1.

---

## Troubleshooting

### “Open With…” dialog every time

Two handlers were registered for `cursor://`. Re-run the setup script — it keeps a single **Cursor - URL Handler** entry.

In the browser, choose **Cursor - URL Handler** and enable **Always use this application**.

### Link does nothing

- Confirm `cursor` works: `cursor --version`
- Re-run `setup-cursor-url-handler.sh`
- Run `update-desktop-database ~/.local/share/applications`

### Wrong line or file not found

Paths must be absolute on the server. The handler URL-decodes `%2F` and other encoded characters in the path.

### Profile not listed

Only these roots are allowed: `/var/www/html`, `/tmp`. Adjust `ALLOWED_ROOTS` in `index.php` if your profiles live elsewhere.

---

## Files

```
bottleneck/
├── index.php                 # Web UI + API
├── app.js                    # Frontend
├── CachegrindAnalyzer.php    # Parse & rank Cachegrind output
├── SourceFileResolver.php    # Map profile paths to source files
├── setup/linux/
│   ├── cursor-url-handler           # Protocol handler script
│   └── setup-cursor-url-handler.sh  # One-shot Linux installer
└── README.md
```

## License

MIT — see [LICENSE](LICENSE).

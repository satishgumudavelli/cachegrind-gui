# PHP Bottleneck (Cachegrind GUI)

Web UI to browse Xdebug **Cachegrind** profiles, find slow functions, compare before/after runs, and jump to source in your editor.

**URL:** `http://localhost/bottleneck/` (or your vhost that serves `/var/www/html`)

In the app header, click **README** to open this doc in a new tab (`?action=readme`).

**Repo:** [satishgumudavelli/cachegrind-gui](https://github.com/satishgumudavelli/cachegrind-gui)

---

## Requirements

- PHP 8+ with web server (Apache/nginx) serving `/var/www/html`
- [Xdebug 3+](https://xdebug.org/) with **profile** mode (see below)
- Readable profile paths under allowed roots:
  - `/var/www/html`
  - `/tmp`

---

## Xdebug setup (profiling)

Bottleneck reads Xdebug **Cachegrind** files (`cachegrind.out.*`). Configure Xdebug **3** (not the old `xdebug.profiler_enable` settings).

### 1. Confirm Xdebug is loaded

```bash
php -v | grep -i xdebug
php -i | grep -E 'xdebug.(mode|output_dir|start_with_request)'
```

Find the active ini (often `/etc/php/*/mods-available/xdebug.ini` or a `conf.d/20-xdebug.ini`):

```bash
php --ini
```

### 2. Recommended php.ini (trigger mode)

Prefer **trigger** so normal browsing stays fast; only profile when you ask for it:

```ini
zend_extension=xdebug.so

xdebug.mode=profile
xdebug.start_with_request=trigger
xdebug.output_dir=/var/www/html/bottleneck/profiler
xdebug.profiler_output_name=cachegrind.out.%u.%r
; Bottleneck reads plain text Cachegrind — disable gz compression
xdebug.use_compression=false
```

| Setting | Notes |
|---------|--------|
| `xdebug.mode=profile` | Enables the profiler (can combine: `develop,profile`) |
| `xdebug.start_with_request=trigger` | Profile only when triggered (recommended) |
| `xdebug.output_dir` | Must exist and be **writable** by the PHP / web-server user |
| `xdebug.profiler_output_name` | Must start with `cachegrind.out` — Bottleneck filters on that name |
| `xdebug.use_compression=0` | Avoid `.gz` profiles; this app opens uncompressed files |

**Magento tip:** point output at the project profiler folder so profiles sit next to the code tree:

```ini
xdebug.output_dir=/var/www/html/your-project/var/profiler
```

Create it and make it writable:

```bash
mkdir -p /var/www/html/your-project/var/profiler
chmod 777 /var/www/html/your-project/var/profiler   # or chown to www-data
```

Bottleneck also auto-creates a missing folder named `profiler` when you browse to it (parent must already exist under an allowed root), and ensures `/var/www/html/bottleneck/profiler` on load.
Restart PHP-FPM / Apache after editing ini:

```bash
sudo systemctl restart php8.2-fpm   # adjust version
# or: sudo systemctl restart apache2
```

### 3. Trigger a profile

With `start_with_request=trigger`, start one request with any of:

| Method | Example |
|--------|---------|
| Query string | `https://shop.local/checkout/?XDEBUG_TRIGGER=1` |
| Legacy query | `?XDEBUG_PROFILE=1` |
| Cookie | `XDEBUG_TRIGGER=1` (browser extensions / Xdebug helper) |
| CLI env | `XDEBUG_TRIGGER=1 php bin/magento …` |

Optional lock so only your secret triggers profiling:

```ini
xdebug.trigger_value=StartProfileForMe
```

Then use `?XDEBUG_TRIGGER=StartProfileForMe`.

**Always-on** (every request — heavy; local only):

```ini
xdebug.mode=profile
xdebug.start_with_request=yes
```

### 4. Verify a file was written

```bash
ls -lt /tmp/cachegrind.out.* | head
# or Magento:
ls -lt /var/www/html/your-project/var/profiler/cachegrind.out.* | head
```

If nothing appears: check `output_dir` permissions, that FPM/Apache picked up the ini (`php-fpm` vs CLI can differ), and that the trigger was present on the **first** request (not only on AJAX follow-ups unless those are triggered too).

### 5. Open in Bottleneck

1. Open `http://localhost/bottleneck/`
2. Browse to the `output_dir` (or paste the full `cachegrind.out.*` path)
3. Click **Analyze**

Profiling **inflates** wall time vs production — use deltas / baseline compares, not absolute seconds, when judging fixes.

Official docs: [Xdebug Profiling](https://xdebug.dev/docs/profiler)

---

## Quick start

1. Configure Xdebug (section above) and generate a profile for a slow request.
2. Open `http://localhost/bottleneck/`.
3. Browse to the `cachegrind.out.*` file (often under `/tmp` or `var/profiler`).
4. Click **Analyze** to see the slowest functions, modules, plugins, and flame chart.
5. Use **Compare** / **vs baseline** for before/after deltas.
6. Click a function → **Backtrace / callers**; use **← Back** after drilling into callers.
7. Click **Open in editor** on a stack frame to jump to the file and line.
8. **Export HTML** to attach a report to a ticket or PR.

---

## Features

| Feature | What it does |
|---------|----------------|
| **Inclusive / self time** | Toggle metric; self-time finds hot leaves |
| **Scope filter** | App only / hide vendor / framework (default: App only) |
| **Modules tab** | Roll up self-time by `Vendor\Module` |
| **Plugins tab** | Interceptor / Plugin / PluginList tax (% of `{main}`) |
| **3rd-party APIs** | HTTP boundaries + callers from the selected Cachegrind profile only (no static URL catalogs) |
| **Flame tab** | Icicle call-tree from `{main}`; click a block to open backtrace |
| **Backtrace drill-back** | **← Back** restores the previous function after link/row traversal |
| **Labels / notes / endpoint** | Tag profiles; group by endpoint in the file list |
| **Baseline** | Mark a known-good profile; one-click **vs baseline** compare |
| **Trend** | Chart `{main}` across recent profiles in a folder |
| **Export HTML / JSON** | Shareable HTML report for tickets/PRs, or machine-readable JSON |
| **Copy AI prompt** | Clipboard prompt for Cursor with hotspots + plugin tax |
| **Cleanup** | Delete old profiles by age / keep-newest (keeps baseline; dry-run + size confirm) |
| **Cancel analyze** | Abort long parses; indeterminate progress bar while parsing |
| **CLI** | `php bin/analyze.php …` with `--fail-over` / `--fail-module` CI gates |
| **Open in editor** | Cursor / VS Code / Sublime protocol handlers + optional HTTP opener |

---

## Open in editor

Browser protocol handlers:

| Editor   | URL format                                      |
|----------|--------------------------------------------------|
| Cursor   | `cursor://open?file=/abs/path&line=42`          |
| VS Code  | `vscode://file/abs/path:42`                     |
| Sublime  | `subl://open?url=file://…&line=42`              |

### HTTP open server (alternative)

Avoids custom-protocol browser prompts:

```bash
bash /var/www/html/bottleneck/setup/linux/open-http-server.sh
```

Then link to:

```text
http://127.0.0.1:63343/open?file=/abs/path/to/File.php&line=42
```

Localhost-only; path must be under `/var/www/html` or `/tmp`.

### Cursor protocol on Linux

```bash
bash /var/www/html/bottleneck/setup/linux/setup-cursor-url-handler.sh
```

Requires `cursor` on PATH. See script output for a test URL.

---

## CLI

```bash
php bin/analyze.php /path/to/cachegrind.out.123
php bin/analyze.php /path/to/cachegrind.out.123 --top 20 --min 0.05 --self
php bin/analyze.php /path/to/cachegrind.out.123 --plugins --json
php bin/analyze.php /path/to/cachegrind.out.123 --fail-over 15 --fail-module 'Acme\Checkout:0.8'
```

| Option | Meaning |
|--------|---------|
| `--top N` | Number of top functions (default 20) |
| `--min SEC` | Minimum seconds threshold |
| `--self` | Sort / report by self-time |
| `--plugins` | Print interceptor / plugin tax hotspots |
| `--json` | JSON output (includes `gates` when thresholds set) |
| `--fail-over SEC` | Exit **2** if `{main}` exceeds SEC |
| `--fail-module M:SEC` | Exit **2** if module self-time exceeds SEC (repeatable) |

---

## Profile metadata

Labels are stored next to the profile folder in `.bottleneck-meta.json`:

- `label`, `notes`, `endpoint`, `method`
- `baseline` path for the folder

Safe to commit or gitignore per team preference. Endpoint values power the **By endpoint** grouping in the file browser.

---

## Troubleshooting

### “Open With…” dialog every time

Re-run `setup/linux/setup-cursor-url-handler.sh` and choose **Cursor - URL Handler** once with “Always”.

### Profile not listed

Only `/var/www/html` and `/tmp` are allowed. Adjust `ALLOWED_ROOTS` in `index.php` if needed.

### Large profiles

First parse is slow; results are cached under the system temp dir. Use **Cancel** to abort. Prefer higher **Min seconds** / lower **Top N**.

---

## Files

```
bottleneck/
├── index.php                 # Web UI + API
├── app.js                    # Frontend
├── CachegrindAnalyzer.php    # Parse, self-time, modules, plugins, flame, APIs
├── SourceFileResolver.php    # Map profile paths to source files
├── ProfileMeta.php           # Labels, notes, endpoint, baseline
├── bin/analyze.php           # CLI twin + CI gates
├── setup/linux/
│   ├── cursor-url-handler
│   ├── setup-cursor-url-handler.sh
│   ├── open-router.php
│   └── open-http-server.sh
├── LICENSE
└── README.md
```

## License

MIT — see [LICENSE](LICENSE).

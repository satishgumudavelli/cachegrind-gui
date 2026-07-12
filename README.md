# PHP Bottleneck (Cachegrind GUI)

Web UI to browse Xdebug **Cachegrind** profiles, find slow functions, compare before/after runs, and jump to source in your editor.

**URL:** `http://localhost/bottleneck/` (or your vhost that serves `/var/www/html`)

In the app header, click **README** to open this doc in a new tab (`?action=readme`).

**Repo:** [satishgumudavelli/cachegrind-gui](https://github.com/satishgumudavelli/cachegrind-gui)

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

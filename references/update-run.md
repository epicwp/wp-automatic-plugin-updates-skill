# Update run — the procedure

You are updating plugins, and WordPress core, on a **live production site**.
One unit at a time, with a measurement before and after, and a rollback you
verifiably check.
Everything you do is real and visible to the site's visitors.

Load the site's entry from `~/.wp-plugin-updates/sites.yaml` first. All server
actions run as `ssh <alias> "cd <wproot> && ..."`. The hard rules in SKILL.md
apply throughout and override everything here.

**Dry run:** when the user chose a dry run (`DRYRUN=true` — always offer it if
`first_run_done` is false), do everything below *except* the actual update
commands. Say so in the plan and the report.

Persistent files on the server (the system's memory between runs):

```
<workdir>/waitlist.json     # plugins a human must judge, with reasons
<workdir>/history.jsonl     # one line per plugin per run: what, when, outcome
<workdir>/<RUNID>/          # snapshots + baselines of this run
```

**RUNID is date + time, not just the date** — e.g. `2026-08-10T1430`.
Determine it once at the start and use it everywhere:

```bash
RUNID=$(ssh <alias> "date +%Y-%m-%dT%H%M")
```

There can be several runs on one day (a dry run followed by the real one).
A date-only RUNID overwrites the previous run's snapshots — exactly the
rollback you'll need. If `<workdir>/<RUNID>/` already exists: stop and report,
never overwrite.

---

## Phase 0 — Preparation

### 0.1 Can you safely begin?

```bash
ssh <alias> "cd <wproot> && wp core version && wp plugin list --format=count && df -h . | tail -1"
```

Stop and report if:
- less than 5 GB of free disk space
- WordPress doesn't respond or already throws fatals
- a run already seems active (`<workdir>/.lock` exists)

Set a lock: `ssh <alias> "mkdir -p <workdir> && touch <workdir>/.lock"`.
Remove it at the end of the run — also when aborting.

### 0.2 Disable host-side auto-updates

If the hosting platform runs its own auto-updates (Kinsta and others), tell
the user and **stop until it's off** — otherwise you're fighting a second
system and can never know who did what. Also check WordPress itself:

```bash
ssh <alias> "cd <wproot> && wp plugin auto-updates status --format=table 2>/dev/null"
```

WordPress core has its own switches. Read them too:

```bash
ssh <alias> "cd <wproot> && echo WP_AUTO_UPDATE_CORE=\$(wp config get WP_AUTO_UPDATE_CORE --type=constant 2>/dev/null || echo undefined); echo auto_update_core_major=\$(wp option get auto_update_core_major 2>/dev/null || echo unset); echo auto_update_core_minor=\$(wp option get auto_update_core_minor 2>/dev/null || echo unset)"
```

- `auto_update_core_major=enabled`, or `WP_AUTO_UPDATE_CORE` set to `true`,
  `beta` or `rc`: WordPress moves itself to feature releases with no judgment
  gate, no snapshot and no measurement. Tell the user and **stop the core
  unit until it is off** — switching it (`wp option update
  auto_update_core_major disabled`) is their site policy, so only on an
  explicit yes. Plugin units may continue meanwhile.
- `auto_update_core_minor=enabled` (WordPress default): leave it. Minor
  releases are the security channel and WordPress's own file rollback covers
  them. Note it in the report: a minor can land between two runs.

### 0.2b Measure the opcache window

PHP-FPM keeps compiled bytecode in opcache and re-checks files only every
`opcache.revalidate_freq` seconds. Until then, a freshly swapped plugin runs
as a **mix** of old bytecode and new files — on a plugin that moved or renamed
classes that is a `Class "..." not found` fatal on every page, for visitors
too, and it happens again in reverse on a rollback. Observed: 30 seconds on
Kinsta, two 500 windows of about a minute each on one update.

```bash
ssh <alias> "grep -rhE '^opcache\.(revalidate_freq|validate_timestamps)' /etc/php/*/fpm/ 2>/dev/null | sort -u"
```

The FPM value is the one that counts (`wp eval` reports the CLI's own, often
lower). Settle time = that value + 10 s, default 40 s when unreadable.
**Wait the settle time after every file swap before the HTTP probes** (P1,
P8, P11, extras) — CLI probes are unaffected and can run meanwhile. Say in
the report that each update carries this visitor-facing window; it is a
host property, not something the procedure adds.

### 0.3 One DB dump as a safety net

Once per run, not per plugin:

```bash
ssh <alias> "cd <wproot> && mkdir -p <workdir>/<RUNID> && wp db export <workdir>/<RUNID>/db-before.sql --add-drop-table 2>/dev/null && gzip <workdir>/<RUNID>/db-before.sql && ls -lh <workdir>/<RUNID>/"
```

You never restore this automatically (hard rule 5). It exists for a human.

**Dry-run exception:** a dry run changes nothing, so a dump from earlier today
(another RUNID directory) is still valid — reuse it and note which one in the
report. For a real run, always make a fresh dump.

### 0.4 Build the site profile

Choose the URLs you will measure — 8–12: homepage, one category page, 2–3
products (WooCommerce), cart, checkout, my-account, one post/page, and the
sitemap. For non-WooCommerce sites: homepage, key landing pages, one
archive, one post, any critical forms page, and the sitemap. Always add
`/wp-login.php` and `/wp-json/` (the REST root): both are rendered by core,
so they move with core rather than with the theme.

**Product selection is critical and goes wrong easily** (WooCommerce).
"Best-selling" is a bad heuristic: those products are often out of stock or
discontinued, the probe returns an empty price or 404 and gets discarded —
leaving you without product coverage. Filter explicitly:

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$found = [];
foreach ( wc_get_products( [
    'status'       => 'publish',
    'stock_status' => 'instock',
    'limit'        => 30,
    'orderby'      => 'popularity',
] ) as $p ) {
    if ( '' === (string) $p->get_price() ) { continue; }
    if ( ! $p->is_purchasable() ) { continue; }
    $found[] = implode( '|', [ $p->get_id(), $p->get_type(), $p->get_price(), $p->get_permalink() ] );
    if ( count( $found ) >= 3 ) { break; }
}
echo $found ? implode( "\n", $found ) . "\n" : "NO_SUITABLE_PRODUCT\n";
PHP
```

Prefer one simple and one variable product so both code paths are covered.
On `NO_SUITABLE_PRODUCT`, tell the user: this run has no product coverage.

**Multilingual sites: include every language.** With one language you measure
half the shop, and the translation layer is exactly what plugin updates break.
Per active language add at least the homepage, one product, and the checkout.

Record the profile in `<workdir>/<RUNID>/profile.json`: URLs, product IDs,
whether WooCommerce is active, which custom/own plugins exist.

---

## Phase 1 — Inventory and risk

### 1.1 Fetch the update list — force a fresh one first

WordPress caches available updates in the `update_plugins` transient for
**12 hours**. Read it stale and you research changelogs of versions you won't
install. Always clear first:

```bash
ssh <alias> "cd <wproot> && wp transient delete update_plugins 2>/dev/null; wp plugin list --update=available --fields=name,status,version,update_version --format=json 2>/dev/null"
```

**The target versions from this list are binding for the rest of the run.**
If a different target appears later, that plugin is by definition
*unresearched* — re-assess or waitlist. This applies emphatically when
building on an earlier dry run: versions shift within a day. (Observed in
practice: 4 of 16 targets shifted within three hours.) Compare every reused
target against the fresh list and report which moved.

**Gate: can the update be installed at all?** Check per plugin whether a
download package exists. An empty `package` means the update is technically
impossible — almost always an inactive or expired license. That's a fact, not
a risk, and you know it before the loop. The same readout tells you the
**channel**, which decides pinning later:

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$u = get_site_transient( 'update_plugins' );
foreach ( (array) ( $u->response ?? [] ) as $file => $d ) {
    $pkg = $d->package ?? '';
    if ( '' === $pkg ) {
        $channel = 'NO PACKAGE (license?)';
    } elseif ( false !== strpos( $pkg, 'downloads.wordpress.org' ) ) {
        $channel = 'wp.org  (pinning possible)';
    } else {
        $channel = 'vendor  (pinning NOT possible) — ' . parse_url( $pkg, PHP_URL_HOST );
    }
    printf( "%-45s %-12s %s\n", dirname( $file ), $d->new_version ?? '?', $channel );
}
PHP
```

Plugins without a package go straight to the waitlist as "download blocked" —
no changelog research needed; you couldn't install anyway. State that this
needs a **commercial** action (activate or renew a license), not a technical
one. If a known vulnerability is open on such a plugin, say so prominently:
that's a security deadline, not an admin chore.

Read `<workdir>/waitlist.json` and **exclude everything on it**, reporting
per skipped plugin the date and reason. This is the memory that stops you
hitting the same wall every run.

### 1.1b The core update — same freshness rule

Skip this when the site config has `core_updates: false` (say so in the
plan). Otherwise force a fresh check — the `update_core` transient is cached
like the plugin one:

```bash
ssh <alias> "cd <wproot> && wp transient delete update_core 2>/dev/null; wp core check-update --format=csv 2>/dev/null; wp core version --extra 2>/dev/null"
```

Empty list ⇒ no core unit this run. Otherwise the **newest** offered version
is the target (never an intermediate one), and like a plugin target it is
binding for the rest of the run. `update_type` reads `major` for a feature
release (x.y) and `minor` for a maintenance or security release (x.y.z).
Record the **package language** from `--extra`: that is the locale you pin
in phase 3. It is not necessarily the locale of the offered package — a
Dutch site often runs the `en_US` package plus language packs while
`check-update` offers the `nl_NL` bundle. Pin what is installed, so the
install does not flip between the two flavours.

WordPress only offers versions the site's PHP can run. If wordpress.org has
a newer release than `check-update` shows (compare with
`https://api.wordpress.org/core/version-check/1.7/`), PHP is the usual
reason: waitlist it as **blocked: PHP** — a hosting action, not a technical
one. Core always comes from wordpress.org, so pinning is always possible:
no channel gate.

### 1.2 Research per plugin — local first, external as backstop

**Don't search the web first.** WordPress fetches changelogs itself through
the plugin's own update channel via `plugins_api()` — the same source as the
"Changelog" tab in wp-admin. Faster, authoritative, and it works for many
premium plugins too:

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$slugs   = [ /* SLUGS */ ];
$updates = get_site_transient( 'update_plugins' );

foreach ( $slugs as $slug ) {
    echo "===== {$slug} =====\n";

    foreach ( (array) ( $updates->response ?? [] ) as $file => $data ) {
        if ( dirname( $file ) === $slug && ! empty( $data->upgrade_notice ) ) {
            echo '[UPGRADE NOTICE] ' . trim( wp_strip_all_tags( $data->upgrade_notice ) ) . "\n";
        }
    }

    $info = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => true ] ] );
    if ( is_wp_error( $info ) ) { echo "NO LOCAL CHANGELOG: " . $info->get_error_message() . "\n\n"; continue; }

    $cl = $info->sections['changelog'] ?? '';
    if ( ! $cl ) { echo "NO LOCAL CHANGELOG\n\n"; continue; }
    echo trim( wp_strip_all_tags( str_replace( [ '</li>', '</p>', '</h4>' ], "\n", $cl ) ) ) . "\n\n";
}
PHP
```

Three things this gives you that external sources don't:

- **Release dates are usually included** — the age factor works for premium
  plugins too.
- **No slug confusion.** Searching wp.org for a slug can land on a completely
  different (even closed) plugin; `plugins_api()` asks the updater of the
  plugin actually installed here.
- **`upgrade_notice`** — a short vendor warning pinned to a version. Rare,
  but when someone bothers to set it, something is usually up.

The returned changelog is often the **full history**. Clip the range between
the installed and the target version yourself; only that range counts.

**When to research externally anyway.** Local fetching replaces the
*fetching*, not the *judging*. Vendors understate (see risk-model.md for the
Forminator example). Search externally when:

- there is no local changelog, or the clipped range is empty
- the text mentions security, breaking, removed, deprecated, renamed, or a migration
- the plugin is on the critical path and the description is vague
- there is an `upgrade_notice`

Sources: `wordpress.org/plugins/<slug>/#developers`, the vendor changelog,
GitHub releases, and for vulnerabilities Patchstack or WPScan. If nothing
turns up, say so explicitly — **"not found" is a valid outcome** that raises
the risk. Never invent changelog content, and never accept a search result
that contradicts the official changelog: note the contradiction and trust the
official source.

### 1.2b Research the core update

Core has no `plugins_api()` changelog. Use, in this order:

- **Release post**, machine-readable — wordpress.org/news is itself a
  WordPress site, so its REST API answers without HTML scraping:
  `curl -s "https://wordpress.org/news/wp-json/wp/v2/posts?search=WordPress%20<x.y.z>&per_page=5&_fields=date,title,link,content"`.
  Read the date (release age) and the content: a minor's post says whether
  it is a security release; a feature release's post lists what changed.
  The documentation page
  `https://wordpress.org/documentation/wordpress-version/version-<x-y-z>/`
  holds the same and more, but renders client-side — don't rely on
  `curl | grep` there.
- **Requirements**: `curl -s "https://api.wordpress.org/core/version-check/1.7/?version=<installed>&php=<site php>&locale=<locale>"`
  lists every offer with `php_version` and `mysql_version` minimums and the
  download URL. This is the same call WordPress makes for `check-update`.
- **Security status of the installed version**:
  `curl -s https://api.wordpress.org/core/stable-check/1.0/` lists every
  version as `latest`, `outdated` or `insecure`. **`insecure` is a security
  deadline**: present it to the user as such, the same way as an actively
  exploited plugin vulnerability — their trade-off, not a sum.
- **Field notes**: the release post on `wordpress.org/news` and
  `https://wordpress.org/support/forum/alphabeta/` for early breakage
  reports — the role the plugin forum plays for plugins.

Then measure the plugin side of the jump — which active plugins declare
themselves tested against the target:

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$target  = '7.1';   // TARGET feature version x.y
$updates = get_site_transient( 'update_plugins' );
foreach ( (array) get_option( 'active_plugins' ) as $file ) {
    $slug   = dirname( $file );
    $tested = '';
    $readme = WP_PLUGIN_DIR . "/{$slug}/readme.txt";   // describes what is INSTALLED
    if ( file_exists( $readme ) && preg_match( '/Tested up to:\s*([0-9.]+)/i', file_get_contents( $readme ), $m ) ) {
        $tested = $m[1];
    }
    if ( '' === $tested ) {
        $tested = $updates->no_update[ $file ]->tested ?? '';   // also the installed version
    }
    $state = '' === $tested ? 'UNKNOWN' : ( version_compare( $tested, $target, '>=' ) ? 'ok' : 'BEHIND' );
    printf( "%-45s tested=%-8s %s\n", $slug, $tested ?: '-', $state );
}
PHP
```

Read the **installed** declaration, not the update's: the transient's
`response` entries carry the `tested` value of the *pending* version, which
is what you'd get after the plugin unit, not what runs under core today.
`readme.txt` and the `no_update` entries describe the installed version.
Score against what will actually be installed when core runs: plugins that
update earlier in this run count with their new declaration (the `response`
value), waitlisted or license-blocked ones with the installed one.

`BEHIND` by more than one feature release on a critical-path plugin is a
counted factor (risk-model.md); one release behind is a readme's normal lag.
`UNKNOWN` is the premium-plugin blind spot — list them, count nothing, say
so. Also list what could replace core components: `ls wp-content/*.php
wp-content/mu-plugins` — drop-ins score, must-use plugins are only listed.

### 1.3 Score the risk

Apply [risk-model.md](risk-model.md) — plugins with the plugin table, core
with its own. Every score is presented as its list of counted factors.

### 1.4 Order

- Plugins whose changelog says "compatibility with X" go **before** X itself.
- Low risk first, high risk last — a rollback is cheapest then.
- Never step to an intermediate version known to be broken; go straight to
  the newest.
- WordPress core is always the **last** unit: plugins that ship
  "compatibility with X" land before X itself, and everything you measured
  around the plugin units stays measured under the core you started with.
- WooCommerce, when its gate passes, is the last *plugin* unit, right before
  core: the extensions that declare compatibility with it go first.

### 1.5 Linked plugins — the group as the unit

Some plugins can't move independently: an add-on pinned to a base plugin, or
a pair enforcing each other's version. Update one alone and you leave the site
in a combination the vendor never tested. Recognise a group by:

- an add-on requiring a minimum/maximum version of another plugin
- a vendor shipping base + pro as one product (e.g. Polylang Pro + Polylang
  for WooCommerce, an invoicing base + Professional, an SEO free + Premium)
- a changelog saying "requires <other plugin> >= x.y"

**A group is one unit through the whole loop:** snapshot together, update
together, measure once, and on failure **roll back together**. Never halfway.
Score: the highest in the group (risk-model.md). Order within the group
follows the dependency: first the one the other sets a minimum on (usually
the base), then the add-on. Measure only after the last member.

> **Mind the in-between state.** Between two members the site briefly runs a
> mixed combination. If that is demonstrably safe (the add-on only sets a
> lower bound), fine. If you can't establish that, treat the group as elevated
> risk and schedule it in a quiet window.

### 1.6 The plan — the human gate

Show the user the plan: order, per-plugin score with counted factors, the
channel, the core unit (target, type, score with factors — or why there is
none), and what goes to the waitlist with reasons. **Wait for explicit
approval before changing anything.** This gate is never skipped, not even on
a repeat run.

---

## Phase 2 — Baseline

Assemble the probe set per [probes.md](probes.md): the core set (P1–P4, plus
P5–P8 when WooCommerce is active) plus any extra probes the changelogs call
for. Freeze the set, run the double baseline, apply the core-probe gate.

---

## Phase 3 — The update loop

Per unit, in the order from 1.4:

**1. Snapshot the files**

```bash
ssh <alias> "cp -a <wproot>/wp-content/plugins/<slug> <workdir>/<RUNID>/<slug>@<old-version>"
```

Verify the copy is complete (compare file counts). Never in the webroot.

**2. Update — two regimes, by channel (from 1.1)**

You must guarantee you install what you researched. A run easily takes an
hour and releases don't wait for it.

*Channel wp.org — pin:*

```bash
ssh <alias> "cd <wproot> && wp plugin update <slug> --version=<target> 2>/dev/null"
```

*Channel vendor — pinning impossible:*

`--version=` only works against wp.org. Used on a plugin with its own update
server, WP-CLI searches wordpress.org, finds nothing, and reports the
misleading `Error: No plugins installed.` — while the plugin is right there.
That is a structural limitation, not a mistake in your command. Instead:

```bash
# 1. re-read the target immediately before updating — keep the window in seconds
ssh <alias> "cd <wproot> && wp plugin list --name=<slug> --field=update_version 2>/dev/null"
# 2. differs from your researched target? STOP this plugin, back to phase 1
# 3. otherwise update unpinned
ssh <alias> "cd <wproot> && wp plugin update <slug> 2>/dev/null"
```

Save the **complete output** of the update command (stdout and stderr) in the
run directory — you need it in step 2b. Dry run: skip this step, do the rest.

**2b. Did the update actually land?**

Do not trust the exit code — `wp plugin update` regularly reports success
while nothing happened (an expired license often just prints "plugin is
already up to date"). Verify with P3 that the installed version is **exactly**
the researched target. If not, determine which failure it is — they demand
opposite actions:

| Kind | Recognition | Action |
|---|---|---|
| **A. Nothing happened** | old version still runs, plugin dir intact | do **not** roll back (nothing to roll back), waitlist, next unit |
| **B. Stranded halfway** | dir missing, empty, missing files, or plugin gone from the list | **roll back immediately** from the snapshot, then waitlist |

When unsure, compare the directory's file count against the snapshot:

```bash
ssh <alias> "ls -1 <wproot>/wp-content/plugins/<slug> 2>/dev/null | wc -l"
```

Far lower or zero ⇒ category B.

Common causes of category A — record which applies, because it decides who
fixes it: expired/missing **license** (search the saved output for `license`,
`subscription`, `expired`, `not entitled`, `authenticate`, `401`, `403`);
a missing or unlinked **updater/license manager** plugin; a **download error**
(404, timeout, vendor server down); **write permissions or disk space**.
Waitlist with the **raw output attached**, not just your conclusion. A license
problem is a commercial action, not a technical one — say so, or the same
plugin returns every run.

If a vendor-channel update landed on a *different* version than researched:
you installed an unresearched version — roll back and waitlist.

**Directory ≠ wp.org slug.** Some plugins were installed from a vendor zip
under one folder name while wp.org serves them under another (the transient's
`slug` differs from `dirname($file)`). WordPress then installs the update into
the *slug's* folder, deletes the old one, and the plugin silently deactivates
because `active_plugins` still points at the old path. Check before the loop:

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
foreach ( (array) ( get_site_transient( 'update_plugins' )->response ?? [] ) as $file => $d ) {
    $dir = dirname( $file );
    if ( ! empty( $d->slug ) && $d->slug !== $dir ) { echo "{$dir} != {$d->slug}\n"; }
}
PHP
```

A mismatch is a manual action (update, then activate the new path) — waitlist
it and say so; pinning by slug would create a second copy next to the first.

**3. Flush caches before measuring** — or you measure old code:

```bash
ssh <alias> "cd <wproot> && wp cache flush"
```

If an object cache or page cache runs (host-level too), purge those as well.

**4. Re-run the probe set** — exactly the frozen baseline set, after the
opcache settle time from 0.2b. A 500 measured inside that window is the
window, not the plugin: measure again after it before you classify.

**5. Classify differences**

| Type | Example | Action |
|---|---|---|
| **Hard** | status changes, new fatal, plugin deactivated, payment method gone, probe went from value → `unavailable` | roll back immediately |
| **Expected** | the changelog predicted this change | log as confirmation, continue |
| **Unexpected, not fatal** | price formatting changes, extra field name | do **not** roll back; waitlist for a human |

**6a. On hard failure — roll back**

```bash
ssh <alias> "rm -rf <wproot>/wp-content/plugins/<slug> && cp -a <workdir>/<RUNID>/<slug>@<old-version> <wproot>/wp-content/plugins/<slug>"
ssh <alias> "cd <wproot> && wp plugin activate <slug>; wp cache flush"
```

Then run the probe set **again** — after the settle time, the rollback is a
file swap too — and compare with the original baseline. If it doesn't
match, your rollback didn't work: **stop the entire run, call the human**. Waitlist the plugin with the observed diff as the reason.

**6b. On success** — write the history line and continue.

**7. Never** clean up the snapshot directory at the end. It stays ≥ 30 days
(hard rule 7).

After every unit, one line to `<workdir>/history.jsonl`:

```json
{"date":"...","site":"...","slug":"...","from":"...","to":"...","risk":4,
 "factors":["patch","security"],"probes":["P1","P2","P3","P4"],
 "result":"ok|rolled_back|flagged|update_failed","fail_reason":"license|updater|download|disk|null",
 "diffs":[...],"duration_s":12}
```

A dry run writes the same line with `"result":"dryrun"` — never `ok`, or the
next run reads a rehearsal as a real outcome.

`update_failed` is not `rolled_back`. Rolled back = the update landed but
broke something; update_failed = it never installed. Mixing them pollutes the
risk history: a plugin with a dead license isn't dangerous, it's unreachable.

**`from` and `to` are frozen at research time.** Carry the version you judged
in phase 1 unchanged into the log line. Never re-derive it from
`update_version` at write time — the transient may have refreshed meanwhile,
and you'd log a version that was never researched. The consequence is
treacherous: the next run compares its target against the log, sees no
difference, and wrongly concludes nothing shifted. Your memory then lies about
exactly the field your risk model depends on.

---

### The WooCommerce unit — same major, no database update, gated by the code

WooCommerce touches everything a shop earns with, and its releases often
carry database upgrades that a file rollback cannot undo. It enters the loop
only when three facts hold — read from the code and the site, never from a
changelog line alone:

1. **Same major.** First digit unchanged: `11.0.1 → 11.1.0` qualifies,
   `10.8.1 → 11.1.0` does not. A major is a human's job in a quiet window.
2. **No database update between installed and target.** WooCommerce
   declares its upgrade routines in `includes/class-wc-install.php`, in the
   `$db_updates` array, keyed by version. Download the target package to the
   workdir and compare that array with the installed file:

```bash
ssh <alias> "D=<workdir>/wc-gate && mkdir -p \$D && cd \$D && curl -sL -o wc.zip https://downloads.wordpress.org/plugin/woocommerce.<target>.zip && unzip -p wc.zip woocommerce/includes/class-wc-install.php > install-<target>.php && cp <wproot>/wp-content/plugins/woocommerce/includes/class-wc-install.php install-installed.php"
ssh <alias> "cd <workdir>/wc-gate && INSTALLED=<installed> TARGET=<target> php" < scripts/wc-db-updates-keys.php
```

   The script (shipped with this skill) reads both `$db_updates` arrays and
   prints the keys between installed and target, with their routine names.
   Keys can carry a suffix (`11.1.0-1`); the script accepts them — an
   earlier version did not and let one routine through unread.

   Exit 0 (no keys between installed and target) ⇒ continue. Exit 1 ⇒ the
   package carries routines for this jump. That is not yet a verdict: read
   what they do.
2b. **The routines are housekeeping, proven from their bodies.** Unpack the
   package in the workdir and run the scanner shipped with this skill
   (`scripts/wc-db-routines-scan.php`) over the routine names the key check
   printed:

```bash
ssh <alias> "cd <workdir>/wc-gate && mkdir -p pkg && unzip -q wc.zip -d pkg"
ssh <alias> "cd <workdir>/wc-gate && PKG=pkg/woocommerce ROUTINES=<name1>,<name2> php" < scripts/wc-db-routines-scan.php
```

   The scanner reads each routine's body from `includes/wc-update-functions.php`,
   follows one level of delegation into the package's own classes
   (`Class::method()`, `new Class()`), and blocks on anything that touches
   schema or business data: `dbDelta`, `CREATE|ALTER|DROP|RENAME|TRUNCATE
   TABLE`, raw `INSERT|UPDATE|DELETE`, `$wpdb->insert|update|replace|query|
   delete`, order or product stores, background scheduling, and post writes
   that are not guarded by WooCommerce's own email-template post type. What
   remains — transients, caches, options, and WooCommerce's generated
   email-template posts — is housekeeping a file rollback can live with.
   `VERDICT=ALLOW` ⇒ continue; `BLOCK` ⇒ waitlist as human work, quoting
   the scanner's line. A routine the scanner cannot read (delegation it
   cannot resolve) blocks too. The release post's "Database update:
   Yes/No" is context; the code is the authority.
3. **WordPress already meets `Requires at least`.** WordPress hides the
   update otherwise, so an offered update satisfies this.

With all three, WooCommerce is a plugin unit like any other — the **last
plugin unit, before core** — with three additions: a fresh dump right before
it (the run's dump predates the other units), P17 in the probe set, and,
when 2b allowed routines, an explicit `wp wc update` right after the file
update — the same principle as core's `update-db`: the upgrade runs under
you, not under the next visitor. After that, besides the plugin checks:

- P17: `WC_Install::needs_db_update()` must be `no` and `woocommerce_db_version`
  must equal the **last key** of `WC_Install::get_db_update_callbacks()` — not
  `WC()->version`, because the last key can be a suffixed one like
  `11.1.0-1`. If it still needs an update after `wp wc update`, a routine did
  not finish: roll back the files and waitlist with the `wp wc update`
  output.
- P5–P7 and P11 identical: prices, gateways, zones and the REST surface the
  integrations use.

Score with the plugin table: +3 critical path always (WooCommerce is the
critical path), plus age and the rest. A minor within the gate typically
lands at 4.

### The core unit — WordPress core, last

Same loop, different steps 1, 2, 2b and 6a. Skip the unit entirely when the
site config has `core_updates: false` or 1.1b offered nothing.

**0. Fresh dump and preconditions.** The run's dump predates every plugin
unit; core gets its own:

```bash
ssh <alias> "cd <wproot> && wp db export <workdir>/<RUNID>/db-before-core.sql --add-drop-table 2>/dev/null && gzip <workdir>/<RUNID>/db-before-core.sql && ls -lh <workdir>/<RUNID>/db-before-core.sql.gz"
ssh <alias> "cd <wproot> && wp core check-update --format=csv 2>/dev/null; wp core verify-checksums 2>&1 | tail -20"
```

All three must hold, else waitlist as blocked and skip the unit:

- the target from 1.1b is still the newest offered version (same binding
  rule as plugins — keep this window short)
- `verify-checksums` reports no `File doesn't verify against checksum`. A
  modified core file is either a hack or a deliberate patch; both need a
  human before you overwrite them — **stop and ask**. `File should not
  exist` (leftovers such as `.orig` files) is a warning: note it, continue.
- major auto-updates are off (0.2)

**1. Snapshot the core files** — everything the update touches, nothing else:

```bash
ssh <alias> "SNAP=<workdir>/<RUNID>/wordpress-core@<old-version> && mkdir -p \$SNAP && cd <wproot> && cp -a wp-admin wp-includes \$SNAP/ && cp -a index.php wp-*.php xmlrpc.php license.txt readme.html \$SNAP/ 2>/dev/null; rm -f \$SNAP/wp-config.php; echo snapshot=\$(find \$SNAP -type f | wc -l) live=\$(find wp-admin wp-includes -type f | wc -l)"
```

`wp-content` and `wp-config.php` are never in the snapshot: the update does
not touch them and a rollback must never overwrite them. The two counts
should be close (the live count lacks the root files); a snapshot far
smaller than the live tree is incomplete — stop.

**2. Update — pinned, in the site's package language:**

```bash
ssh <alias> "cd <wproot> && wp core update --version=<target> --locale=<package-language> 2>&1"
ssh <alias> "cd <wproot> && wp core update-db 2>&1; wp language core update 2>&1"
```

Save the complete output of all three in the run directory. `--version`
pins the researched target; `--locale` keeps the localized package (without
it WordPress downloads `en_US` over a localized install). `update-db` is
hard rule 6's one exception: run it explicitly, here, never deferred to
"the first admin visit" — that leaves a half-updated site that finishes
its upgrade under a visitor instead of under you. Dry run: skip all three.

**2b. Did it land?** — P9 and P10 from probes.md, plus the upgrade
directory:

```bash
ssh <alias> "cd <wproot> && ls -A wp-content/upgrade 2>/dev/null | wc -l"
```

- P9 version ≠ target ⇒ nothing landed, or a different version did: roll
  back (6a), waitlist with the saved output
- P9 code `db_version` ≠ option `db_version` ⇒ `update-db` did not finish:
  run it once more; still unequal ⇒ roll back the files, waitlist, **stop
  the run**
- P10 modified count > 0 ⇒ partial download or write failure: roll back
- `wp-content/upgrade` not empty ⇒ WordPress left its unpack directory
  behind (hard rule 2): remove its contents, note it

**3–5.** Flush caches (host cache too), re-run the frozen probe set including
P9–P11, classify with the plugin table. Expected diffs: P9's first field
and, after a feature release, its `db_version` fields. Nothing else is
expected.

**6a. Rollback — files only, from the snapshot:**

```bash
ssh <alias> "SNAP=<workdir>/<RUNID>/wordpress-core@<old-version> && cd <wproot> && rm -rf wp-admin wp-includes && cp -a \$SNAP/wp-admin \$SNAP/wp-includes . && cp -a \$SNAP/*.php \$SNAP/*.txt \$SNAP/*.html . && wp cache flush 2>/dev/null; wp core version 2>/dev/null; wp core verify-checksums 2>&1 | tail -3"
```

Fallback when the snapshot is unusable: `wp core update --version=<old>
--locale=<package-language> --force` — a fresh download from wordpress.org,
which needs the network and is exactly why the snapshot exists. Then the
full probe set against the baseline, as for plugins.

**The database stays where it is** (hard rule 5). After a rollback the
option `db_version` is higher than the old code expects. That is harmless:
upgrade routines only run for *lower* values, and the number is simply
reset by the next `wp core update-db` or admin load. Schema additions from
the newer version stay in place, unused. Say so in the report, so nobody
"repairs" it with a database restore.

**6b. On success** — history line with `"slug":"wordpress-core"`, then:

**7. Re-inventory the plugins.** A core update unlocks plugin updates that
were hidden behind a `Requires at least` — WordPress does not even list
them before. Re-run 1.1 and report what appeared. Those targets are
**unresearched**: a second pass through phase 1 with its own plan gate, or
the next run. Never fold them into this loop.

## Phase 4 — Close out

1. Final measurement of the full probe set; compare with the very first baseline.
2. Purge caches (host cache included).
3. Verify **nothing** was left in the webroot:
   ```bash
   ssh <alias> "find <wproot> -name '*.bak' -o -name '*.tmp' -o -name '*.new' -o -name '*.orig' | head"
   ```
4. Remove the lock.
5. Report how much disk `<workdir>` now uses.
6. First completed real run: set `first_run_done: true` in `sites.yaml`.

---

## Stop conditions — stop and call the human

- A core probe that returns empty/`null`/error at baseline (probes.md gate)
- A rollback that does not restore the baseline
- Two units rolled back in a row
- A fatal that persists after a rollback
- Less than 2 GB of disk space
- WooCommerce across a major, or with anything in its `$db_updates` list for
  the jump; anything else running a DB migration (core's own `update-db`
  inside the core unit excepted)
- Modified core files at `verify-checksums` before the core unit
- A core `update-db` that does not complete on the second attempt
- `<workdir>/<RUNID>/` already exists
- The site is unreachable and you don't know why
- You would need to do something not written in this procedure

When stopping: report exactly what you did, what you observed, what the
current state is, and what the human should check. No invented conclusions,
nothing creative.

---

## The report

Short and factual, in the user's language, written so it can be forwarded to
an end client as-is (hard rule 8: neutral professional language, no AI or
tool references):

- What was updated (from → to)
- What was skipped and why (waitlisted items with their counted factors;
  license blocks marked as commercial actions)
- What was rolled back and what was observed
- What a human should check — always include invoicing and transactional
  email for critical-path updates (the un-probeable blind spot)
- For a core unit: the version jump, `db_version` before and after, and the
  checks only a logged-in human can do — open the block editor on a page and
  a product, the media library, and on shops the orders screen and WooCommerce
  settings. Admin screens cannot be probed without a session.
- For dry runs: state clearly that nothing was changed

If during the run you missed a probe or a risk factor that the model should
have had, mention it to the user and invite them to open an issue on the
skill's GitHub repository — that is how this skill learns.

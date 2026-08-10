# Update run — the procedure

You are updating plugins on a **live production site**. One plugin at a time,
with a measurement before and after, and a rollback you verifiably check.
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
sitemap. For non-WooCommerce sites: homepage, key landing pages, one archive,
one post, any critical forms page, and the sitemap.

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

### 1.3 Score the risk

Apply [risk-model.md](risk-model.md). Every score is presented as its list of
counted factors.

### 1.4 Order

- Plugins whose changelog says "compatibility with X" go **before** X itself.
- Low risk first, high risk last — a rollback is cheapest then.
- Never step to an intermediate version known to be broken; go straight to
  the newest.

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
channel, and what goes to the waitlist with reasons. **Wait for explicit
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

**3. Flush caches before measuring** — or you measure old code:

```bash
ssh <alias> "cd <wproot> && wp cache flush"
```

If an object cache or page cache runs (host-level too), purge those as well.

**4. Re-run the probe set** — exactly the frozen baseline set.

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

Then run the probe set **again** and compare with the original baseline. If it
doesn't match, your rollback didn't work: **stop the entire run, call the
human**. Waitlist the plugin with the observed diff as the reason.

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
- WooCommerce itself, or anything else running a DB migration
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
- For dry runs: state clearly that nothing was changed

If during the run you missed a probe or a risk factor that the model should
have had, mention it to the user and invite them to open an issue on the
skill's GitHub repository — that is how this skill learns.

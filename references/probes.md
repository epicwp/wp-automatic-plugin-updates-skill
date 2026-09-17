# Probes — the measurements

A probe returns a **value**, never "ok". Comparison is mechanical: same value
before and after, or a diff to classify. Probes are strictly read-only —
a probe that writes anything is broken by definition.

## Rules

- **One probe measures one thing.** Never bundle two measurements: a bundled
  probe counts as passed while one half silently stopped measuring. Eight
  small probes, one of which visibly drops out, beat three big green ones.
- **Freeze the set at baseline.** After the update you run *exactly* the same
  set — no re-filtering. Otherwise the probe that should have raised the alarm
  is exactly the one that quietly disappears.
- **`unavailable` ≠ `null`.** A probe whose precondition isn't met (no
  WooCommerce → P5–P8) is `unavailable`: fine, outside the set. A probe that
  *could* measure but returned empty/`null`/error saw nothing — for a core
  probe that **stops the run** (see gate below).
- Flat, sortable output: sort lists, round numbers, strip timestamps.
  Otherwise you diff noise.
- Append `2>/dev/null` to every WP-CLI command whose output you parse —
  deprecation notices leak into output and pollute measurements.
- For PHP, always use `wp eval-file -` fed by a local heredoc — never inline
  `wp eval` (quoting through the SSH layer breaks on quotes and `$`):

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
// plain PHP, no escaping needed; the <?php tag is mandatory
PHP
```

## Double baseline

Run the full set **twice, ~30 seconds apart**, before any update. Anything
that differs between those two runs is unstable (stock, sessions, countdowns,
response time) — exclude those fields from comparison and note what you
excluded. This is the noise filter.

## Gate: a dropped core probe stops the run

If any core probe (P1–P8, preconditions met) returns empty/`null`/error at
baseline: **stop the run**, show the raw output, and fix it before starting
over. Never proceed with partial core coverage — that is silent degradation:
everything looks green because nothing was measured.

## The core set

P1–P4 always. P5–P8 only when WooCommerce is active (else `unavailable`).

### P1 `http_status` — per URL from the site profile

```bash
curl -s -o /dev/null -w '%{http_code} %{time_total}\n' -H 'Cache-Control: no-cache' "<URL>?cb=$(date +%s%N)"
curl -sI "<URL>?cb=$(date +%s%N)" | grep -iE 'x-kinsta-cache|x-cache|x-litespeed-cache|cf-cache-status'
```

Confirm you get a cache MISS/BYPASS. **A green check on a cached page is
worthless** — you'd be measuring the page from before the update.

`/wp-login.php` and `/wp-json/` are always in the profile: core renders both,
so they change with core, not with the theme.

### P2 `php_error_signatures` — compare *which* errors, not how many

Precondition: an accessible error log (`logpath` in the site config). Without
one — recorded during setup — P2 is `unavailable`; treat it as a known
coverage gap and mention it in the report, not as a dropped probe.

**Read the log PHP actually writes to.** With `WP_DEBUG_LOG` enabled, PHP
errors go to `wp-content/debug.log` and the host's error log holds only
nginx lines — a P2 on the host log then sees nothing while the site fatals.
Check `wp config get WP_DEBUG_LOG` at setup and at the start of a run; when
it is on, `logpath` must be `debug.log` (or read both). For `debug.log`
lines use `.*` instead of `[^"]*` in the signature grep: the message itself
may contain quotes (`Attempt to read property "x" on null`).

**Subtract your own noise.** P4 runs WordPress in admin context under
WP-CLI and logs its known fatal and warnings into the same log; host cache
purges (`wp kinsta cache purge`) log rate-limit lines in nginx's log. Those
signatures appear *because you measured*. List them at baseline and exclude
them, or every unit looks like it produced a new error. Periodic noise
(a cron hitting a snippet every 5 minutes) shows as the same signature at
fixed intervals — check the whole day's log before calling it a diff.

Counting lines gives false alarms whenever a crawler re-triggers an existing
warning. Normalise each line to a **signature** — file, line, message, without
timestamp/request-ID/IP — and compare the sets.

Baseline: remember the current line count.

```bash
ssh <alias> "wc -l < <logpath>"
```

After the update, extract signatures of everything new since that line:

```bash
ssh <alias> "tail -n +<BASELINE_LINES> <logpath> \
  | grep -aoE 'PHP (Fatal error|Parse error|Warning|Notice|Deprecated):[^\"]*' \
  | sed -E 's/(on line [0-9]+).*/\1/; s/\" while reading.*//; s/, client:.*//; s/, referer:.*//' \
  | sed -E 's/PHP message: //g' \
  | sort -u"
```

**Normalise strictly or the probe is worthless.** One nginx line often holds
several PHP messages, and only the last one carries the nginx tail (`client:`,
`server:`, `request:`, `host:`) — which contains IPs and URLs, so it differs
per visitor. Cutting at `on line <n>` does most of the work. Sanity check at
baseline: the signature set should be a handful of lines, not hundreds — if
it's hundreds, your normalisation is broken.

Interpretation:
- signature that wasn't there before ⇒ diff; `Fatal error`/`Parse error` ⇒ hard failure
- known signature occurring more often ⇒ not a diff, that's traffic volume
- signature that disappears ⇒ note it; usually a fix

### P3 `active_plugins`

```bash
ssh <alias> "cd <wproot> && wp plugin list --fields=name,status,version --format=json 2>/dev/null"
```

Catches silent deactivation and confirms the update landed on the expected
version.

### P4 `admin_context_load`

```bash
ssh <alias> "cd <wproot> && wp --context=admin plugin list --format=count; echo \"exit=\$?\"" 2>&1
```

Record the **full outcome** as the baseline value: exit code plus the first
error line (file, line, message). Compare literally afterwards.

> **Known limitation.** On WooCommerce sites this often fatals *before* any
> update, inside WooCommerce Admin (`react-admin/connect-existing-pages.php`) —
> that code expects a screen context that doesn't exist under WP-CLI. The
> browser admin works fine; it is not a site defect. As long as the fatal is
> **identical** to the baseline, nothing changed. A *different* fatal, a *new*
> fatal, or a changed exit code is an alarm. Be aware of the cost: the fatal
> aborts loading, so admin coverage stops at that point.

### P5 `wc_product_snapshot` (WooCommerce)

Product IDs come from the site profile:

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$ids = [ /* PRODUCT_IDS */ ];
foreach ( $ids as $id ) {
    $p = wc_get_product( $id );
    if ( ! $p ) { echo $id . "|null\n"; continue; }
    echo implode( '|', [
        $id,
        $p->get_price(),
        $p->get_stock_status(),
        $p->is_purchasable() ? 1 : 0,
        $p->is_in_stock() ? 1 : 0,
    ] ) . "\n";
}
PHP
```

### P6 `wc_payment_gateways` (WooCommerce)

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$enabled = [];
foreach ( WC()->payment_gateways->payment_gateways() as $key => $gw ) {
    if ( 'yes' === $gw->enabled ) { $enabled[] = $key; }
}
sort( $enabled );
echo implode( ',', $enabled ) . "\n";
PHP
```

### P7 `wc_shipping_zones` (WooCommerce)

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$zones = [];
foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
    $methods = [];
    foreach ( $zone['shipping_methods'] as $m ) {
        if ( 'yes' === $m->enabled ) { $methods[] = $m->id; }
    }
    sort( $methods );
    $zones[] = $zone['zone_name'] . ':' . implode( '+', $methods );
}
sort( $zones );
echo implode( ',', $zones ) . "\n";
PHP
```

### P8 `wc_checkout_fields` (WooCommerce)

```bash
curl -s -H 'Cache-Control: no-cache' "<CHECKOUT_URL>?cb=$(date +%s%N)" | grep -oE 'name="[a-z_]+"' | sort -u
```

> **P8 often drops out, and that should be visible.** Without a cart session
> many checkouts 302 or render no fields; P8 returns nothing and the run stops
> via the gate — deliberately, to expose a real coverage gap instead of hiding
> it behind P6/P7. When it happens, discuss it with the user. Usually the
> right answer is to remove P8 from the core set *for this site* and record it
> as a known gap: payment and shipping stay covered server-side by P6/P7, but
> plugins only visible in a real cart context (postcode checkers, delivery
> date pickers) are not.

## Extra probes from the changelog

When a changelog adds a specific risk (shipping costs, VAT, forms, feeds),
design an **additional** measurement — never a replacement for the core set.
Extra probes follow the same rules, but a dropped extra probe does not stop
the run: discard it, continue, and note it in the report.

## Core extras — when the run has a core unit

Added to the frozen set at baseline like any extra probe, but a dropped one
here **does** stop the run: they are the only measurement of the core unit
itself.

### P9 `core_version_db`

```bash
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
global $wp_version, $wp_db_version;
echo "{$wp_version}|{$wp_db_version}|" . get_option( 'db_version' ) . "\n";
PHP
```

Code version, the `db_version` the code expects, and the `db_version` the
database has. Baseline: the last two are equal. After the core unit: first
field equals the target (expected diff), the last two equal each other —
else `update-db` did not finish (update-run.md, core unit 2b).

### P10 `core_checksums`

```bash
ssh <alias> "cd <wproot> && wp core verify-checksums 2>&1 | grep -cE \"doesn't verify\"; wp core verify-checksums 2>&1 | grep -c 'should not exist'"
```

Two counts: modified core files, and extra files inside `wp-admin` or
`wp-includes`. Baseline must read `0` on the first line — else the core unit
is blocked (update-run.md). The second line is informational and must not
grow.

### P11 `rest_namespaces`

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Cache-Control: no-cache' "<URL>/wp-json/?cb=$(date +%s%N)"
ssh <alias> "cd <wproot> && wp eval-file - 2>/dev/null" <<'PHP'
<?php
$ns = rest_get_server()->get_namespaces();
sort( $ns );
echo count( $ns ) . ':' . implode( ',', $ns ) . "\n";
PHP
```

HTTP status of the REST root (external — bot protection may force this one
server-side, as with P1) and the sorted namespace list from inside
WordPress. A namespace that disappears is a plugin whose REST routes no
longer register: the integration-side counterpart of a deactivated plugin,
invisible to P1–P8 and exactly what order-fulfilment and feed integrations
break on.

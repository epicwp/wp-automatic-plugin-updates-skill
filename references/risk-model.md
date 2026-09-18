# Risk model — score from observable facts

Add up the factors. No gut feeling — only things you can point at. The score
is always the sum of named factors; never present a number without its
breakdown. That breakdown is what makes a decision explainable to a client.

## Factor table

| Factor | Points |
|---|---|
| Major version jump (first digit changes) | +3 |
| More than 3 versions skipped | +2 |
| Changelog mentions migration / database / schema / upgrade routine | +4 |
| Changelog mentions breaking / removed / deprecated / renamed | +3 |
| Plugin is on the critical path (see below) | +3 |
| Custom/own code hooks into this plugin (grep in wp-content) | +2 |
| No changelog to be found | +2 |
| Plugin appears in `history.jsonl` as previously failed | +4 |
| Target version released less than 7 days ago | +2 |
| Target version 7–30 days old | +1 |
| Security fix only, patch version | −2 |
| Changelog is solely "compatibility with X" or translations | −2 |

**Score > maxrisk (default 6), or any database migration, or WooCommerce
outside the conditions of its unit (update-run.md) ⇒ do not update.** Write to the server-side `waitlist.json`: slug,
current version, target version, score, the factors that counted, date, and
what a human should check.

## Release age

A version that has run for three months without complaints is demonstrably
safer than this morning's: thousands of sites have field-tested it and the
vendor has had time to ship a hotfix. With a same-day release, *you* are the
field test.

wp.org plugins:

```bash
curl -s "https://api.wordpress.org/plugins/info/1.0/<slug>.json" | grep -oE '"last_updated":"[^"]+"'
```

Premium plugins: the date next to the version in the vendor changelog. If you
can't determine it, say so and count no points — but note it; it is the same
uncertainty as a missing changelog.

**Interplay with the security discount:** a security patch released today
scores +2 and −2 — net zero, deliberately: the two arguments cancel out and
the remaining factors decide. **Exception:** for an actively exploited
vulnerability, patching fast beats waiting. That is a human trade-off, not a
sum — present it to the user instead of computing it.

## Critical path

1. Checkout and cart
2. Payment and payment gateways
3. VAT and tax calculation
4. Shipping, shipping methods, delivery options
5. Stock and price display
6. **Invoicing and documents** (invoices, packing slips, accounting links)
7. **Transactional email** (order confirmations, shipping notices, password resets)

Categories 6 and 7 are easy to forget because they're not on the buy button —
and just as expensive. With a broken invoice generator, orders keep flowing,
just without invoices, and nobody notices until bookkeeping. With silent email
breakage the customer never gets a confirmation and calls days later.

> **Double blind spot.** These same two categories also cannot be probed on
> production: invoices and order confirmations require a real order, and you
> never place one (hard rules 3 and 4). They are simultaneously the easiest to
> underrate and the hardest to catch. The score is the only protection —
> count that +3 for real, and put these plugins in the human follow-up
> checklist of the final report.

Custom code check:

```bash
ssh <alias> "cd <wproot>/wp-content && grep -rl '<plugin_prefix>' plugins/ themes/ --include='*.php' | grep -v '^plugins/<slug>/'"
```

## Linked groups score as one

For a linked group (see update-run.md §Linked plugins) the **highest** score
in the group counts for the whole group. One member above `maxrisk` puts the
entire group on the waitlist — updating the rest separately is precisely the
danger.

## Why changelogs alone aren't enough

Vendors understate. A real example: Forminator 1.55.1's changelog said no more
than "Fix: Security improvements" — it was an unauthenticated path traversal,
CVSS 7.5. Vague security wording on a critical-path plugin is a reason to
research externally (Patchstack, WPScan), not a reason to relax.

## WordPress core — its own table

Core is scored the same way — from facts — but the facts differ: there is no
changelog to clip, "tested up to" runs the other way (plugins declare
themselves against core), and the database upgrade is expected rather than
disqualifying. `maxrisk` applies unchanged.

| Factor | Points |
|---|---|
| Feature release (x.y changes; `update_type=major`) | +3 |
| More than one feature release skipped (6.8 → 7.1 skips 6.9 and 7.0) | +2 |
| An active **critical-path** plugin declares `Tested up to` **more than one feature release** below the target (target 7.1: declares 6.9 or lower; 7.0 is the normal one-release lag of a readme) (update-run.md 1.2b) | +2 (once; list them) |
| An own **drop-in** (`object-cache.php`, `db.php`, `advanced-cache.php`, `db-error.php`) — not the host's, not an installed plugin's; the file header says. Drop-ins replace core components; `mu-plugins` only use core's API like any plugin, so list them, count nothing | +2 |
| Core appears in `history.jsonl` as previously failed | +4 |
| Target released less than 7 days ago | +2 |
| Target 7–30 days old | +1 |
| Minor release whose release notes call it a security release | −2 |

Not scored — these **block** or **stop**, whatever the sum:

- no target offered that the site's PHP can run ⇒ waitlist, blocked: PHP (hosting action)
- `wp core verify-checksums` reports modified files ⇒ stop and ask
- `auto_update_core_major` enabled ⇒ the core unit waits until it is off
- installed version `insecure` per `api.wordpress.org/core/stable-check` ⇒ a security deadline: present it, don't sum it

The database upgrade (`wp core update-db`) is **not** the +4 migration
factor: it is core's normal path, idempotent, and the one migration hard
rule 6 permits. Its cost is a blind spot instead — nothing on the front end
shows a half-finished upgrade — which is why update-run.md verifies
`db_version` explicitly after the unit.

Typical: 6.9.7 → 7.1, two months after release, on a shop whose
translation plugin still declares 6.9 = +3 +2 = 5, allowed. The same jump in
the week 7.1 ships = 7, waitlist until it has aged.

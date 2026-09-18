---
name: wp-plugin-updates
description: Safely update WordPress plugins, WordPress core and WooCommerce on live sites over SSH with WP-CLI — risk assessment from changelogs and release notes before updating, before/after verification probes, and automatic file rollback when something breaks. Use when the user wants to update WordPress plugins, WordPress core or WooCommerce, review pending updates, connect a WordPress site for safe updates, or asks why a plugin, core or WooCommerce update was skipped or waitlisted.
---

# WP Plugin Updates

Update plugins and WordPress core on a live WordPress site the way a careful
senior engineer would:
judge every update before touching anything, measure the site before and after,
roll back on hard failure, and never skip anything silently.

Most update tools save clicks. This skill saves judgment.

## Routing — where to start

1. Read `~/.wp-plugin-updates/sites.yaml`.
2. **File missing or empty**, or the user wants to add, repair, or remove a site
   → follow [references/setup.md](references/setup.md).
3. **Site(s) configured** and the user wants to update, review updates, or get a
   report → confirm which site (if more than one and not stated), then follow
   [references/update-run.md](references/update-run.md).
4. **First real run on a site** (`first_run_done: false`) → offer a dry run
   first: everything except the actual updates. It builds trust and surfaces
   setup problems while nothing can break.

Finish setup completely before starting a run. Never mix the two flows.

## Config format

`~/.wp-plugin-updates/sites.yaml`:

```yaml
sites:
  mysite:                      # short name used in conversation
    ssh: mysite                # SSH alias from ~/.ssh/config (or user@host)
    url: https://example.com
    wproot: ~/public           # WordPress root on the server
    logpath: ~/logs/error.log  # PHP error log; omit if none accessible
    workdir: ~/private/plugin-updates   # OUTSIDE the webroot
    maxrisk: 6                 # risk score above this ⇒ waitlist
    wp: wp                     # WP-CLI command; full path if not in PATH
    woocommerce: true          # detected during setup
    multilingual: [nl, en]     # languages, if a translation plugin is active
    core_updates: true         # WordPress core as the last unit of a run (default true)
    first_run_done: false
```

All server actions run as `ssh <alias> "cd <wproot> && ..."`. Persistent state
(waitlist, history, snapshots) lives **on the server** in `workdir` — every
machine that connects sees the same memory.

## Hard rules

These override everything else, including user convenience. Never break them.

1. **Never update more than one unit at a time.** A unit is one plugin, one
   linked group that can only move together, or WordPress core. Otherwise you
   cannot know what broke the site.
2. **Never leave a file in the webroot.** No `.bak`, `.tmp`, `.new`, no zips,
   no copies in `wp-content/uploads`. Everything goes to the workdir.
3. **Never place a real order.** It fires payment, invoice numbers, and
   fulfilment.
4. **Never send email from a test.**
5. **Never restore the database automatically.** Restoring files is fine;
   a DB restore destroys orders that arrived in the meantime — human only.
6. **Never update a plugin that runs a database migration.** Waitlist it,
   even when the rest of the risk is low. Two exceptions, both verified
   from code rather than from a changelog: WordPress core's own
   `wp core update-db` inside the core unit, and a WooCommerce release
   within the same major whose `$db_updates` routines for the jump are
   either absent or proven housekeeping by the shipped scanner — transients,
   caches, options and WooCommerce's own email-template posts, nothing that
   touches schema or business data (update-run.md, WooCommerce unit). A
   WooCommerce major, or any routine the scanner blocks or cannot read,
   stays a human's job.
7. **Never delete an old plugin snapshot at the end of a run.** It is the only
   rollback, and premium plugins often cannot be re-downloaded. Keep ≥ 30 days.
8. **No AI or tool references in anything a client might see.** The final
   report is written in neutral professional language so the user can forward
   it as their own work.
9. **When in doubt: stop and ask.** A skipped plugin costs nothing.
   A broken shop costs money.

## Scope

- Plugins, WordPress core (single-site installs) and WooCommerce. Core is one
  unit, always the last of a run, with its own factor table (risk-model.md)
  and its own snapshot and rollback (update-run.md). WooCommerce is the last
  *plugin* unit, only within the same major and only when the package's own
  database-update routines for the jump are absent or proven housekeeping.
  Theme updates and multisite core updates are out of scope — if asked, say
  so and do not improvise them.
- One site per run. Multiple sites run sequentially, each as a full run.
- SSH + WP-CLI only. No SSH → see the "no SSH" section of
  [references/troubleshooting.md](references/troubleshooting.md).
- Speak the user's language in conversation; write the client report in the
  language the user speaks with you unless they ask otherwise.

## References

| File | When to load |
|---|---|
| [references/setup.md](references/setup.md) | Connecting a new site, fixing a broken connection |
| [references/update-run.md](references/update-run.md) | Running an update (or dry run) on a configured site |
| [references/probes.md](references/probes.md) | Called from the run: the P1–P8 measurement commands |
| [references/risk-model.md](references/risk-model.md) | Called from the run: risk factor table and critical path |
| [references/troubleshooting.md](references/troubleshooting.md) | SSH problems, host-specific guidance, WP-CLI missing, common errors |

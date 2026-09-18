# Troubleshooting — SSH, hosts, and common errors

## Finding SSH access per host

Panel layouts change; treat these as pointers, and fall back to searching the
host's help center for "SSH". Almost every managed WordPress host has SSH —
most users have simply never opened that page.

| Host | Where to look |
|---|---|
| **Kinsta** | MyKinsta → Sites → (site) → Info: the full SSH command with host, user and port is shown there. SSH keys are added under User Settings. |
| **SiteGround** | Site Tools → Devs → SSH Keys Manager: generate or import a key there, then use the shown user/host with **port 18765**. |
| **Cloudways** | Server → Master Credentials for SSH user/host; add your public key under SSH Public Keys. Application-level credentials also work. |
| **WP Engine** | Add your public key in the User Portal (SSH keys). Then `ssh <env>@<env>.ssh.wpengine.net`. |
| **cPanel hosts** (many brands) | cPanel → Security → SSH Access → Manage SSH Keys. Some shared hosts require enabling shell access first or asking support. |
| **Plesk hosts** | Subscription → Web Hosting Access → set shell access to `/bin/bash`. |
| **Hostinger** | hPanel → Advanced → SSH Access. |
| **Anything else** | Search "<host name> SSH access" in the host's help docs, or ask their support to enable SSH. It is a normal request. |

If the host genuinely offers no SSH on the user's plan, stop honestly: the
site can't be connected yet. Upgrading the plan or moving the site are the
user's decisions, not yours to push. Ask them to open a GitHub issue naming
the host — that data shapes what gets built next.

## SSH connection failures

Test with verbose output when the plain test fails:

```bash
ssh -o BatchMode=yes -o ConnectTimeout=10 -v <alias> 'echo CONNECTED' 2>&1 | tail -20
```

- `Permission denied (publickey)` — the public key isn't (yet) on the server,
  or the panel needs a few minutes to sync keys. Verify the right key file is
  referenced in `~/.ssh/config`.
- `Connection timed out` — wrong port (check the panel; e.g. SiteGround uses
  18765) or a firewall that whitelists IPs (some hosts require adding your IP
  in the panel).
- `Host key verification failed` — first connection needs interactive
  confirmation. Ask the user to run the plain `ssh <alias>` once themselves
  and answer `yes`. Suggest they type `! ssh <alias> echo ok` if their agent
  supports running interactive commands.
- Works interactively but not in scripts — `BatchMode=yes` disables password
  prompts by design; key auth isn't set up yet. Finish key setup first.

## WP-CLI issues

- `wp: command not found` — install per setup.md §4 (phar in `~/bin`), store
  the full path as `wp:` in the site config.
- `Error: This does not seem to be a WordPress installation.` — wrong
  `wproot`; re-run setup.md §3.
- PHP version errors when running `wp` — some hosts route CLI PHP to an old
  binary. Try `php8.2 ~/bin/wp ...` variants or check the panel's CLI PHP
  setting; store the working invocation in `wp:`.
- Deprecation notices polluting output — you forgot `2>/dev/null`.

## Quoting through SSH

Inline `wp eval '...'` breaks on quotes and `$` through the SSH layer. Always
use the heredoc pattern from probes.md: `wp eval-file -` fed via stdin, with
the quoted `<<'PHP'` marker so the local shell substitutes nothing, and the
mandatory `<?php` opening tag (eval-file does an include).

## Misleading errors seen in practice

- `Error: No plugins installed.` from `wp plugin update <slug> --version=...`
  — the plugin updates via a vendor server; `--version=` only queries wp.org.
  Use the vendor-channel procedure in update-run.md phase 3 step 2.
- `wp plugin update` exits 0, prints "already up to date", nothing changed —
  usually an expired license. See update-run.md step 2b, category A.
- `wp --context=admin` fatals inside WooCommerce Admin on a healthy site —
  known limitation; only a *changed* outcome versus baseline is an alarm
  (probes.md P4).
- A probe suddenly all-green after an update that should have changed output —
  check you're not measuring a cached page (P1's MISS/BYPASS check) and that
  caches were flushed before measuring.

## Core update issues

- `wp core check-update` shows nothing while wordpress.org has a newer
  release — WordPress filters offers by the site's PHP version. Compare
  `https://api.wordpress.org/core/version-check/1.7/?php=<site php>` with
  the same URL without `php=`: if the release only appears without the
  filter, raising PHP is a hosting action, not a technical one. Also clear
  `wp transient delete update_core` first — the check is cached 12 hours.
- `Error: Update failed: Could not copy file.` / `Could not create
  directory.` — permissions or disk. Run `wp core verify-checksums` before
  deciding whether anything partial landed.
- Localized package 404 (`wordpress-<ver>-<locale>.zip`) right after a
  release — language packages trail `en_US` by hours. Wait; do not fall back
  to `en_US`, that swaps the package language of the install.
- `Warning: File doesn't verify against checksum` **before** the core unit —
  a modified core file; stop and ask (update-run.md, core preconditions).
  `File should not exist` is leftovers, not a modification.
- `wp core update-db` prints `Success: WordPress database already at latest
  revision` — normal after a minor release; after a feature release compare
  P9's two `db_version` fields anyway.

## Seen in the field

- **All pages 500 for ~30 s right after an update, and again after the
  rollback** — the opcache window (update-run.md 0.2b). `debug.log` shows
  `Class "..." not found` in the updated plugin's main file. Wait the settle
  time, measure again; the plugin is only at fault if the 500 outlives the
  window.
- `limiting requests, excess: ... by zone "purgeAll"` in nginx's error log
  after `wp kinsta cache purge --all` — Kinsta rate-limits its own purge
  endpoint; the run causes these lines, they are not a site error.
- `wp plugin update <slug> --version=X` downloads the *unversioned* zip when
  X is the current latest (`<slug>.zip` instead of `<slug>.X.zip`). P3 still
  has to confirm the installed version equals X.
- A plugin that silently deactivates on update — directory name differs from
  the wp.org slug (update-run.md, step 2b). Manual update plus re-activation.
- `wp core update` prints HTML (`<p>`, `<div class="update-messages">`, a
  `<script>` block) — that is the language-pack updater WordPress runs
  inside the core upgrade, also for plugins. Harmless; the lines that count
  are `Success: WordPress updated successfully.` and, from `update-db`,
  `database upgraded successfully from db version X to Y`.

- **P2 line count dropped below the baseline** — the host rotated the log
  overnight. The baseline signatures are gone with it; take a fresh
  reference measurement before the next unit instead of diffing against a
  file that no longer exists in that form.
- **A run that spans a night** — re-read the update list before continuing:
  targets shift (a plugin released a new version overnight) and WordPress
  may have moved itself to a minor release through its own channel. Both
  are unresearched from the plan's point of view: re-assess the shifted
  unit, and treat the new core version as the target.

- WooCommerce's release post says "Database update: Yes" for a version
  that looks like a minor — believe it. 11.1.0 carried one while 11.0.1 did
  not; the version number tells you nothing about the database. The gate in
  update-run.md reads the package instead.

## Workdir problems

- Cannot create a directory outside the webroot — see setup.md §6: without
  one, this host isn't supported yet; don't improvise webroot storage.
- Host malware scanner quarantined snapshots — snapshots use predictable
  directory names inside the workdir (outside the webroot) precisely to avoid
  this; if it still happens, report which host/scanner in a GitHub issue.

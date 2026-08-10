# Setup — connecting a site

Goal: a working entry in `~/.wp-plugin-updates/sites.yaml` plus a verified,
read-only baseline of the site. Nothing in this flow modifies the site.

Work conversationally, one site at a time. The user may know nothing about
SSH — that is normal and fine. Your job is to get them there step by step,
not to assume knowledge.

## 1. Gather the basics

Ask (in one short message) for:

- The site URL.
- Who hosts it (Kinsta, SiteGround, Cloudways, WP Engine, cPanel-based, …).
  If they don't know: the invoice or welcome email from their hosting company
  says; so does `curl -sI <url> | grep -iE 'server|x-powered|via|x-kinsta|x-cache'`.
- Whether they already use SSH for this site ("do you type `ssh something`
  in a terminal for this site, or use an app like Termius?").

## 2. Establish SSH access

Try the cheapest path first:

**a. Existing alias.** If they name one, test it:

```bash
ssh -o BatchMode=yes -o ConnectTimeout=10 <alias> 'echo CONNECTED'
```

Also check `~/.ssh/config` for likely entries (`grep -iE '^Host ' ~/.ssh/config`).

**b. Credentials but no alias.** If they have user/host/port (from their
hosting panel — see [troubleshooting.md](troubleshooting.md) for where each
host hides them), set up key auth:

1. Check for an existing key: `ls ~/.ssh/id_*.pub`. Only if none exists,
   generate one — never overwrite: `ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519`.
2. Get the public key into the host: most panels have an "SSH keys" screen to
   paste `~/.ssh/id_ed25519.pub` into; otherwise `ssh-copy-id` if password
   login works.
3. Append an alias block to `~/.ssh/config` (create the file with mode 600 if
   missing), using the site's short name:

```
Host <sitename>
    HostName <ssh host>
    User <ssh user>
    Port <port>
    IdentityFile ~/.ssh/id_ed25519
```

4. Test as in (a). On failure, go to troubleshooting.md — do not loop blindly.

**c. No SSH at all.** Walk them through their hosting panel using the
host-specific pointers in troubleshooting.md. Most managed WordPress hosts
have SSH; users often simply never opened that panel page. If the host truly
offers no SSH, stop honestly: this site cannot be connected yet — point them
to the README's "My host has no SSH" answer.

## 3. Find the WordPress root

```bash
ssh <alias> 'find ~ -maxdepth 4 -name wp-load.php -not -path "*/wp-content/*" 2>/dev/null'
```

`wp-load.php` sits in the WordPress root (unlike `wp-config.php`, which may
live one level up). One result → that directory is `wproot`. Multiple results
(staging copies, backups) → show them with their URLs (`wp option get home`)
and let the user pick. Confirm:

```bash
ssh <alias> 'cd <wproot> && php -r "echo PHP_VERSION;" && ls wp-content/plugins | head -3'
```

## 4. Verify WP-CLI

```bash
ssh <alias> 'cd <wproot> && wp core version 2>/dev/null || echo NO_WPCLI'
```

If `NO_WPCLI`, install it in the user's home (no root needed):

```bash
ssh <alias> 'curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && mkdir -p ~/bin && mv wp-cli.phar ~/bin/wp && chmod +x ~/bin/wp && ~/bin/wp --version'
```

If `~/bin` is not on the non-interactive PATH, store `wp: ~/bin/wp` in the
site config and use that everywhere. Re-test with the stored command.

## 5. Find the PHP error log

Try, in order: the host's documented location (troubleshooting.md), then:

```bash
ssh <alias> 'for f in ~/logs/error.log ~/logs/*error*.log <wproot>/wp-content/debug.log; do [ -f "$f" ] && echo "$f"; done 2>/dev/null'
```

Verify it actually receives PHP messages (`tail -3` should show recognisable
PHP/nginx lines, possibly old — that's fine). No log found → omit `logpath`
from the config and tell the user plainly: probe P2 (new PHP errors) will be
unavailable for this site, which weakens verification. Record it; don't hide it.

## 6. Choose the workdir

Must be **outside the webroot** (snapshots and dumps must never be
web-reachable, and host malware scanners quarantine stray zips in webroots):

```bash
ssh <alias> 'mkdir -p ~/private/plugin-updates && echo OK'
```

If `~/private` fails, try another home-level directory (`~/plugin-updates`).
If the account cannot write anywhere outside the webroot, **stop**: this
hosting setup is not supported yet. Ask the user to open a GitHub issue with
the host's name rather than improvising a webroot location.

## 7. Read-only baseline check

```bash
ssh <alias> 'cd <wproot> && wp core version && wp plugin list --format=count 2>/dev/null && wp option get home && df -h . | tail -1'
ssh <alias> 'cd <wproot> && wp plugin list --status=active --field=name 2>/dev/null | grep -iE "woocommerce|polylang|wpml|translatepress|weglot"'
```

Record: WordPress version, plugin count, free disk space, whether WooCommerce
is active, which translation plugin (if any) with its languages
(`wp pll languages` / `wp option get WPML` vary — just note the plugin and ask
the user which languages the site serves). Flag now, not during a run:

- Free disk < 5 GB → warn; runs need room for snapshots and a DB dump.
- `wp option get home` should match the URL from step 1. Mismatch → staging or
  wrong install; resolve before saving.

## 8. Save and confirm

Create `~/.wp-plugin-updates/` if needed and write the entry per the format in
SKILL.md (`first_run_done: false`, `maxrisk: 6` default). Show the user what
was saved, then offer the next step:

> "Site connected. Want me to do a **dry run** now? It walks the entire update
> process — risk scores, plan, measurements — but changes nothing."

# WP Plugin Updates — an agent skill

Safely update WordPress plugins, WordPress core and WooCommerce from the AI
coding agent you already use —
Claude Code, Cowork, Cursor, Codex, or anything that supports agent skills.

No SaaS. No plugin to install on your site. No account. Just a skill that
teaches your agent to update plugins the way a careful senior engineer would:
**judge every update before touching anything, measure the site before and
after, roll back when something breaks, and never skip anything silently.**

## Why this exists

This skill wasn't designed as a product. It grew out of maintaining real
production WooCommerce shops — the kind where a broken checkout costs real
money by the hour. Like most people responsible for sites like that, we kept
postponing plugin updates. Not out of laziness: out of a justified fear that
update #14 of 16 would take the checkout down.

The existing answers all fall short in the same way:

- **Auto-updates are blind.** WordPress core's built-in rollback checks the
  homepage only — a checkout, a payment gateway, or an invoice generator can
  die silently while the homepage smiles back. Host-level auto-updaters are
  a second system fighting whoever else touches the site.
- **Changelogs understate.** A real example: a form plugin's changelog said
  "Fix: Security improvements." What it fixed was an unauthenticated path
  traversal, CVSS 7.5. The one-line changelog is where vendors hide the news.
- **Update dashboards save clicks, not judgment.** They'll bulk-update fifty
  plugins in one click, but they don't read the changelog, don't know that
  your theme hooks into that plugin, don't notice that the target version
  shipped four hours ago and nobody has field-tested it yet.

So the real work — reading sixteen changelogs, checking version jumps,
spotting database migrations, remembering which plugin failed last time —
stays manual. And nobody sustains that discipline by hand. It's exactly the
kind of work a language-model agent is better at than a human: it reads
*every* changelog, *every* time, scores risk from countable facts instead of
gut feeling, digs into CVE databases when the wording smells wrong, and never
gets bored on plugin fifteen.

But judgment alone isn't enough — you also have to check whether the update
actually worked. So this skill measures: HTTP status on the pages that
matter, new PHP errors by signature, active plugins, payment gateways,
shipping zones, product prices, checkout fields — before *and* after every
single plugin. A snapshot is taken first, and on a hard failure the files are
rolled back and the measurements re-verified. Whatever can't be updated
safely is *waitlisted with its reasons* — never silently skipped.

The procedure behind this skill has run on live production WooCommerce shops:
seven shops in two days, 80 units including five WordPress core updates
(6.9 → 7.1 and 7.0 → 7.1.1) — and it caught things a human wouldn't have: four target versions shifted *during* the run window, one "up to date"
success message that actually meant "your license expired, nothing was
installed", one update whose 500s were the host's opcache window rather than
the plugin, and a WooCommerce "minor" whose package carried three database
routines the version number never hinted at.

Tools that update your plugins save you clicks. This saves you the thinking.

## What it does

1. **Connect** — a guided, conversational setup per site: SSH access (the
   skill helps you find and configure it, even if you've never used SSH),
   WP-CLI check, error log, a safe working directory outside the webroot.
2. **Judge** — fresh update list, changelog research (local first, CVE
   databases when needed), and a risk score per plugin built from named,
   countable factors. High risk, database migrations, and dead licenses go
   to a waitlist — with reasons.
3. **Ask** — you get the plan: order, scores, factors, waitlist. Nothing
   happens until you approve. There is no fully-unattended mode, by design.
4. **Measure** — a frozen set of probes runs twice as a baseline: page
   statuses, PHP error signatures, plugin states, and on WooCommerce shops
   also products, payment gateways, shipping zones, and checkout fields.
5. **Update** — one plugin at a time: snapshot, update (pinned to the exact
   researched version where possible), cache flush, re-measure, compare.
   Hard failure → automatic file rollback, re-verified against the baseline.
   WordPress core, when a release is offered, is the last unit of the run
   under the same discipline: snapshot of the core files, pinned download
   from wordpress.org in the site's language, explicit database upgrade,
   re-measure, file rollback on hard failure. WooCommerce joins the loop as
   the last plugin unit only within the same major and only when the
   package's own database-update list is empty for the jump — a fact read
   from the downloaded code, not from the changelog. Anything else on
   WooCommerce stays a human's job, and the skill says so with the reason.
6. **Report** — what changed, what was skipped and why, what a human should
   still check. Written in neutral professional language you can forward to
   a client as-is.

Between runs, the site remembers: waitlist, history, and snapshots live on
your server, so every machine (and every agent) you connect from sees the
same state.

## What you need

- An agent that supports skills: Claude Code, Cowork, Cursor, Codex CLI, or
  any tool that reads `SKILL.md` / `AGENTS.md`.
- SSH access to your site. Most hosts have it, even if you've never used it —
  the skill walks you through finding it for Kinsta, SiteGround, Cloudways,
  WP Engine, cPanel/Plesk hosts, and more.
- WP-CLI on the server (the skill installs it in your home directory if missing).
- Your own agent subscription/API usage — the skill is free; the tokens are yours.

## Install

**Claude Code** (personal skill, available in every project):

```bash
git clone https://github.com/epicwp/wp-automatic-plugin-updates-skill.git ~/.claude/skills/wp-plugin-updates
```

**Codex CLI:**

```bash
git clone https://github.com/epicwp/wp-automatic-plugin-updates-skill.git ~/.codex/skills/wp-plugin-updates
```

**Cursor:**

```bash
git clone https://github.com/epicwp/wp-automatic-plugin-updates-skill.git ~/.cursor/skills/wp-plugin-updates
```

**Cowork:** download this repo as a ZIP (green "Code" button → Download ZIP),
then in Cowork: Customize → **+** → Skills → upload the ZIP. Note: Cowork
runs in its own sandbox — if the SSH connection test fails there, use Claude
Code instead.

**Any other agent** (workspace mode — no skill support needed):

```bash
git clone https://github.com/epicwp/wp-automatic-plugin-updates-skill.git
cd wp-automatic-plugin-updates-skill
# start your agent here — AGENTS.md/CLAUDE.md route it to the skill
```

## First use

Start your agent and say something like:

> "Connect my WordPress site for safe plugin updates."

The setup is a conversation, not a form. Afterwards:

> "Update the plugins on mysite."

The first run on a site is offered as a **dry run**: the full process — risk
scores, plan, baseline measurements — without changing anything. Do that
once; it builds trust and catches setup issues while nothing can break.

## Safety model

The skill operates under hard rules the agent may never break, including:

- Never more than **one plugin at a time** (or one linked group that must
  move together) — otherwise you can't know what broke the site.
- **Nothing is ever left in the webroot** — snapshots and dumps live outside it.
- **Never a real order, never test email** — nothing that fires payments,
  invoices, or messages to customers.
- **The database is never restored automatically.** Files yes; a DB restore
  destroys orders that arrived in the meantime and always requires a human.
- Plugins that run **database migrations are never auto-updated** — waitlisted.
  The only migration the skill ever runs is WordPress core's own database
  upgrade, inside the core unit, after a fresh dump. WooCommerce is updated
  only when its own `$db_updates` list proves the jump carries none.
- Snapshots are kept **at least 30 days** — premium plugins often can't be
  re-downloaded.
- **When in doubt, it stops and asks.** A skipped plugin costs nothing;
  a broken shop costs money.

A note on privacy: everything runs over *your* SSH connection from *your*
machine. There is no third-party service in between. Whatever your agent
reads (plugin lists, changelogs, probe output) is processed by your own
agent's model provider, under your existing agreement with them.

## What it deliberately does not do

- **Theme updates** — out of scope for now; on the roadmap.
- **WooCommerce majors, or any WooCommerce release with a database update** —
  deliberately manual. The skill tells you which upgrade routines the
  package carries so you can plan the window.
- **Multisite core updates** — out of scope; treat multisite as unsupported.
- **Unattended operation** — the plan-approval gate is the product, not a
  limitation.
- **Database rollbacks** — by design (see safety model).
- **Sites without SSH** — not yet. Open an issue naming your host; if enough
  people are stuck on the same one, that shapes what gets built next.

## FAQ

**Is this safe to run on production?**
It was built *on* production, for production. Judgment gate before, frozen
measurements before/after, snapshot + verified rollback, and hard stop
conditions. Start with the dry run and read the plan it shows you. And keep
your regular backups running — this complements them, it doesn't replace them.

**Does it handle premium plugins?**
Yes. It detects the update channel per plugin, knows vendor channels can't be
version-pinned (and re-verifies the target right before updating), flags dead
licenses as commercial actions instead of retrying them forever, and keeps
vendor snapshots — often your only copy of that version.

**Why does it need SSH? My host has a one-click updater.**
The one-click updater is exactly the "clicks, not judgment" problem this
exists to solve. SSH + WP-CLI is what makes snapshots, targeted rollbacks,
error-log reading, and server-side measurement possible.

**Can I use it for client sites?**
Yes — that's its natural habitat. The final report is written in neutral
professional language, so you can forward it under your own name.

**Multisite?**
Untested. Treat it as unsupported for now.

**What does a run cost?**
The skill is free (MIT). A run uses your own agent's tokens — changelog
research on a site with 15–25 pending updates is the bulk of it.

## Contributing

The risk model and the probe set get better through field reports. If a run
missed a risk factor, needed a probe that didn't exist, or hit a host where
setup failed — [open an issue](https://github.com/epicwp/wp-automatic-plugin-updates-skill/issues).
PRs welcome, especially host-specific setup guidance and new probes.

## License

MIT — see [LICENSE](LICENSE). Built by [EpicWP](https://github.com/epicwp)
from a battle-tested internal procedure.

**Disclaimer:** you run this on your own sites at your own risk. It is
designed to be careful, but no update process is risk-free. Keep backups.

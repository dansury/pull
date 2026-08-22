# pull.php

**Auto-deploy from GitHub to shared hosting — in a single PHP file.**

[Русская версия](README.md)

`pull.php` downloads a ZIP archive of the chosen branch, unpacks it and copies
the contents of the folder you pick into the directory the script itself lives
in. No git, no SSH, no shell access on the server — just PHP and plain outbound
HTTPS.

---

## Why this exists

### The problem

The code lives on GitHub, the site lives on cheap shared hosting where the only
tools are FTP and a control panel. Moving files between the two by hand breaks
in predictable ways:

- some files don't get uploaded — production runs half a feature;
- an older version gets uploaded over a newer one — and there's nothing to roll
  back to;
- nobody knows what is actually on the server: production matches no commit;
- files deleted from the repo stay on the server for years — dead scripts,
  forgotten backups, junk that is sometimes still reachable by direct URL;
- only the person with a configured FTP client can update the site.

Proper CI/CD solves this, but wiring up GitHub Actions with FTP deploy, storing
secrets and debugging a pipeline costs more than a landing page is worth. And
`git pull` over SSH isn't an option: this kind of hosting has neither SSH nor
git.

### The solution

One file, dropped into the site directory, that deploys when you open a link.
The server's state becomes a copy of the GitHub branch — with mirror mode on,
an exact copy, with no accumulated leftovers.

### Who it's for

- **Freelancers and web studios** shipping sites to a client's shared hosting
  without SSH.
- **Small teams** running landing pages, brochure sites, static content and
  simple PHP sites, where full CI is overkill.
- **A site owner or content manager** — updating the site becomes "open a link,
  press a button", with no git and no FTP client.
- **Agencies handing a project over**: the client keeps the hosting, the source
  stays on GitHub, this file connects them.

### What you get

| | Before | After |
|---|---|---|
| Deploy | FTP client, by hand, file by file | open a URL or one cron line |
| Source of truth | "something is on the server" | a branch on GitHub |
| Leftovers from old versions | pile up for years | removed automatically (mirror mode) |
| Who can deploy | whoever has FTP configured | whoever has the `pull.php` password |
| Infrastructure | — | none: no runner, no composer, no dependencies |
| Setup | — | a browser form on first open |

### When not to use it

Honest limits:

- **You need a build step** (`npm run build`, asset compilation) — the script
  deploys the repo as is. Options: commit the built output, or build in CI and
  push the artifacts to a separate branch and point `pull.php` at that branch.
- **A large repo or frequent deploys** — every run downloads the whole branch
  archive; there is no incremental transfer.
- **You need atomic deploys, rollback, DB migrations, zero downtime** — during
  the copy the site is briefly in an in-between state. Use Deployer,
  Capistrano or real CI for that.
- **The host has SSH and git** — then `git pull` is simpler, faster and more
  honest.

---

## How it works

1. Downloads the branch archive (three sources are tried in order):
   `api.github.com/repos/…/zipball/…`, `codeload.github.com`,
   `github.com/…/archive/refs/heads/….zip`.
2. Unpacks it into a temp directory (`sys_get_temp_dir()`).
3. Locates the configured subdirectory (`subdir`) inside the archive.
4. Copies its contents over the script's directory, skipping the preserve list.
5. If mirror mode is on, deletes everything in the directory that is no longer
   in the repository.
6. Cleans up temp files and prints a summary banner: status, start and end time,
   duration, files copied and files deleted.

## Requirements

- PHP 7.4+
- the `ZipArchive` extension
- cURL, or `allow_url_fopen = On`
- outbound HTTPS to `github.com` / `api.github.com` / `codeload.github.com`
- write access to the deploy directory

## Installation

1. Upload `pull.php` into the site directory you want to keep updated (the
   web root or `public_html/`, for example).
2. Open `https://your-site/pull.php` in a browser.
3. On the first run (no `pull-config.php` next to it yet) a setup form appears —
   fill it in and press "save_config".
4. The script writes `pull-config.php` (mode `0600`) and offers to run the first
   deploy right away.

From then on, every visit to `pull.php` performs a deploy.

## Setup fields

| Field | Meaning | Example |
|---|---|---|
| `repo` | repository as `owner/name` | `dansury/pull` |
| `branch` | branch to pull from | `main` |
| `subdir` | folder inside the repo whose contents replace the script's directory; `.` mirrors the repo root | `.` or `website/public` |
| `gh_token` | Personal Access Token, **only needed for private repos** | `github_pat_…` |
| `timezone` | IANA timezone for the timestamps in the banner | `Europe/Moscow` |
| `keep_files` | top-level file and folder names that must never be overwritten or deleted | `.htaccess, uploads` |
| `password` | password required to run a deploy; empty means no password | `••••••` |
| `purge` | delete files that are no longer in the repository | on by default |

`pull.php` and `pull-config.php` are always preserved, regardless of
`keep_files`.

## Password and "remember me"

The script's URL triggers a deploy, so setting a password is worth doing right
away.

- The password is **stored hashed** (`password_hash()`); the password itself
  never touches the disk. Minimum length is 6 characters.
- Every run is gated by an unlock screen with a **"remember me on this browser"**
  checkbox:
  - checked — a signed cookie is stored for **30 days** and you are not asked
    again;
  - unchecked — the cookie is a session cookie and dies with the browser (what
    you want on a shared computer).
- The cookie holds no password: it is an expiry plus an HMAC signature keyed by
  the stored hash. It cannot be forged without access to `pull-config.php`, and
  changing the password invalidates every cookie issued earlier.
- The cookie is set with `HttpOnly`, `SameSite=Lax`, `Secure` (over HTTPS), and
  is scoped to the script's directory.
- `pull.php?logout=1` forgets the password on this browser.

Leaving the password empty is allowed — the deploy is then open to anyone who
knows the URL; see [Security](#security).

## Deleting obsolete files (mirror mode)

The `purge` option — the "Delete files that are gone from the repository"
checkbox — is **on by default** for new installs.

What it does: after every copy it walks the directory and deletes everything
that is not in the repository, making the directory an exact copy of the repo
folder.

> ⚠️ **This is irreversible.** Files are removed from disk; there is no recycle
> bin. Anything created on the server and absent from the repository — user
> uploads, caches, logs, config files — is deleted unless it is listed in
> `keep_files`.

What keeps it safe:

- names in `keep_files` (plus `pull.php` and `pull-config.php` always) are
  skipped entirely, including everything inside them if they are folders;
- deletion runs **only after a successful copy** — a failed download or a broken
  archive never deletes anything;
- symlinks are not followed: the link itself is removed, whatever it points at
  is left alone.

With the option off, files removed from the repository stay on the server and
have to be cleaned up by hand.

**For existing installs:** an older `pull-config.php` has no `purge` key, and in
that case mirror mode is treated as **off** — updating the script alone will
never delete anything. To enable it, add `'purge' => true` to the config, or
delete `pull-config.php` and run the setup again.

## Access token

A public repo needs no token. For a private one:

**Fine-grained PAT** (recommended) — `github.com/settings/personal-access-tokens/new`:
Repository access → "Only select repositories" → this repo;
Repository permissions → **Contents: Read**.

**Classic PAT** — `github.com/settings/tokens/new`: the full `repo` scope
(`public_repo` alone is not enough for a private repository).

A `404` on download while a token is set means the token has no access to that
specific repo, or it expired or was revoked. The script prints this hint itself.

## Config file

`pull-config.php` is generated by the form and simply returns an array, so you
can edit it by hand:

```php
<?php
return [
    'repo'          => 'owner/name',
    'branch'        => 'main',
    'subdir'        => '.',
    'gh_token'      => '',
    'keep_files'    => ['pull.php', 'pull-config.php', '.htaccess', 'uploads'],
    'timezone'      => 'Europe/Moscow',
    'password_hash' => '',    // password hash; empty means no password
    'purge'         => true,  // delete files that are not in the repository
];
```

Never put a plaintext password in this file — only a hash. Easiest is to set it
through the setup form, or generate one yourself:
`php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`.

Delete `pull-config.php` to bring the setup form back.

## Environment variables

Both are optional and set on the hosting side (via `.htaccess` `SetEnv` or the
control panel, for example).

| Variable | Purpose |
|---|---|
| `GITHUB_TOKEN` | access token; **takes precedence** over `gh_token` from the config — handy for keeping the secret out of the file |
| `KRASKI_PULL_ALLOW_IPS` | comma-separated list of IPs allowed to trigger a pull. Empty means no restriction; everyone else gets a `403` |

## Running it

From a browser:

```
https://your-site/pull.php
```

The output is styled as a terminal and streams as the run progresses.

From cron or curl — `?plain=1` returns clean `text/plain` with no markup, and
the password goes in the `X-Pull-Password` header:

```bash
curl -s -H "X-Pull-Password: $PULL_PASSWORD" "https://your-site/pull.php?plain=1"
```

A daily deploy at 04:00 via cron:

```cron
0 4 * * * curl -s -H "X-Pull-Password: secret" "https://your-site/pull.php?plain=1" >> /var/log/pull.log 2>&1
```

The password is also accepted as `?password=…`, but that lands in web server
logs and browser history — the header is safer.

Response codes: `200` success, `401` password required or wrong, `403` IP not
allowed, `502` archive download failed, `500` unpack error, missing
`ZipArchive`, subdirectory not found, or temp directory not writable.

## Things to know

- **Mirror mode deletes permanently.** Anything that must survive on the server
  belongs either in the repository or in `keep_files`.
- **`keep_files` only applies at the top level.** Nested paths
  (`assets/config.json`) are not protected — inside subfolders everything is
  both copied and deleted.
- **The deploy is not atomic.** While files are being copied the site is briefly
  in an in-between state.
- **Hosting limits.** The script asks for `set_time_limit(300)` and sets
  `ignore_user_abort(true)`, but hard hosting limits can still cut a large
  repository off mid-run.
- **The token sits in a file next to the site.** The file is created with mode
  `0600`, but passing the token via `GITHUB_TOKEN` is safer.

## Security

`pull.php` is reachable by anyone who knows the URL. Do at least one of:

- **set a password** during setup — the simplest option;
- set `KRASKI_PULL_ALLOW_IPS` to a list of trusted addresses;
- put the file behind HTTP auth (`.htaccess` + `.htpasswd`);
- rename the file to something unguessable (then add the new name to
  `keep_files` so a deploy doesn't wipe the script itself).

The password and the IP filter combine well: the filter rejects stray requests
before the password is even checked.

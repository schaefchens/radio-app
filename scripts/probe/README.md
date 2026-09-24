# Host probe (Phase 0.5)

The plan builds on a few facts about the Hetzner webhosting that the sibling
projects contradict or never measured: how often the konsoleH cron really
runs, how long PHP may keep working after it has answered, whether SQLite's
WAL mode works on this filesystem, and which `.htaccess` directives are
allowed. Guessing wrong costs silently: a cron that is really hourly, a tick
killed halfway through, a directive that turns the whole site into a 500.

This probe answers those questions **before** anything is built on them. It is
a throwaway `/_probe/` folder, independent of the real site and uses none of
its code.

## Run it

```bash
scripts/probe/probe.sh --upload            # add --with-ai for the real AI calls
# → set up the konsoleH cron job it prints, leave it 24 h
scripts/probe/probe.sh --read              # any time; add --ai for one Claude + one TTS call
scripts/probe/probe.sh --remove            # when done
```

- **`--upload`** first creates the directories and the deny rule for
  `private/`. It then checks over HTTP that `private/` answers 403, and only
  after that uploads the config holding the probe key.
- **The probe key** is written to `scripts/probe/probe.env` (gitignored). The
  full cron lines go to `scripts/probe/results/cron-lines.txt`.
- **The 10-minute background run** also starts on upload.
- **`--dry-run`** works with `--upload` and `--remove`: it prints the sftp
  batch and uploads nothing.
- **`--with-ai`** copies `ANTHROPIC_KEY` and `OPENAI_KEY` into the probe's
  private config. With that, `--read --ai` makes **one** Claude call and
  **one** TTS call. ElevenLabs is never called: the account's quota is too
  small to spend on a probe.
- **Results** are kept in `scripts/probe/results/<time>/` as raw JSON plus
  `checks.tsv`. The summary ends in a **Decisions** block.

## The 24-hour cron check

Add one konsoleH cron job per minute. If the panel allows it, set up both **a**
and **c**; the log shows which of them actually arrive:

| Line | Type | What an arrival proves |
|---|---|---|
| a) `curl … --header 'Authorization: Bearer …' …/cron-probe.php` | command | The interval, **and** that the Authorization header survives the panel and the host |
| b) `…/cron-probe.php?key=…` | URL job | The interval. The key travels in the URL, so it lands in access logs |
| c) `<web root>/_probe/cron-cli.php` | PHP script | A **CLI tick** is possible: `bin/tick.php` runs without the FPM time limit |

The absolute web-root path for **c** is `paths.script_dir` in the `--read`
output. `cron-probe.php` also logs arrivals **without** a valid key, so a panel
that silently drops the header shows up rather than looking like "no cron".
Leave the job running a full day: a panel that is really hourly, or one with
long gaps at night, only shows up over hours.

## What each result decides

| Result | Decides |
|---|---|
| **Cron interval** (median, gaps over 90 s) | Around 60 s means the one-minute plan holds (commit horizon 15 min). **Hourly** means a longer look-ahead, with ticks triggered by app requests becoming the main driver |
| **Key transport** (header / query / cli) | Which line `npm run cron-command` prints for production: the header form, or the documented `?key=` fallback |
| **CLI arrivals** | Whether production uses `bin/tick.php` (no FPM time limit) or `cron.php` over HTTP |
| **Background run length** | `TICK_BUDGET` = seconds of bounded work per tick after `fastcgi_finish_request`: about the kill time minus 8 s, capped at 50 so a tick never overlaps the next minute's cron |
| **SQLite** `journal_mode` after `PRAGMA journal_mode=WAL` and on reopen | `SQLITE_WAL=1` if it reads `wal` both times, else `0` (rollback journal) |
| **flock** | That overlapping ticks skip instead of running twice (publish lock, jobs lock) |
| **Outbound HTTPS** to Anthropic, OpenAI, YouTube, Hetzner | That the generator, moderation and wake controller can reach their APIs. Without a key a 401 is the expected answer |
| **Argon2id time** | Cost of a passphrase claim or login, which hashes once |
| **htaccess checks** | Which directives `server/public/**/.htaccess` may use. A 500 on `htaccess-test/` means one is forbidden. `expr/` checks conditional headers (404 → `no-store`); `media/` checks that an uploaded `.php` is refused rather than run |
| **Authorization header** (with SetEnvIf / plain / CGIPassAuth) | Which mechanism the `/api` and `/cron.php` `.htaccess` must use so Bearer auth reaches PHP |
| **`.user.ini`**, `php_value`, `php_flag` | Where the upload limits live. PHP-FPM caches `.user.ini` for `user_ini.cache_ttl` seconds (300 by default), so read again after five minutes |
| **PHP version and extensions** | The domain must run PHP 8.5 with pdo_sqlite, curl, mbstring, intl, gd, sodium and openssl |

## Files

- **`www/`**: exactly what goes to `/_probe/`. Plain PHP, compatible down to
  7.4, so a wrong PHP version is reported instead of hidden by a parse error.
  - `probe.php`: the environment report.
  - `background.php`: the post-response run length.
  - `cron-probe.php` / `cron-cli.php`: the cron log.
  - `echo-auth.php`, `cgipassauth/`: Authorization header transport.
  - `htaccess-test/`: every directive under test.
  - `private/`: denied; holds the generated `config.php` and the runtime logs.
- **`probe.sh`**: upload, read, background, remove.
- **`summarize.php`**: turns a results directory into the summary. Runs
  locally.

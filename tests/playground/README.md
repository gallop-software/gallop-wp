# Testing Gallop locally

These checks run the plugin in a real WordPress, on your machine, with nothing
installed but Node.js (20.18 or later). [WordPress Playground](https://wordpress.github.io/wordpress-playground/)
runs PHP and the database inside Node.

Nothing in this folder ships: `build.sh` leaves `tests/` out of the zip.

## Run the checks

```bash
./tests/playground/run.sh                  # terminal 1: start WordPress on port 9400
node tests/playground/checks.mjs           # terminal 2: run the checks
```

The run ends by removing the plugin's data, to check that uninstalling cleans up. Stop the
server and start it again before running the checks a second time.

| What | Server | Checks |
|---|---|---|
| Everything | `./tests/playground/run.sh` | `node tests/playground/checks.mjs` |
| Oldest versions supported | `WP=6.4 PHP=8.1 ./tests/playground/run.sh` | `node tests/playground/checks.mjs` |
| Key set in `wp-config.php` | `BLUEPRINT=tests/playground/blueprint-constant.json ./tests/playground/run.sh` | `MODE=constant KEY=gallopwp_ForTestsOnly0123456789abcdefghijklmnopqrstu node tests/playground/checks.mjs` |
| Unusable key in `wp-config.php` | `BLUEPRINT=tests/playground/blueprint-constant-invalid.json ./tests/playground/run.sh` | `MODE=constant-invalid node tests/playground/checks.mjs` |
| Plain HTTP, not a local site | `BLUEPRINT=tests/playground/blueprint-production.json ./tests/playground/run.sh` | `MODE=not-local node tests/playground/checks.mjs` |

Use another port with `PORT=9401` for the server and `BASE=http://127.0.0.1:9401` for the
checks.

## Check syntax

```bash
PHP=8.1 npx -y @php-wasm/cli@latest -l src/Auth/ApiKey.php
```

## Look at it yourself

Start the server and log in at <http://127.0.0.1:9400/wp-login.php> with `admin` /
`password`. The site is thrown away when the server stops.

The settings screen is not covered by `checks.mjs`. Check by hand, under Gallop → Settings →
Front-end connection:

1. With no key, the status says so and the button reads **Generate key**.
2. Generating shows the key once, with a **Copy** button. After a reload it is gone, and the
   status shows the date and the key's last four characters.
3. **Submit comments** starts unticked. Ticking and saving lets the key submit a comment;
   unticking takes that away.
4. **Regenerate key** asks first. Afterwards the old key is refused and the permission is
   still ticked.
5. With the key set in `wp-config.php` there is no button, and the status says where the key
   comes from. With an unusable key there, every admin screen shows a warning.

## Plugin Check

[Plugin Check](https://wordpress.org/plugins/plugin-check/) is the tool WordPress.org's
reviewers use. Its command line does not run in Playground, so use its screen:

```bash
PORT=9401 BLUEPRINT=tests/playground/plugin-check.json ./tests/playground/run.sh
```

Log in, open Tools → Plugin Check, choose Gallop, tick every category, and run it. Findings
in `build.sh`, `.gitignore` and `tests/` are about files that are not in the zip. To check
exactly what ships, run `npm run zip`, unpack `gallop.zip`, and mount that folder in place
of the repository.

## What the helper plugin does

`mu-plugins/gallop-test.php` is mounted into the test site only. It records the email
WordPress would have sent, records what other plugins can see while a comment is saved,
and adds routes under `gallop-test/v1` for setting options and reading stored rows. Its
routes are open to anyone who can reach the server. Never install it on a real site.

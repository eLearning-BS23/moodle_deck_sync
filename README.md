# Moodle Deck Sync

Moodle Deck Sync is the Nextcloud companion app for Collaborative Learning
Spaces. It receives signed Moodle webhook events, provisions Nextcloud Deck
boards for Moodle course groups and assignments, manages board membership, and
exposes health/configuration endpoints for administrators.

This app is the Nextcloud half of the integration.

## Getting the Moodle plugin

The companion Moodle plugin (`local_nextclouddeck`) is supplied directly by
Brain Station 23 rather than through the Moodle plugins directory. Both halves
are required: Moodle sends the signed course/group events, and this Nextcloud
app turns those events into Deck boards and membership changes.

To request the Moodle plugin, or for installation and support enquiries,
contact **<elearning@brainstation-23.com>**.

## What it does

- Receives HMAC-signed webhooks from Moodle for course group, assignment, and
  membership changes.
- Provisions one Nextcloud Deck board per eligible Moodle assignment/group sync
  context and creates the expected project stacks.
- Resolves Moodle participants to Nextcloud users by email and can provision
  accounts according to the configured mode.
- Keeps persistent sync mappings so repeated Moodle events are idempotent and
  board/member updates can be reconciled safely.
- Archives Deck boards when Moodle groups are deleted, with optional background
  purge controlled by administrator configuration.
- Exposes admin settings and a health endpoint for validating Moodle, Deck, and
  bot-account connectivity.

## Requirements

- Nextcloud 34
- Deck 1.18.2 enabled in Nextcloud
- PHP 8.3
- Node 22 and npm 10 for frontend builds
- A dedicated Nextcloud bot user with an app password for Deck API calls
- The matching Moodle integration that signs webhook requests with the shared
  HMAC secret

The app is intentionally pinned to Nextcloud 34. Expanding compatibility needs a
fresh Deck/API readiness pass before release.

## Installation

For development, place this app at `apps-extra/moodle_deck_sync` or another
enabled Nextcloud app path, then run:

```bash
composer install
npm ci
npm run build
php /var/www/html/occ app:enable moodle_deck_sync
```

Run `occ` inside a real Nextcloud installation or container. It cannot run as a
standalone host command.

## Configuration

Configure the app from the Nextcloud administration settings page after enabling
it:

- Allowed Moodle instance URLs
- Shared HMAC secret
- Bot username
- Bot app password
- User provisioning mode
- Optional purge policy for archived boards

Never log or share bot passwords, shared secrets, full signatures, raw webhook
bodies, or unnecessary personally identifiable information.

## Health Check

Use the OCS health endpoint after configuration:

```bash
curl -s https://cloud.example.org/ocs/v2.php/apps/moodle_deck_sync/api/v1/health \
  -H 'OCS-APIRequest: true'
```

The response should report whether the app is configured, Deck is reachable, and
the bot account is authenticated.

## Development

Install local dependencies and run the full quality gate:

```bash
make install
make quality
```

The quality target runs:

- PHPUnit
- Psalm
- PHP-CS-Fixer dry run
- ESLint
- Webpack production build
- Shared integration fixture verification

Generated frontend assets under `js/` are release artifacts and must be
committed before signing a release.

## Release

Use the repository runbook:

```text
RELEASING_MOODLE_DECK_SYNC.md
```

The Nextcloud App Store release version is read from `appinfo/info.xml`.

## Security

Report issues through:

```text
https://github.com/eLearning-BS23/moodle_deck_sync/issues
```

Do not include secrets, app passwords, raw signed payloads, or full signatures in
public reports.

## License

AGPL-3.0-or-later. See `COPYING`.

# Captured API fixtures

Empty until someone runs `tests/bin/record-api-fixtures.php` against a live
site's sandbox credentials. See CLAUDE.md ("Tier 2 API fixture tests") for
the full explanation - short version:

- `voter/*.json` / `voter/*.expected.json` - raw sandbox response and frozen
  parsed snapshot for `ApiVoter::getApiVoter()`, consumed by
  `tests/src/Unit/Service/ApiVoterFixtureReplayTest.php`.
- `nl/*.json` / `nl/*.expected.json` - same, for `ApiNls::getApiNls()`, consumed
  by `tests/src/Unit/Service/ApiNlsFixtureReplayTest.php`.

Both files in a pair are safe to commit: they're sandbox data (not real
voters/NLs) and contain no API key or URL. The replay tests skip cleanly
(not a failure) when a directory has no fixtures yet.

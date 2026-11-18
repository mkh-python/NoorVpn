# Contributing

Keep code changes small and include a regression test for a bug. Run the commands in README.md. Do not commit a deployed config.php, runtime state, API token, client subscription link or database backup.

Use a disposable source copy and temporary MySQL schema for integration tests. `tests/schema_smoke.py` refuses an already configured deployment. Run live panel mutation tests only on an explicitly authorized panel; they create and delete a dedicated test client.

Preserve original licensing and attribution. Add the exact tested panel/runtime versions to TEST_REPORT.md. Do not describe an integration as tested merely because its files exist or PHP lint passed.

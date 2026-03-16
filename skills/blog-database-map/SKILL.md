---
name: blog-database-map
description: Map the current C:\www\blog database schema, legacy tables, Eloquent models, and code usage. Use when Codex needs to answer questions like "which table stores this", "哪些 controller/command/job/service 用到這個 table 或 model", "which migration owns these columns", "is this table legacy or active", or before changing Laravel data-layer code in this repo.
---

# Blog Database Map

## Start Here

1. Read `references/db-summary.md` first.
2. Read `references/db-inventory.md` only for the specific table or model you need.
3. If migrations, models, or DB call sites may have changed, run `python skills/blog-database-map/scripts/build_db_inventory.py` before trusting the snapshot.
4. For risky edits, verify exact call sites with `rg` on the repo because the generated usage map is heuristic.

## What This Skill Gives You

- A current table inventory sourced from `database/migrations`.
- Model-to-table mapping, primary keys, and Eloquent relations from `app/Models`.
- Code touchpoints from controllers, commands, jobs, services, routes, and tests.
- Gap detection for tables that exist in code but have no migration in this repo, plus framework-managed tables like `migrations`.

## Typical Uses

- Find which table or model owns a feature before editing controllers or commands.
- Estimate blast radius before renaming a column, adding a migration, or removing a legacy table.
- Check whether a table is still active in runtime or only exists as a legacy artifact.
- Trace data flows for the article/media, commerce, Telegram, BTDig, or video-processing areas.

## Refresh Rules

- Rebuild the references after any schema, model, or DB query change.
- Extend `scripts/build_db_inventory.py` if new DB-heavy folders are introduced outside the current scan list.
- Do not copy `.env` secrets into the skill; keep only safe runtime flags in generated references.

## Resources

### references/

- `db-summary.md`: quick domain map, schema sources, runtime notes, and gap list.
- `db-inventory.md`: per-table columns, foreign keys, relations, and code usage.

### scripts/

- `build_db_inventory.py`: regenerate both reference files from the current workspace state.

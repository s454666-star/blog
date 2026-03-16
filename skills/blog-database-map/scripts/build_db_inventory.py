#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path


SAFE_ENV_KEYS = [
    "DB_CONNECTION",
    "CACHE_DRIVER",
    "SESSION_DRIVER",
    "QUEUE_CONNECTION",
]

CODE_PATHS = [
    "app/Http/Controllers",
    "app/Console/Commands",
    "app/Jobs",
    "app/Services",
    "routes",
    "tests",
]

DOMAIN_GROUPS = [
    (
        "Media catalog and articles",
        [
            "actors",
            "albums",
            "album_photos",
            "articles",
            "images",
            "photos",
            "file_screenshots",
        ],
    ),
    (
        "Member, product, and checkout flow",
        [
            "members",
            "credit_cards",
            "delivery_addresses",
            "orders",
            "order_items",
            "return_orders",
            "products",
            "product_categories",
            "blacklisted_receivers",
        ],
    ),
    (
        "BTDig and scraping",
        [
            "api_log",
            "btdig_results",
            "btdig_result_images",
            "extracted_codes",
        ],
    ),
    (
        "Telegram, token scan, and filestore",
        [
            "dialogues",
            "group_media_scan_states",
            "telegram_filestore_sessions",
            "telegram_filestore_files",
            "token_scan_headers",
            "token_scan_items",
        ],
    ),
    (
        "Video library and duplicate detection",
        [
            "videos",
            "videos_ts",
            "video_master",
            "video_screenshots",
            "video_face_screenshots",
            "video_duplicates",
            "video_features",
            "video_feature_frames",
            "video_feature_matches",
            "external_video_duplicate_matches",
            "external_video_duplicate_frames",
            "test_vi",
        ],
    ),
    (
        "Framework and infrastructure",
        [
            "users",
            "password_resets",
            "personal_access_tokens",
            "failed_jobs",
            "jobs",
            "cache",
            "sessions",
            "migrations",
        ],
    ),
]


@dataclass
class RelationRecord:
    name: str
    relation_type: str
    related_model: str
    foreign_key: str | None = None
    local_key: str | None = None


@dataclass
class ModelRecord:
    class_name: str
    path: str
    table: str
    primary_key: str = "id"
    fillable: list[str] = field(default_factory=list)
    timestamps: bool = True
    relations: list[RelationRecord] = field(default_factory=list)


@dataclass
class TableRecord:
    name: str
    columns: list[str] = field(default_factory=list)
    primary_key: str | None = None
    foreign_keys: list[str] = field(default_factory=list)
    migration_sources: list[str] = field(default_factory=list)
    model_name: str | None = None
    model_path: str | None = None
    model_primary_key: str | None = None
    relations: list[RelationRecord] = field(default_factory=list)
    code_usage: dict[str, set[str]] = field(default_factory=lambda: defaultdict(set))
    direct_table_usage: dict[str, set[str]] = field(default_factory=lambda: defaultdict(set))


def repo_root_from_script() -> Path:
    return Path(__file__).resolve().parents[3]


def slugify_heading(value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")
    return slug or value.lower()


def relpath(path: Path, root: Path) -> str:
    return path.resolve().relative_to(root.resolve()).as_posix()


def snake_case(value: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "_", value).lower()


def pluralize(word: str) -> str:
    if word.endswith("y") and len(word) > 1 and word[-2] not in "aeiou":
        return word[:-1] + "ies"
    if word.endswith(("s", "x", "z", "ch", "sh")):
        return word + "es"
    return word + "s"


def infer_table_name(class_name: str) -> str:
    return pluralize(snake_case(class_name))


def parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values

    for raw_line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key in SAFE_ENV_KEYS:
            values[key] = value.strip().strip('"').strip("'")
    return values


def add_table_record(table_map: dict[str, TableRecord], table_name: str) -> TableRecord:
    if table_name not in table_map:
        table_map[table_name] = TableRecord(name=table_name)
    return table_map[table_name]


def split_args(arg_string: str) -> list[str]:
    parts: list[str] = []
    current: list[str] = []
    depth = 0
    quote: str | None = None

    for char in arg_string:
        if quote:
            current.append(char)
            if char == quote:
                quote = None
            continue

        if char in {"'", '"'}:
            quote = char
            current.append(char)
            continue

        if char in "([{" :
            depth += 1
        elif char in ")]}" and depth:
            depth -= 1

        if char == "," and depth == 0:
            part = "".join(current).strip()
            if part:
                parts.append(part)
            current = []
            continue

        current.append(char)

    tail = "".join(current).strip()
    if tail:
        parts.append(tail)
    return parts


def extract_string_literal(argument: str) -> str | None:
    match = re.match(r"\s*'([^']+)'", argument)
    if match:
        return match.group(1)
    return None


def parse_schema_builder_columns(body: str) -> tuple[list[str], str | None, list[str]]:
    columns: list[str] = []
    primary_key = None
    foreign_keys: list[str] = []

    lines = body.splitlines()
    for line in lines:
        stripped = line.strip()
        if "$table->timestamps()" in stripped:
            columns.extend(["created_at", "updated_at"])
            continue
        if "$table->rememberToken()" in stripped:
            columns.append("remember_token")
            continue
        morphs = re.search(r"\$table->morphs\('([^']+)'\)", stripped)
        if morphs:
            base = morphs.group(1)
            columns.extend([f"{base}_type", f"{base}_id"])
            continue
        method_match = re.search(r"\$table->([A-Za-z_]+)\((.*?)\)", stripped)
        if not method_match:
            continue
        method = method_match.group(1)
        args = split_args(method_match.group(2))
        if method == "id":
            columns.append("id")
            primary_key = primary_key or "id"
            continue
        if method in {"index", "unique"}:
            continue
        if method == "foreign":
            continue
        if method in {"foreignId", "foreignIdFor"} and args:
            name = extract_string_literal(args[0])
            if name:
                columns.append(name)
            continue
        if args:
            name = extract_string_literal(args[0])
            if name:
                columns.append(name)

    for match in re.finditer(
        r"foreignId\('([^']+)'\)\s*->constrained\('([^']+)'\)",
        body,
        re.S,
    ):
        foreign_keys.append(f"{match.group(1)} -> {match.group(2)}.id")

    for match in re.finditer(
        r"foreign\('([^']+)'.*?\)\s*->references\('([^']+)'\)\s*->on\('([^']+)'\)",
        body,
        re.S,
    ):
        foreign_keys.append(f"{match.group(1)} -> {match.group(3)}.{match.group(2)}")

    deduped_columns = list(dict.fromkeys(columns))
    deduped_foreign_keys = list(dict.fromkeys(foreign_keys))
    return deduped_columns, primary_key, deduped_foreign_keys


def parse_sql_table_body(body: str) -> tuple[list[str], str | None, list[str]]:
    columns: list[str] = []
    primary_key = None
    foreign_keys: list[str] = []

    for line in body.splitlines():
        column_match = re.match(r"\s*`([^`]+)`\s+", line)
        if column_match:
            columns.append(column_match.group(1))
            continue
        primary_match = re.search(r"PRIMARY KEY \(`([^`]+)`\)", line)
        if primary_match:
            primary_key = primary_match.group(1)
            continue
        foreign_match = re.search(
            r"FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)",
            line,
        )
        if foreign_match:
            foreign_keys.append(
                f"{foreign_match.group(1)} -> {foreign_match.group(2)}.{foreign_match.group(3)}"
            )

    return columns, primary_key, foreign_keys


def parse_migrations(repo_root: Path) -> tuple[dict[str, TableRecord], dict[str, list[str]]]:
    tables: dict[str, TableRecord] = {}
    migrations_by_file: dict[str, list[str]] = defaultdict(list)

    for path in sorted((repo_root / "database" / "migrations").glob("*.php")):
        relative = relpath(path, repo_root)
        content = path.read_text(encoding="utf-8", errors="ignore")

        for match in re.finditer(
            r"Schema::create\(\s*'([^']+)'\s*,\s*function\s*\(Blueprint \$table\)\s*\{(.*?)\n\s*\}\s*\);",
            content,
            re.S,
        ):
            table_name = match.group(1)
            body = match.group(2)
            record = add_table_record(tables, table_name)
            columns, primary_key, foreign_keys = parse_schema_builder_columns(body)
            record.columns = list(dict.fromkeys(record.columns + columns))
            if primary_key and not record.primary_key:
                record.primary_key = primary_key
            record.foreign_keys = list(dict.fromkeys(record.foreign_keys + foreign_keys))
            if relative not in record.migration_sources:
                record.migration_sources.append(relative)
            migrations_by_file[relative].append(table_name)

        for match in re.finditer(
            r"CREATE TABLE IF NOT EXISTS `([^`]+)` \((.*?)\) ENGINE=",
            content,
            re.S,
        ):
            table_name = match.group(1)
            body = match.group(2)
            record = add_table_record(tables, table_name)
            columns, primary_key, foreign_keys = parse_sql_table_body(body)
            record.columns = list(dict.fromkeys(record.columns + columns))
            if primary_key and not record.primary_key:
                record.primary_key = primary_key
            record.foreign_keys = list(dict.fromkeys(record.foreign_keys + foreign_keys))
            if relative not in record.migration_sources:
                record.migration_sources.append(relative)
            migrations_by_file[relative].append(table_name)

    record = add_table_record(tables, "migrations")
    record.primary_key = record.primary_key or "id"
    record.columns = record.columns or ["id", "migration", "batch"]
    if "framework-managed via migrate:install" not in record.migration_sources:
        record.migration_sources.append("framework-managed via migrate:install")

    for migration_file, table_names in migrations_by_file.items():
        migrations_by_file[migration_file] = sorted(set(table_names))

    return tables, migrations_by_file


def parse_model_relations(content: str) -> list[RelationRecord]:
    relations: list[RelationRecord] = []
    method_pattern = re.compile(
        r"function\s+(\w+)\s*\([^)]*\)\s*(?::\s*[^{]+)?\{(.*?)\}",
        re.S,
    )

    for method_match in method_pattern.finditer(content):
        method_name = method_match.group(1)
        body = method_match.group(2)
        relation_match = re.search(
            r"return\s+\$this->(belongsTo|hasMany|hasOne|belongsToMany)\(([^;]+)\);",
            body,
            re.S,
        )
        if not relation_match:
            continue
        relation_type = relation_match.group(1)
        args = split_args(relation_match.group(2))
        related_model = "unknown"
        foreign_key = None
        local_key = None

        if args:
            related_match = re.search(r"([A-Za-z0-9_]+)::class", args[0])
            if related_match:
                related_model = related_match.group(1)
        if len(args) > 1:
            foreign_key = extract_string_literal(args[1])
        if len(args) > 2:
            local_key = extract_string_literal(args[2])

        relations.append(
            RelationRecord(
                name=method_name,
                relation_type=relation_type,
                related_model=related_model,
                foreign_key=foreign_key,
                local_key=local_key,
            )
        )

    return relations


def parse_models(repo_root: Path) -> dict[str, ModelRecord]:
    models: dict[str, ModelRecord] = {}
    model_root = repo_root / "app" / "Models"

    for path in sorted(model_root.glob("*.php")):
        content = path.read_text(encoding="utf-8", errors="ignore")
        class_match = re.search(r"class\s+([A-Za-z0-9_]+)", content)
        if not class_match:
            continue
        class_name = class_match.group(1)
        table_match = re.search(r"\$table\s*=\s*'([^']+)'", content)
        primary_match = re.search(r"\$primaryKey\s*=\s*'([^']+)'", content)
        fillable_match = re.search(r"\$fillable\s*=\s*\[(.*?)\];", content, re.S)
        timestamps_disabled = bool(re.search(r"\$timestamps\s*=\s*false", content, re.I))
        table_name = table_match.group(1) if table_match else infer_table_name(class_name)
        primary_key = primary_match.group(1) if primary_match else "id"
        fillable = (
            re.findall(r"'([^']+)'", fillable_match.group(1))
            if fillable_match
            else []
        )
        models[class_name] = ModelRecord(
            class_name=class_name,
            path=relpath(path, repo_root),
            table=table_name,
            primary_key=primary_key,
            fillable=fillable,
            timestamps=not timestamps_disabled,
            relations=parse_model_relations(content),
        )

    return models


def classify_path(relative_path: str) -> str:
    normalized = relative_path.replace("\\", "/")
    if normalized.startswith("app/Http/Controllers/"):
        return "controllers"
    if normalized.startswith("app/Console/Commands/"):
        return "commands"
    if normalized.startswith("app/Jobs/"):
        return "jobs"
    if normalized.startswith("app/Services/"):
        return "services"
    if normalized.startswith("routes/"):
        return "routes"
    if normalized.startswith("tests/"):
        return "tests"
    return "other"


def find_model_usage(content: str, model_names: set[str]) -> set[str]:
    found: set[str] = set()

    for imported in re.findall(r"use App\\Models\\([A-Za-z0-9_]+);", content):
        if imported in model_names:
            found.add(imported)

    for model_name in model_names:
        if (
            re.search(rf"\b{re.escape(model_name)}::", content)
            or re.search(rf"new\s+{re.escape(model_name)}\b", content)
            or re.search(rf"App\\Models\\{re.escape(model_name)}\b", content)
        ):
            found.add(model_name)

    return found


def parse_code_usage(repo_root: Path, models: dict[str, ModelRecord]) -> tuple[dict[str, dict[str, set[str]]], dict[str, dict[str, set[str]]]]:
    model_usage: dict[str, dict[str, set[str]]] = defaultdict(lambda: defaultdict(set))
    direct_usage: dict[str, dict[str, set[str]]] = defaultdict(lambda: defaultdict(set))
    model_names = set(models)

    table_pattern = re.compile(
        r"(?:DB::table|->from|->join|->leftJoin|->rightJoin)\('([A-Za-z0-9_]+)'\)"
    )

    for relative_dir in CODE_PATHS:
        target = repo_root / relative_dir
        if not target.exists():
            continue
        for path in sorted(target.rglob("*.php")):
            relative = relpath(path, repo_root)
            category = classify_path(relative)
            content = path.read_text(encoding="utf-8", errors="ignore")

            for model_name in find_model_usage(content, model_names):
                model_usage[model_name][category].add(relative)

            for table_name in table_pattern.findall(content):
                direct_usage[table_name][category].add(relative)

    return model_usage, direct_usage


def attach_models_and_usage(
    tables: dict[str, TableRecord],
    models: dict[str, ModelRecord],
    model_usage: dict[str, dict[str, set[str]]],
    direct_usage: dict[str, dict[str, set[str]]],
) -> None:
    for model in models.values():
        record = add_table_record(tables, model.table)
        record.model_name = model.class_name
        record.model_path = model.path
        record.model_primary_key = model.primary_key
        record.relations = model.relations
        if not record.columns:
            inferred_columns = [model.primary_key] + model.fillable
            if model.timestamps:
                inferred_columns.extend(["created_at", "updated_at"])
            record.columns = list(dict.fromkeys(inferred_columns))
        for category, files in model_usage.get(model.class_name, {}).items():
            record.code_usage[category].update(files)

    for table_name, categories in direct_usage.items():
        record = add_table_record(tables, table_name)
        for category, files in categories.items():
            record.direct_table_usage[category].update(files)

    for record in tables.values():
        if not record.primary_key and record.model_primary_key:
            record.primary_key = record.model_primary_key


def format_list(values: list[str]) -> str:
    return ", ".join(f"`{value}`" for value in values) if values else "none"


def render_summary(
    repo_root: Path,
    tables: dict[str, TableRecord],
    migrations_by_file: dict[str, list[str]],
    safe_env: dict[str, str],
    output_path: Path,
) -> None:
    generated_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%SZ")
    lines: list[str] = [
        "# Blog Database Summary",
        "",
        f"Generated by `skills/blog-database-map/scripts/build_db_inventory.py` on {generated_at}.",
        "",
        "## How to use this skill reference",
        "",
        "- Read this file first to identify the relevant table group and schema source.",
        "- Read `db-inventory.md` when you need columns, relationships, and code touchpoints for a specific table.",
        "- Regenerate both files after schema or model changes with `python skills/blog-database-map/scripts/build_db_inventory.py`.",
        "",
        "## Connection notes",
        "",
        "- Default database config lives in `config/database.php`.",
        "- Credential values live in `.env`; do not copy secrets into commits or responses.",
    ]

    for key in SAFE_ENV_KEYS:
        value = safe_env.get(key)
        if value:
            lines.append(f"- `{key}` is currently `{value}`.")

    runtime_notes: list[str] = []
    if safe_env.get("CACHE_DRIVER") and safe_env["CACHE_DRIVER"] != "database" and "cache" in tables:
        runtime_notes.append(
            f"- `cache` table exists, but `CACHE_DRIVER` is `{safe_env['CACHE_DRIVER']}`, so DB cache is not the default path."
        )
    if safe_env.get("SESSION_DRIVER") and safe_env["SESSION_DRIVER"] != "database" and "sessions" in tables:
        runtime_notes.append(
            f"- `sessions` table exists, but `SESSION_DRIVER` is `{safe_env['SESSION_DRIVER']}`, so DB sessions are not the default path."
        )
    if safe_env.get("QUEUE_CONNECTION") and safe_env["QUEUE_CONNECTION"] != "database" and "jobs" in tables:
        runtime_notes.append(
            f"- `jobs` table exists, but `QUEUE_CONNECTION` is `{safe_env['QUEUE_CONNECTION']}`, so the database queue is not the default path."
        )

    if runtime_notes:
        lines.extend(["", "## Runtime notes", ""])
        lines.extend(runtime_notes)

    lines.extend(
        [
            "",
            "## Schema sources",
            "",
        ]
    )

    for migration_file in sorted(migrations_by_file):
        table_names = migrations_by_file[migration_file]
        lines.append(
            f"- `{migration_file}`: {len(table_names)} tables -> {', '.join(f'`{name}`' for name in table_names)}"
        )

    lines.extend(
        [
            "",
            "## Domain map",
            "",
        ]
    )

    seen: set[str] = set()
    for group_name, table_names in DOMAIN_GROUPS:
        present = [table for table in table_names if table in tables]
        if not present:
            continue
        seen.update(present)
        lines.append(f"### {group_name}")
        lines.append("")
        lines.append(f"- {len(present)} tables: {', '.join(f'`{name}`' for name in present)}")
        lines.append("")

    unmapped = sorted(set(tables) - seen)
    if unmapped:
        lines.append("### Unmapped or legacy-only tables")
        lines.append("")
        lines.append(f"- {len(unmapped)} tables: {', '.join(f'`{name}`' for name in unmapped)}")
        lines.append("")

    no_migration = sorted(
        table.name for table in tables.values() if not table.migration_sources
    )
    no_model = sorted(table.name for table in tables.values() if not table.model_name)
    no_usage = sorted(
        table.name
        for table in tables.values()
        if not any(table.code_usage.values()) and not any(table.direct_table_usage.values())
    )

    lines.extend(
        [
            "## Gaps and caveats",
            "",
            f"- Tables with no migration file in this repo: {format_list(no_migration)}.",
            f"- Tables with no Eloquent model: {format_list(no_model)}.",
            f"- Tables with no current controller/command/job/service/test usage detected: {format_list(no_usage)}.",
            "- Detection is heuristic. Use `rg` on the repo for final confirmation before risky edits.",
            "",
        ]
    )

    output_path.write_text("\n".join(lines), encoding="utf-8")


def render_inventory(repo_root: Path, tables: dict[str, TableRecord], output_path: Path) -> None:
    generated_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%SZ")
    table_names = sorted(tables)
    lines: list[str] = [
        "# Database Inventory",
        "",
        f"Generated by `skills/blog-database-map/scripts/build_db_inventory.py` on {generated_at}.",
        "",
        "## Table of contents",
        "",
    ]

    for table_name in table_names:
        lines.append(f"- [{table_name}](#{slugify_heading(table_name)})")

    for table_name in table_names:
        table = tables[table_name]
        lines.extend(
            [
                "",
                f"## {table_name}",
                "",
                f"- Migration source: {format_list(table.migration_sources)}",
                f"- Model: `{table.model_name}` ({table.model_path})" if table.model_name and table.model_path else "- Model: none",
                f"- Primary key: `{table.primary_key or 'unknown'}`",
                f"- Columns ({len(table.columns)}): {format_list(table.columns)}",
                f"- Foreign keys: {format_list(table.foreign_keys)}",
            ]
        )

        if table.relations:
            relation_parts = []
            for relation in table.relations:
                details = f"`{relation.name}` {relation.relation_type} `{relation.related_model}`"
                if relation.foreign_key:
                    details += f" via `{relation.foreign_key}`"
                if relation.local_key:
                    details += f" -> `{relation.local_key}`"
                relation_parts.append(details)
            lines.append(f"- Model relations: {', '.join(relation_parts)}")
        else:
            lines.append("- Model relations: none")

        if any(table.code_usage.values()):
            usage_parts = []
            for category in sorted(table.code_usage):
                files = sorted(table.code_usage[category])
                usage_parts.append(f"{category}: {', '.join(f'`{path}`' for path in files)}")
            lines.append(f"- Code usage via model: {'; '.join(usage_parts)}")
        else:
            lines.append("- Code usage via model: none detected")

        if any(table.direct_table_usage.values()):
            usage_parts = []
            for category in sorted(table.direct_table_usage):
                files = sorted(table.direct_table_usage[category])
                usage_parts.append(f"{category}: {', '.join(f'`{path}`' for path in files)}")
            lines.append(f"- Direct table calls: {'; '.join(usage_parts)}")
        else:
            lines.append("- Direct table calls: none detected")

    output_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Build a markdown database inventory for the current blog workspace."
    )
    parser.add_argument(
        "--repo-root",
        default=str(repo_root_from_script()),
        help="Workspace root to scan. Defaults to the parent repo of this skill.",
    )
    parser.add_argument(
        "--output-dir",
        default=str(Path(__file__).resolve().parents[1] / "references"),
        help="Directory for generated markdown files.",
    )
    args = parser.parse_args()

    repo_root = Path(args.repo_root).resolve()
    output_dir = Path(args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    tables, migrations_by_file = parse_migrations(repo_root)
    models = parse_models(repo_root)
    model_usage, direct_usage = parse_code_usage(repo_root, models)
    attach_models_and_usage(tables, models, model_usage, direct_usage)
    safe_env = parse_env(repo_root / ".env")

    render_summary(
        repo_root=repo_root,
        tables=tables,
        migrations_by_file=migrations_by_file,
        safe_env=safe_env,
        output_path=output_dir / "db-summary.md",
    )
    render_inventory(
        repo_root=repo_root,
        tables=tables,
        output_path=output_dir / "db-inventory.md",
    )

    print(f"Wrote {output_dir / 'db-summary.md'}")
    print(f"Wrote {output_dir / 'db-inventory.md'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

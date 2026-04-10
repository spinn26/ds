#!/usr/bin/env python3
"""
Import CSV files from Db/ directory into PostgreSQL tables.
CSV format: semicolon-delimited, with headers, @-prefixed columns are metadata (skip).
Handles Bubble-style comma-separated array values in integer FK columns.
"""

import csv
import os
import sys
import subprocess
import re

csv.field_size_limit(10 * 1024 * 1024)

DB_NAME = "newds"
DB_USER = "newds"
DB_HOST = "127.0.0.1"
DB_PASSWORD = "Rfghbtkjd%22"

BASE_DIR = "/var/www/newds/Db/Db"

# Map CSV filename (without .csv, lowercase) to actual table name
TABLE_NAME_MAP = {
    "webuser": "WebUser",
}

# Map (table_name, csv_column) to actual DB column name
COLUMN_RENAME = {
    ("country", "order"): "order_field",
    ("changeConsultantStructureTrigger", "user"): "user_field",
    ("user_status_log", "user"): "user_field",
}

# Files to skip (no matching table in DB)
SKIP_FILES = {"person"}


def psql_env():
    env = os.environ.copy()
    env["PGPASSWORD"] = DB_PASSWORD
    return env


def run_psql(sql, tuples_only=False):
    cmd = ["psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST]
    if tuples_only:
        cmd += ["-t", "-A"]
    cmd += ["-c", sql]
    result = subprocess.run(cmd, capture_output=True, text=True, env=psql_env())
    return result


def get_table_columns(table_name):
    """Get column names using pg_attribute (more reliable than information_schema)."""
    sql = (
        f"SELECT a.attname FROM pg_attribute a "
        f"JOIN pg_class c ON a.attrelid = c.oid "
        f"JOIN pg_namespace n ON c.relnamespace = n.oid "
        f"WHERE c.relname = '{table_name}' AND n.nspname = 'public' "
        f"AND a.attnum > 0 AND NOT a.attisdropped "
        f"ORDER BY a.attnum;"
    )
    result = run_psql(sql, tuples_only=True)
    cols = [line.strip() for line in result.stdout.strip().split("\n") if line.strip()]
    return cols


def get_column_types(table_name):
    """Get column name -> type mapping."""
    sql = (
        f"SELECT a.attname, t.typname FROM pg_attribute a "
        f"JOIN pg_class c ON a.attrelid = c.oid "
        f"JOIN pg_namespace n ON c.relnamespace = n.oid "
        f"JOIN pg_type t ON a.atttypid = t.oid "
        f"WHERE c.relname = '{table_name}' AND n.nspname = 'public' "
        f"AND a.attnum > 0 AND NOT a.attisdropped "
        f"ORDER BY a.attnum;"
    )
    result = run_psql(sql, tuples_only=True)
    types = {}
    for line in result.stdout.strip().split("\n"):
        line = line.strip()
        if "|" in line:
            col, typ = line.split("|", 1)
            types[col.strip()] = typ.strip()
    return types


def get_all_tables():
    sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public';"
    result = run_psql(sql, tuples_only=True)
    return [t.strip() for t in result.stdout.strip().split("\n") if t.strip()]


def get_table_name(csv_filename):
    """Derive table name from CSV filename."""
    basename = os.path.splitext(csv_filename)[0]
    if basename.lower() in {s.lower() for s in SKIP_FILES}:
        return None
    # Check explicit mapping (case-insensitive)
    for key, val in TABLE_NAME_MAP.items():
        if basename.lower() == key.lower():
            return val
    return basename


def discover_csv_files(base_dir):
    """Walk directory and find all CSV files, return list of (relative_path, full_path)."""
    files = []
    for root, dirs, filenames in os.walk(base_dir):
        for f in filenames:
            if f.lower().endswith(".csv"):
                full = os.path.join(root, f)
                rel = os.path.relpath(full, base_dir)
                files.append((rel, full))
    return files


def clean_value(val, col_type):
    """Clean a CSV value for PostgreSQL import."""
    if val is None or val.strip() == "":
        return ""

    val = val.strip()

    # Handle Bubble-style comma-separated arrays in integer columns
    if col_type in ("int4", "int8", "int2", "integer", "bigint", "smallint"):
        if "," in val:
            # Take first value from comma-separated list
            first = val.split(",")[0].strip()
            return first if first else ""
        # Handle boolean-like values in int columns
        if val.lower() == "true":
            return "1"
        if val.lower() == "false":
            return "0"

    # Handle boolean columns
    if col_type == "bool":
        if val.lower() in ("true", "t", "1", "yes"):
            return "t"
        if val.lower() in ("false", "f", "0", "no"):
            return "f"
        return ""

    # Handle timestamp columns - convert ISO format
    if col_type in ("timestamp", "timestamptz"):
        # Remove the T and .000Z from ISO format
        val = val.replace("T", " ")
        if val.endswith(".000Z"):
            val = val[:-5]
        elif val.endswith("Z"):
            val = val[:-1]
        # Remove milliseconds like .441Z
        val = re.sub(r'\.\d+$', '', val)

    return val


def import_csv(full_path, table_name):
    """Import a single CSV file into a PostgreSQL table."""
    if not os.path.exists(full_path):
        print(f"  SKIP (file not found)")
        return False

    # Read CSV header
    with open(full_path, "r", encoding="utf-8-sig") as f:
        reader = csv.reader(f, delimiter=";", quotechar='"')
        try:
            csv_header = next(reader)
        except StopIteration:
            print(f"  SKIP (empty file)")
            return False

    table_cols = get_table_columns(table_name)
    col_types = get_column_types(table_name)

    if not table_cols:
        print(f"  SKIP (table '{table_name}' not found or no columns)")
        return False

    # Build column mapping: CSV index -> (db_column_name, type)
    col_mapping = []  # list of (csv_index, db_col_name, col_type)
    skipped_cols = []

    for i, csv_col in enumerate(csv_header):
        if csv_col.startswith("@"):
            continue

        # Check for explicit rename
        db_col = COLUMN_RENAME.get((table_name, csv_col), csv_col)

        if db_col in table_cols:
            col_type = col_types.get(db_col, "varchar")
            # Skip JSON columns entirely
            if col_type == "json" or col_type == "jsonb":
                skipped_cols.append(csv_col)
                continue
            col_mapping.append((i, db_col, col_type))
        else:
            skipped_cols.append(csv_col)

    if skipped_cols:
        print(f"  Skipped CSV columns: {', '.join(skipped_cols)}")

    if not col_mapping:
        print(f"  SKIP (no matching columns)")
        return False

    # Check that id is in the mapping
    has_id = any(db_col == "id" for _, db_col, _ in col_mapping)
    if not has_id and "id" in table_cols:
        print(f"  WARNING: 'id' column exists in table but not mapped from CSV!")

    # Create cleaned temp CSV
    temp_path = f"/tmp/import_{table_name}.csv"
    row_count = 0
    errors = 0
    seen_ids = set()

    with open(full_path, "r", encoding="utf-8-sig") as fin, \
         open(temp_path, "w", encoding="utf-8", newline="") as fout:
        reader = csv.reader(fin, delimiter=";", quotechar='"')
        writer = csv.writer(fout, delimiter="\t", quotechar='"', quoting=csv.QUOTE_ALL)

        next(reader)  # skip header

        for row_num, row in enumerate(reader, start=1):
            if not row or (len(row) == 1 and not row[0].strip()):
                continue

            new_row = []
            skip_row = False
            for csv_idx, db_col, col_type in col_mapping:
                raw_val = row[csv_idx] if csv_idx < len(row) else ""
                cleaned = clean_value(raw_val, col_type)
                new_row.append(cleaned)

            # Skip rows where id is empty (if table has id as PK)
            if has_id:
                id_idx = next(j for j, (_, db_col, _) in enumerate(col_mapping) if db_col == "id")
                id_val = new_row[id_idx]
                if not id_val:
                    errors += 1
                    continue
                # Skip duplicate ids
                if id_val in seen_ids:
                    errors += 1
                    continue
                seen_ids.add(id_val)

            writer.writerow(new_row)
            row_count += 1

    if row_count == 0:
        print(f"  SKIP (no valid data rows, {errors} skipped)")
        if os.path.exists(temp_path):
            os.remove(temp_path)
        return False

    # Build column list
    db_cols = [db_col for _, db_col, _ in col_mapping]
    cols_str = ", ".join(f'"{c}"' for c in db_cols)

    # Use COPY with CSV format (handles quotes, newlines in data properly)
    # FORCE_NULL treats quoted empty strings "" as NULL (needed because QUOTE_ALL wraps everything)
    force_null_str = ", ".join(f'"{c}"' for c in db_cols)
    copy_sql = f"\\copy \"{table_name}\" ({cols_str}) FROM '{temp_path}' WITH (FORMAT csv, DELIMITER E'\\t', QUOTE '\"', NULL '', FORCE_NULL ({force_null_str}))"

    result = subprocess.run(
        ["psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST, "-c", copy_sql],
        capture_output=True, text=True, env=psql_env()
    )

    if os.path.exists(temp_path):
        os.remove(temp_path)

    if result.returncode != 0:
        err = result.stderr.strip()
        # Show first 3 lines of error
        err_lines = err.split("\n")[:3]
        print(f"  ERROR: {' | '.join(err_lines)}")
        if errors:
            print(f"  ({errors} rows also skipped due to empty id)")
        return False

    print(f"  OK: {row_count} rows" + (f" ({errors} skipped)" if errors else ""))
    return True


def update_sequences():
    """Update all sequences to max(id) + 1."""
    print("\n=== Updating sequences ===")
    all_tables = get_all_tables()
    for table in all_tables:
        cols = get_table_columns(table)
        if "id" not in cols:
            continue
        sql = (
            f"SELECT setval(pg_get_serial_sequence('\"{table}\"', 'id'), "
            f"COALESCE((SELECT MAX(\"id\") FROM \"{table}\"), 0) + 1, false) "
            f"WHERE pg_get_serial_sequence('\"{table}\"', 'id') IS NOT NULL;"
        )
        run_psql(sql)
    print("Done.")


def main():
    print("=== Discovering CSV files ===")
    all_csv = discover_csv_files(BASE_DIR)
    print(f"Found {len(all_csv)} CSV files\n")

    # Get all tables
    all_tables = get_all_tables()
    all_tables_lower = {t.lower(): t for t in all_tables}
    print(f"Found {len(all_tables)} tables in database\n")

    print("=== Disabling triggers ===")
    for t in all_tables:
        run_psql(f'ALTER TABLE "{t}" DISABLE TRIGGER ALL;')
    print(f"Disabled triggers on {len(all_tables)} tables\n")

    print("=== Truncating tables ===")
    for t in all_tables:
        run_psql(f'TRUNCATE TABLE "{t}" CASCADE;')
    print(f"Truncated {len(all_tables)} tables\n")

    print("=== Importing CSV files ===")
    success = 0
    failed = 0
    skipped = 0

    for rel_path, full_path in sorted(all_csv):
        filename = os.path.basename(rel_path)
        table_name = get_table_name(filename)

        if table_name is None:
            skipped += 1
            continue

        # Skip report_* tables
        if table_name.startswith("report_"):
            skipped += 1
            continue

        # Find actual table name (case-insensitive match)
        actual_table = all_tables_lower.get(table_name.lower())
        if not actual_table:
            # Try exact match
            if table_name in all_tables:
                actual_table = table_name
            else:
                print(f"\n[???] {rel_path} -> no table '{table_name}'")
                failed += 1
                continue

        print(f"\n[{actual_table}] <- {rel_path}")
        if import_csv(full_path, actual_table):
            success += 1
        else:
            failed += 1

    print(f"\n=== Re-enabling triggers ===")
    for t in all_tables:
        run_psql(f'ALTER TABLE "{t}" ENABLE TRIGGER ALL;')

    update_sequences()

    print(f"\n=== RESULTS ===")
    print(f"Success: {success}")
    print(f"Failed:  {failed}")
    print(f"Skipped: {skipped}")


if __name__ == "__main__":
    main()

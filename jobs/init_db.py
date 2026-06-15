#!/usr/bin/env python3
"""Initialize MySQL database from schema.sql"""

from __future__ import annotations

import sys
from pathlib import Path

import pymysql

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from config.settings import DB_HOST, DB_NAME, DB_PASSWORD, DB_PORT, DB_USER  # noqa: E402


def main():
    schema_path = ROOT / "db" / "schema.sql"
    sql = schema_path.read_text(encoding="utf-8")

    conn = pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        charset="utf8mb4",
        autocommit=True,
    )
    try:
        with conn.cursor() as cur:
            for statement in sql.split(";"):
                stmt = statement.strip()
                if stmt:
                    cur.execute(stmt)
        print(f"Database '{DB_NAME}' initialized successfully.")
    finally:
        conn.close()


if __name__ == "__main__":
    main()

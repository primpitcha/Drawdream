#!/usr/bin/env python3
"""ทดสอบเชื่อม MySQL เดียวกับ DrawDream (อ่าน config/db.local.php หรือ env)."""
from __future__ import annotations

import os
import re
import sys

try:
    import mysql.connector
except ImportError:
    print("รัน: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)


def load_php_return_array(path: str) -> dict:
    if not os.path.isfile(path):
        return {}
    text = open(path, encoding="utf-8").read()
    out = {}
    for key in ("host", "user", "password", "database"):
        m = re.search(r"'" + key + r"'\s*=>\s*'([^']*)'", text)
        if m:
            out[key] = m.group(1)
        else:
            m2 = re.search(r"'" + key + r"'\s*=>\s*\"([^\"]*)\"", text)
            if m2:
                out[key] = m2.group(1)
    m = re.search(r"'port'\s*=>\s*(\d+)", text)
    if m:
        out["port"] = int(m.group(1))
    return out


def main() -> int:
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    local = os.path.join(root, "config", "db.local.php")
    cfg = load_php_return_array(local) if os.path.isfile(local) else {}

    host = os.environ.get("DB_HOST", cfg.get("host", "localhost"))
    port = int(os.environ.get("DB_PORT", cfg.get("port", 3306)))
    user = os.environ.get("DB_USER", cfg.get("user", "root"))
    password = os.environ.get("DB_PASSWORD", cfg.get("password", ""))
    database = os.environ.get("DB_NAME", cfg.get("database", "drawdream_db"))

    try:
        conn = mysql.connector.connect(
            host=host,
            port=port,
            user=user,
            password=password,
            database=database,
        )
        cur = conn.cursor()
        cur.execute("SELECT 1")
        cur.fetchone()
        cur.close()
        conn.close()
    except mysql.connector.Error as e:
        print("เชื่อมต่อไม่สำเร็จ:", e, file=sys.stderr)
        return 1

    print("OK — เชื่อม", database, "ที่", f"{host}:{port}", "สำเร็จ")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

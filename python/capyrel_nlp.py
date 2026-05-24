#!/usr/bin/env python3
"""
capyrel_nlp.py — Capyrel natural-language CLI bridge

Called by NewProjectCommand.php via proc_open / shell_exec.
All output is newline-delimited JSON written to stdout.
Errors go to stderr; exit code 1 on failure.

Usage:
  python3 capyrel_nlp.py describe "a blog with posts, tags, and authors"
  python3 capyrel_nlp.py analyze  "title, body:text, published_at, is_featured"
  python3 capyrel_nlp.py fields   "Post"
  python3 capyrel_nlp.py plan     "Post" '["title","body:text","user_id"]' '["User","Tag"]'

Output shapes (back-compat with original):

  describe →  {"entities": [...], "relations": [...], "plan": {...}}
  analyze  →  {"fields": [...]}
  fields   →  {"fields": [...], "archetype": "..."}
  plan     →  {<single model plan dict>}
"""

from __future__ import annotations
import sys
import os
import json
import argparse
from typing import Any, List, Optional

# Ensure the python/ directory is on sys.path so all sub-packages resolve.
_HERE = os.path.dirname(os.path.abspath(__file__))
if _HERE not in sys.path:
    sys.path.insert(0, _HERE)

from engine.pipeline import Pipeline
from engine.context import ProjectContext, InstalledPackage


def _pipeline(
    packages: Optional[List[str]] = None,
    known_tables: Optional[List[str]] = None,
    session_entities: Optional[List[str]] = None,
) -> Pipeline:
    ctx = ProjectContext(
        packages=[InstalledPackage(name=p) for p in (packages or [])],
        migrated_tables=list(known_tables or []),
        session_entities=list(session_entities or []),
    )
    return Pipeline(ctx)


# ── Commands ──────────────────────────────────────────────────────────────────

def cmd_describe(text: str, **kw: Any) -> dict:
    p = _pipeline(**kw)
    result = p.describe(text)
    return {
        "entities":  result["entities"],
        "relations": result["relations"],
        "plan":      result.get("plan", {}),
    }


def cmd_analyze(field_string: str, **kw: Any) -> dict:
    p = _pipeline(**kw)
    result = p.analyze_fields(field_string, kw.get("known_tables"))
    return {"fields": result["fields"]}


def cmd_fields(entity: str, **kw: Any) -> dict:
    p = _pipeline(**kw)
    return p.suggest_fields(entity)


def cmd_plan(
    entity: str,
    field_specs: List[str],
    known_entities: Optional[List[str]] = None,
    soft_deletes: bool = False,
    **kw: Any,
) -> dict:
    p = _pipeline(session_entities=known_entities, **kw)
    return p.build_model_plan(entity, field_specs, soft_deletes, known_entities)


# ── CLI ───────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        prog="capyrel_nlp",
        description="Capyrel intelligence CLI — outputs JSON",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    # describe
    desc_p = sub.add_parser("describe", help="Extract entities from free-text description")
    desc_p.add_argument("text", help="Project description")
    desc_p.add_argument("--packages", default="", help="Comma-separated composer package names")
    desc_p.add_argument("--tables",   default="", help="Comma-separated known table names")

    # analyze
    ana_p = sub.add_parser("analyze", help="Infer field types from a field-spec string")
    ana_p.add_argument("fields", help="Comma-separated field specs")
    ana_p.add_argument("--tables", default="", help="Comma-separated known table names")

    # fields
    fld_p = sub.add_parser("fields", help="Suggest default fields for an entity archetype")
    fld_p.add_argument("entity", help="Entity name (e.g. Post, Product, User)")

    # plan
    plan_p = sub.add_parser("plan", help="Build a full model plan for one entity")
    plan_p.add_argument("entity",          help="StudlyCase entity name")
    plan_p.add_argument("field_specs",     help="JSON array of field spec strings")
    plan_p.add_argument("known_entities",  nargs="?", default="[]",
                        help="JSON array of already-defined entity names")
    plan_p.add_argument("--soft-deletes",  action="store_true")

    args = parser.parse_args()

    try:
        if args.command == "describe":
            packages     = [p for p in args.packages.split(",") if p]
            known_tables = [t for t in args.tables.split(",")   if t]
            result = cmd_describe(args.text, packages=packages, known_tables=known_tables)

        elif args.command == "analyze":
            known_tables = [t for t in args.tables.split(",") if t]
            result = cmd_analyze(args.fields, known_tables=known_tables)

        elif args.command == "fields":
            result = cmd_fields(args.entity)

        elif args.command == "plan":
            field_specs    = json.loads(args.field_specs)
            known_entities = json.loads(args.known_entities)
            result = cmd_plan(
                args.entity,
                field_specs,
                known_entities=known_entities,
                soft_deletes=args.soft_deletes,
            )

        else:
            print(json.dumps({"error": f"Unknown command: {args.command}"}), file=sys.stderr)
            sys.exit(1)

        print(json.dumps(result, ensure_ascii=False))

    except Exception as exc:
        import traceback
        print(json.dumps({"error": str(exc), "trace": traceback.format_exc()}), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()

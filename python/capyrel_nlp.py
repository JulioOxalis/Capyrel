#!/usr/bin/env python3
"""
capyrel_nlp.py — Capyrel natural-language helper (stdlib only, no external deps)

Called by NewProjectCommand via proc_open / shell_exec.
All output is newline-delimited JSON written to stdout.
Errors go to stderr; exit code 1 on failure.

Usage:
  python3 capyrel_nlp.py describe "a blog with posts, tags, and authors"
  python3 capyrel_nlp.py analyze  "title, body:text, published_at, is_featured"
  python3 capyrel_nlp.py fields   "Post"   (suggest sensible default fields for an entity)

Output — describe:
  {"entities": ["Post", "Tag", "Author"], "relations": [{"from": "Post", "to": "Tag", "type": "belongsToMany"}]}

Output — analyze:
  {"fields": [{"name": "title", "type": "string", "nullable": false}, ...]}

Output — fields:
  {"fields": ["title", "body:text", "published_at:timestamp", "is_published:boolean", "user_id"]}
"""

from __future__ import annotations
import sys
import re
import json
import argparse
from typing import Any


# ── Stop words ────────────────────────────────────────────────────────────────

STOP_WORDS = {
    "a", "an", "the", "and", "or", "but", "with", "without", "for", "to",
    "of", "in", "on", "at", "by", "from", "as", "into", "that", "this",
    "these", "those", "its", "my", "your", "their", "our", "is", "are",
    "was", "were", "be", "been", "have", "has", "had", "will", "would",
    "can", "could", "should", "need", "some", "any", "each", "every",
    "where", "when", "how", "which", "who", "also", "system", "platform",
    "application", "app", "management", "module", "feature", "project",
    "website", "like", "such", "including", "allow", "lets", "users",
    "people", "person", "create", "edit", "delete", "view", "manage",
    "multiple", "single", "many", "one", "between", "through", "about",
    "use", "using", "used", "build", "building", "make", "making", "add",
    "adding", "store", "storing", "track", "tracking",
}

LEAD_WORDS = {
    "with", "including", "has", "have", "contains", "consisting", "needs",
    "require", "requires", "manages", "supports", "tracks", "stores",
    "called", "named", "like", "such as", "e.g", "eg",
}

# Relationship indicators in sentences
REL_PATTERNS = [
    (r"(\w+)\s+(?:has|have|can have)\s+(?:many|multiple|several)\s+(\w+)", "hasMany"),
    (r"(\w+)\s+belongs?\s+to\s+(?:a|an|one|many)?\s*(\w+)", "belongsTo"),
    (r"(\w+)\s+and\s+(\w+)\s+(?:are\s+)?(?:many.to.many|linked|associated)", "belongsToMany"),
    (r"(\w+)\s+(?:is\s+)?(?:owned|written|created|authored)\s+by\s+(?:a|an)?\s*(\w+)", "belongsTo"),
    (r"(\w+)\s+(?:can\s+have\s+)?(?:tags?|categories|labels)\s+", "belongsToMany"),
]

# Common entity archetype field templates
ARCHETYPE_FIELDS: dict[str, list[str]] = {
    "user":         ["name", "email:string:unique", "password", "avatar:string:null", "role:string"],
    "post":         ["title", "slug:string:unique", "body:text", "published_at:timestamp:null", "is_published:boolean", "user_id"],
    "article":      ["title", "slug:string:unique", "body:text", "published_at:timestamp:null", "reading_time:integer", "user_id"],
    "product":      ["name", "slug:string:unique", "description:text:null", "price:decimal", "stock:integer", "is_active:boolean"],
    "order":        ["reference:string:unique", "total:decimal", "status:string", "notes:text:null", "user_id"],
    "comment":      ["body:text", "approved:boolean", "user_id", "post_id"],
    "category":     ["name", "slug:string:unique", "description:text:null", "parent_id:integer:null"],
    "tag":          ["name", "slug:string:unique"],
    "image":        ["path", "alt:string:null", "disk:string", "size:integer:null"],
    "notification": ["type", "data:json", "read_at:timestamp:null", "user_id"],
    "invoice":      ["number:string:unique", "amount:decimal", "tax:decimal", "status:string", "due_at:timestamp:null", "user_id"],
    "payment":      ["amount:decimal", "method:string", "status:string", "reference:string:null", "user_id"],
    "subscription": ["plan:string", "status:string", "trial_ends_at:timestamp:null", "ends_at:timestamp:null", "user_id"],
    "review":       ["rating:integer", "body:text:null", "approved:boolean", "user_id"],
    "message":      ["body:text", "read_at:timestamp:null", "sender_id", "receiver_id"],
    "event":        ["title", "description:text:null", "starts_at:timestamp", "ends_at:timestamp:null", "location:string:null"],
    "address":      ["line1:string", "line2:string:null", "city:string", "country:string", "postal_code:string"],
    "setting":      ["key:string:unique", "value:text:null", "group:string:null"],
    "audit_log":    ["action:string", "model_type:string", "model_id:integer", "payload:json:null", "user_id:integer:null"],
    "team":         ["name", "slug:string:unique", "owner_id"],
    "role":         ["name:string:unique", "description:string:null"],
    "permission":   ["name:string:unique", "guard:string"],
}


# ── Helpers ───────────────────────────────────────────────────────────────────

def to_studly(word: str) -> str:
    """'blog_post' → 'BlogPost', 'blogPost' → 'BlogPost'"""
    word = re.sub(r'[-\s]+', '_', word)
    return "".join(part.capitalize() for part in word.split("_"))


def to_singular(word: str) -> str:
    """Very simple English singulariser."""
    word = word.rstrip("'s")
    irregulars = {
        "people": "person", "men": "man", "women": "woman",
        "children": "child", "mice": "mouse", "geese": "goose",
    }
    if word.lower() in irregulars:
        return irregulars[word.lower()]
    if word.endswith("ies"):
        return word[:-3] + "y"
    if word.endswith("ves"):
        return word[:-3] + "f"
    if word.endswith("sses") or word.endswith("xes") or word.endswith("ches") or word.endswith("shes"):
        return word[:-2]
    if word.endswith("s") and not word.endswith("ss") and len(word) > 3:
        return word[:-1]
    return word


def clean_word(word: str) -> str:
    return re.sub(r"[^a-zA-Z0-9_]", "", word)


def is_stop(word: str) -> bool:
    return word.lower().strip(".,;:!?\"'()") in STOP_WORDS


# ── describe ──────────────────────────────────────────────────────────────────

def cmd_describe(text: str) -> dict[str, Any]:
    """
    Extract entity names and likely relations from a free-text description.
    """
    entities = _extract_entities(text)
    relations = _extract_relations(text, entities)

    return {"entities": entities, "relations": relations}


def _extract_entities(text: str) -> list[str]:
    candidates: list[str] = []

    # 1. Grab anything that looks like a direct list (commas)
    list_parts = re.split(r",\s*|\s+and\s+|\s+or\s+", text)
    if len(list_parts) > 1:
        for part in list_parts:
            words = part.strip().split()
            # Take the last meaningful noun in each chunk
            for w in reversed(words):
                cw = clean_word(w)
                if cw and not is_stop(cw) and len(cw) > 2:
                    candidates.append(cw)
                    break

    # 2. Scan for capitalised words (user typed them explicitly)
    for word in text.split():
        cw = clean_word(word)
        if cw and cw[0].isupper() and not is_stop(cw):
            candidates.append(cw)

    # 3. Look for words after lead words
    words = text.split()
    for i, word in enumerate(words):
        if word.lower().rstrip(".,;") in LEAD_WORDS and i + 1 < len(words):
            cw = clean_word(words[i + 1])
            if cw and not is_stop(cw) and len(cw) > 2:
                candidates.append(cw)

    # Normalise → singular StudlyCase, dedupe
    seen: set[str] = set()
    result: list[str] = []
    for c in candidates:
        normalised = to_studly(to_singular(c))
        if normalised and len(normalised) > 1 and normalised not in seen:
            seen.add(normalised)
            result.append(normalised)

    return result


def _extract_relations(text: str, entities: list[str]) -> list[dict[str, str]]:
    relations: list[dict[str, str]] = []
    seen: set[str] = set()

    for pattern, rel_type in REL_PATTERNS:
        for m in re.finditer(pattern, text, re.IGNORECASE):
            a = to_studly(to_singular(m.group(1)))
            b = to_studly(to_singular(m.group(2)))

            if a in STOP_WORDS or b in STOP_WORDS:
                continue

            key = f"{a}-{b}-{rel_type}"
            if key not in seen:
                seen.add(key)
                relations.append({"from": a, "to": b, "type": rel_type})

    # Implicit: if entity ends with known pattern, infer a relation
    for e in entities:
        lower = e.lower()
        if lower.endswith("comment") or lower.endswith("review"):
            for other in entities:
                if other != e and not any(r["from"] == e and r["to"] == other for r in relations):
                    relations.append({"from": e, "to": other, "type": "belongsTo"})
                    break

    return relations


# ── analyze ───────────────────────────────────────────────────────────────────

def cmd_analyze(field_string: str) -> dict[str, Any]:
    """
    Parse a comma-separated field string and infer types.
    e.g. "title, body:text, published_at, is_active, price:decimal, user_id"
    """
    parts = [p.strip() for p in re.split(r"[,;]+", field_string) if p.strip()]
    fields = [_infer_field(p) for p in parts]
    return {"fields": fields}


def _infer_field(spec: str) -> dict[str, Any]:
    """Parse a single field spec like 'title' or 'price:decimal' or 'slug:string:unique'."""
    parts    = spec.split(":")
    name     = parts[0].strip()
    explicit = parts[1].strip().lower() if len(parts) > 1 else None
    modifier = parts[2].strip().lower() if len(parts) > 2 else None

    inferred_type = explicit if explicit else _infer_type(name)

    nullable = modifier in ("null", "nullable") or _default_nullable(name, inferred_type)
    unique   = modifier == "unique"
    default  = _default_value(inferred_type)

    result: dict[str, Any] = {
        "name":     name,
        "type":     inferred_type,
        "nullable": nullable,
        "unique":   unique,
    }

    if default is not None:
        result["default"] = default

    if inferred_type == "decimal":
        result["precision"] = 10
        result["scale"]     = 2

    if inferred_type == "foreignId":
        import re as _re
        result["references"] = _re.sub(r"_id$", "s", name)

    return result


def _infer_type(name: str) -> str:
    lower = name.lower()

    if lower.endswith("_id"):                                         return "foreignId"
    if lower in ("created_at", "updated_at", "deleted_at"):          return "timestamp"
    if lower.endswith("_at") or lower.endswith("_date"):             return "timestamp"
    if lower.startswith("is_") or lower.startswith("has_"):          return "boolean"
    if lower.startswith("can_") or lower.startswith("should_"):      return "boolean"
    if lower in ("active", "enabled", "verified", "published",
                 "featured", "approved"):                             return "boolean"
    if lower in ("price", "cost", "amount", "total", "tax",
                 "balance", "fee", "discount"):                       return "decimal"
    if lower.endswith("_price") or lower.endswith("_amount"):        return "decimal"
    if lower in ("body", "content", "description", "notes",
                 "bio", "summary", "message", "text"):               return "text"
    if lower in ("meta", "settings", "options", "data",
                 "payload", "attributes"):                            return "json"
    if lower in ("count", "qty", "quantity", "stock", "views",
                 "likes", "votes", "rank", "position"):              return "integer"
    if lower.endswith("_count") or lower.endswith("_qty"):           return "integer"
    if lower.endswith("email"):                                       return "string"
    if lower.endswith("_uuid") or lower == "uuid":                   return "uuid"

    return "string"


def _default_nullable(name: str, type_: str) -> bool:
    if type_ == "timestamp" and name not in ("created_at", "updated_at"):
        return True
    if type_ in ("text", "json"):
        return True
    if name in ("bio", "notes", "description", "summary", "avatar", "cover"):
        return True
    return False


def _default_value(type_: str) -> Any:
    if type_ == "boolean":  return False
    if type_ == "integer":  return 0
    if type_ == "decimal":  return "0.00"
    return None


# ── fields ────────────────────────────────────────────────────────────────────

def cmd_fields(entity: str) -> dict[str, Any]:
    """
    Suggest sensible default fields for a known entity archetype.
    Falls back to a generic set when the entity is not recognised.
    """
    lower = entity.lower()

    # Try exact match first, then prefix/suffix match
    if lower in ARCHETYPE_FIELDS:
        return {"fields": ARCHETYPE_FIELDS[lower], "archetype": lower}

    for key, fields in ARCHETYPE_FIELDS.items():
        if lower.endswith(key) or lower.startswith(key):
            return {"fields": fields, "archetype": key}

    # Generic fallback
    return {
        "fields": ["name", "description:text:null", "is_active:boolean"],
        "archetype": "generic",
    }


# ── CLI ───────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        prog="capyrel_nlp",
        description="Capyrel natural-language helper — outputs JSON",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    desc_p = sub.add_parser("describe", help="Extract entities from free-text description")
    desc_p.add_argument("text", help="Project description")

    ana_p = sub.add_parser("analyze", help="Infer field types from a field-spec string")
    ana_p.add_argument("fields", help="Comma-separated field specs")

    fld_p = sub.add_parser("fields", help="Suggest default fields for an entity archetype")
    fld_p.add_argument("entity", help="Entity name (e.g. Post, Product, User)")

    args = parser.parse_args()

    try:
        if args.command == "describe":
            result = cmd_describe(args.text)
        elif args.command == "analyze":
            result = cmd_analyze(args.fields)
        elif args.command == "fields":
            result = cmd_fields(args.entity)
        else:
            print(json.dumps({"error": f"Unknown command: {args.command}"}), file=sys.stderr)
            sys.exit(1)

        print(json.dumps(result, ensure_ascii=False))

    except Exception as exc:
        print(json.dumps({"error": str(exc)}), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()

"""
engine/parser.py

Multi-pass parser.  Takes raw user input (free-text description or
field-spec string) and produces a partially-populated SchemaGraph that
the analyzers will enrich.

Passes
------
1. Tokenise / segment
2. Entity candidate extraction (list detection → capitalised words → lead-word scan)
3. Relation signal detection (regex patterns on the original text)
4. Field-spec parsing (name[:type[:modifier]] → Field with confidence)
5. Ambiguity flag (low-confidence nodes marked for user review)
"""

from __future__ import annotations
import re
from typing import Any, List, Dict, Tuple, Optional

from engine.schema_graph import SchemaGraph, ModelNode, Field, Relation
from engine.confidence import (
    ConfidenceScore, explicit, from_pattern, from_heuristic, inferred,
    HIGH, MEDIUM, LOW,
)
from engine.context import ProjectContext
from engine.utils import (
    to_singular, to_studly, to_snake_plural, split_studly, clean_word,
    fk_prefix, is_fk,
)


# ── Stop words ────────────────────────────────────────────────────────────────

STOP_WORDS: frozenset = frozenset({
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
})

LEAD_WORDS: frozenset = frozenset({
    "with", "including", "has", "have", "contains", "consisting", "needs",
    "require", "requires", "manages", "supports", "tracks", "stores",
    "called", "named", "like", "such as", "e.g", "eg",
})

# ── Relation patterns (regex, rel_type) ──────────────────────────────────────

REL_PATTERNS: List[Tuple[str, str]] = [
    (r"(\w+)\s+(?:has|have|can have)\s+(?:many|multiple|several)\s+(\w+)", "hasMany"),
    (r"(\w+)\s+belongs?\s+to\s+(?:a|an|one|many)?\s*(\w+)",               "belongsTo"),
    (r"(\w+)\s+and\s+(\w+)\s+(?:are\s+)?(?:many.to.many|linked|associated)", "belongsToMany"),
    (r"(\w+)\s+(?:is\s+)?(?:owned|written|created|authored)\s+by\s+(?:a|an)?\s*(\w+)", "belongsTo"),
    (r"(\w+)\s+(?:can\s+have\s+)?(?:tags?|categories|labels)\s+",         "belongsToMany"),
    (r"(\w+)\s+(?:has|have)\s+(?:one|a|an)\s+(\w+)",                      "hasOne"),
    (r"(\w+)\s+through\s+(?:a|an)?\s*(\w+)\s+(?:has|have)\s+(?:many)?\s*(\w+)", "hasManyThrough"),
    (r"(\w+)\s+(?:can\s+)?(?:morph|polymorphic)\s+(?:to|into)\s+(?:many)?\s*(\w+)", "morphMany"),
]

# ── Field type inference map ──────────────────────────────────────────────────

USER_ALIASES: frozenset = frozenset({
    "owner", "author", "creator", "editor", "updater", "deleter",
    "sender", "receiver", "recipient", "approver", "reviewer",
    "assigned", "assignee", "reporter", "moderator", "manager",
    "publisher", "subscriber", "inviter", "invitee", "operator",
    "member", "participant", "attendee", "follower", "contact",
})


# ── Parser ────────────────────────────────────────────────────────────────────

class Parser:
    """
    Stateless parser; call parse_description() or parse_field_specs().
    Returns a SchemaGraph (possibly partial) + list of ambiguities.
    """

    def __init__(self, ctx: ProjectContext):
        self.ctx = ctx

    # ── Public entry points ───────────────────────────────────────────────────

    def parse_description(self, text: str) -> Tuple[SchemaGraph, List[str]]:
        """
        Extract entities and relations from a free-text project description.
        Returns (graph, ambiguities).
        """
        graph       = SchemaGraph()
        ambiguities: List[str] = []

        entities    = self._extract_entities(text)
        raw_rels    = self._extract_raw_relations(text)

        for name, conf in entities:
            node = ModelNode(
                name=name,
                confidence=conf.score,
                source="inferred",
            )
            graph.add_model(node)
            if conf.is_low():
                ambiguities.append(f"Low-confidence entity: {name} ({conf.score:.2f})")

        for src, tgt, rel_type, conf in raw_rels:
            src_node = graph.get_model(src)
            if src_node and graph.has_model(tgt):
                src_node.relations.append(Relation(
                    type=rel_type,
                    target=tgt,
                    confidence=conf.score,
                    source="inferred",
                ))

        graph.metadata["raw_description"] = text
        return graph, ambiguities

    def parse_field_specs(
        self,
        specs:       List[str],
        known_tables: Optional[List[str]] = None,
    ) -> Tuple[List[Field], List[str]]:
        """
        Parse a list of 'name[:type[:modifier]]' specs into Fields.
        Returns (fields, warnings).
        """
        known = list(known_tables or []) + (self.ctx.known_tables())
        fields: List[Field]  = []
        warnings: List[str]  = []

        for spec in specs:
            spec = spec.strip()
            if not spec:
                continue
            f, w = self._parse_single_spec(spec, known)
            fields.append(f)
            warnings.extend(w)

        return fields, warnings

    # ── Entity extraction ─────────────────────────────────────────────────────

    def _extract_entities(self, text: str) -> List[Tuple[str, ConfidenceScore]]:
        scored: Dict[str, ConfidenceScore] = {}

        # Pass 1 — comma/and lists (highest confidence)
        list_parts = re.split(r",\s*|\s+and\s+|\s+or\s+", text)
        if len(list_parts) > 1:
            for part in list_parts:
                words = part.strip().split()
                for w in reversed(words):
                    cw = clean_word(w)
                    if cw and not self._is_stop(cw) and len(cw) > 2:
                        norm = to_studly(to_singular(cw))
                        if norm and norm not in scored:
                            scored[norm] = from_pattern(0.75, "found in comma/and list")
                        break

        # Pass 2 — explicitly capitalised words
        for word in text.split():
            cw = clean_word(word)
            if cw and cw[0].isupper() and not self._is_stop(cw) and len(cw) > 1:
                norm = to_studly(to_singular(cw))
                if norm:
                    existing = scored.get(norm)
                    if existing is None:
                        scored[norm] = from_pattern(0.80, "explicitly capitalised")
                    else:
                        # Boost if found by two passes
                        scored[norm] = ConfidenceScore(
                            score=min(1.0, existing.score + 0.10),
                            reasoning=existing.reasoning + " + capitalised",
                            source="pattern",
                        )

        # Pass 3 — words following lead-words
        words = text.split()
        for i, word in enumerate(words):
            if word.lower().rstrip(".,;") in LEAD_WORDS and i + 1 < len(words):
                cw = clean_word(words[i + 1])
                if cw and not self._is_stop(cw) and len(cw) > 2:
                    norm = to_studly(to_singular(cw))
                    if norm and norm not in scored:
                        scored[norm] = from_heuristic(0.55, f"follows lead-word '{word}'")

        # Filter to plausible model names (≥2 chars, not a stop word)
        result = [
            (name, conf)
            for name, conf in scored.items()
            if len(name) > 1 and name.lower() not in STOP_WORDS
        ]
        result.sort(key=lambda x: -x[1].score)
        return result

    # ── Relation extraction ───────────────────────────────────────────────────

    def _extract_raw_relations(
        self, text: str
    ) -> List[Tuple[str, str, str, ConfidenceScore]]:
        results: List[Tuple[str, str, str, ConfidenceScore]] = []
        seen: set = set()

        for pattern, rel_type in REL_PATTERNS:
            for m in re.finditer(pattern, text, re.IGNORECASE):
                a = to_studly(to_singular(m.group(1)))
                b = to_studly(to_singular(m.group(2)))
                if a.lower() in STOP_WORDS or b.lower() in STOP_WORDS:
                    continue
                key = f"{a}→{b}:{rel_type}"
                if key not in seen:
                    seen.add(key)
                    results.append((a, b, rel_type, from_pattern(0.70, f"matched pattern for {rel_type}")))

        return results

    # ── Field-spec parsing ────────────────────────────────────────────────────

    def _parse_single_spec(
        self, spec: str, known_tables: List[str]
    ) -> Tuple[Field, List[str]]:
        parts    = spec.split(":", 2)
        name     = parts[0].strip()
        explicit_type = parts[1].strip().lower() if len(parts) > 1 else None
        modifier = parts[2].strip().lower() if len(parts) > 2 else None
        warnings: List[str] = []

        if explicit_type:
            field_type = explicit_type
            conf = explicit("user specified type")
        else:
            field_type, conf = self._infer_type(name, known_tables)

        nullable = self._resolve_nullable(modifier, name, field_type)
        unique   = modifier == "unique"
        default  = self._default_value(field_type)

        refs: Optional[str] = None
        if field_type == "foreignId":
            refs = self._resolve_fk_table(fk_prefix(name), known_tables)
            if refs not in known_tables and not refs.startswith("users"):
                warnings.append(
                    f"FK '{name}' → '{refs}' but '{refs}' table not found in known tables"
                )

        hint: Optional[str] = None
        if field_type == "string" and name.lower() in ("status", "type", "role", "state", "gender"):
            hint = "Consider using an Enum cast"

        return Field(
            name=name,
            type=field_type,
            nullable=nullable,
            unique=unique,
            default=default,
            references=refs,
            hint=hint,
            confidence=conf.score,
        ), warnings

    def _infer_type(self, name: str, known_tables: List[str]) -> Tuple[str, ConfidenceScore]:
        lower = name.lower()

        if is_fk(lower):
            return "foreignId", from_pattern(0.95, "_id suffix → foreignId")
        if lower in ("created_at", "updated_at", "deleted_at"):
            return "timestamp", from_pattern(0.99, "Laravel magic column")
        if lower.endswith("_at") or lower.endswith("_date") or lower.endswith("_on"):
            return "timestamp", from_pattern(0.90, "date/time suffix")
        if lower.startswith(("is_", "has_", "can_", "should_")) or \
           lower in ("active", "enabled", "verified", "published", "featured", "approved"):
            return "boolean", from_pattern(0.92, "boolean prefix/name")
        if lower in ("price", "cost", "amount", "total", "subtotal", "tax",
                     "discount", "fee", "balance", "salary", "revenue") or \
           lower.endswith(("_price", "_amount", "_cost")):
            return "decimal", from_pattern(0.90, "money field name")
        if lower in ("count", "quantity", "qty", "stock", "views", "likes",
                     "votes", "rating", "rank", "position", "order", "sort", "priority") or \
           lower.endswith(("_count", "_qty", "_number")):
            return "integer", from_pattern(0.88, "counter/rank field name")
        if lower in ("body", "content", "description", "summary", "notes",
                     "bio", "details", "message", "text", "html", "markdown"):
            return "text", from_pattern(0.88, "long-text field name")
        if lower in ("meta", "settings", "options", "config", "data",
                     "payload", "attributes", "extra", "properties"):
            return "json", from_pattern(0.85, "json/config field name")
        if "email" in lower:
            return "string", from_pattern(0.90, "email field")
        if "url" in lower or "link" in lower or lower.endswith("_path"):
            return "string", from_pattern(0.85, "url/path field")
        if lower.endswith("_uuid") or lower == "uuid":
            return "uuid", from_pattern(0.95, "uuid suffix")
        if lower == "slug" or lower.endswith("_slug"):
            return "string", from_pattern(0.90, "slug field")
        if "token" in lower or "secret" in lower or "password" in lower:
            return "string", from_pattern(0.88, "sensitive field")

        return "string", from_heuristic(0.60, "default to string")

    def _resolve_fk_table(self, prefix: str, known_tables: List[str]) -> str:
        lower = prefix.lower()
        if lower in USER_ALIASES:
            return "users"
        from engine.utils import to_plural
        plural = to_plural(lower)
        if plural in known_tables:
            return plural
        # snake_case variant
        snake_plural = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", plural).lower()
        if snake_plural in known_tables:
            return snake_plural
        return plural

    @staticmethod
    def _resolve_nullable(modifier: Optional[str], name: str, type_: str) -> bool:
        if modifier in ("null", "nullable"):
            return True
        if type_ == "timestamp" and name not in ("created_at", "updated_at"):
            return True
        if type_ in ("text", "json"):
            return True
        if name in ("bio", "notes", "description", "summary", "avatar", "cover"):
            return True
        return False

    @staticmethod
    def _default_value(type_: str) -> Any:
        if type_ == "boolean": return False
        if type_ == "integer": return 0
        if type_ == "decimal": return "0.00"
        return None

    @staticmethod
    def _is_stop(word: str) -> bool:
        return word.lower().strip(".,;:!?\"'()") in STOP_WORDS

"""
engine/analyzer.py

SchemaGraph enrichment pass.

Takes a partially-populated graph (from Parser) and applies a series of
analysis rules to add / correct:
  - Missing inverse relations (hasMany ↔ belongsTo)
  - BelongsToMany detection for pivot-shaped models
  - FK field completeness (ensure belongsTo nodes have the FK field)
  - Archetype assignment + confidence re-scoring
  - Cast suggestions
  - Index suggestions (unique slugs, FK indexes, composite unique)
  - Soft-delete flag inheritance from similar archetypes
  - Warnings for anomalies (orphan FKs, missing timestamps, etc.)
"""

from __future__ import annotations
from typing import Dict, List, Optional, Set, Tuple

from engine.schema_graph import SchemaGraph, ModelNode, Field, Relation, Index, Cast
from engine.confidence import (
    ConfidenceScore, from_pattern, from_heuristic, inferred, HIGH, MEDIUM, LOW,
)
from engine.context import ProjectContext
from engine.utils import (
    to_studly, to_singular, to_plural, to_snake, to_snake_plural, split_studly,
)


# ── Archetype heuristics ──────────────────────────────────────────────────────

# (archetype_name, required_fields, optional_boosters, score)
ARCHETYPES: List[Tuple[str, List[str], List[str], float]] = [
    ("user",         ["email", "password"],                         ["name", "role"],        0.95),
    ("post",         ["title", "body"],                             ["slug", "published_at"], 0.90),
    ("article",      ["title", "body"],                             ["slug", "reading_time"], 0.88),
    ("product",      ["name", "price"],                             ["stock", "sku"],         0.90),
    ("order",        ["total", "status"],                           ["reference", "user_id"], 0.90),
    ("invoice",      ["amount", "status"],                          ["number", "due_at"],     0.88),
    ("payment",      ["amount", "method", "status"],                ["reference"],            0.88),
    ("subscription", ["status"],                                    ["ends_at", "plan"],      0.85),
    ("comment",      ["body"],                                      ["approved", "user_id"],  0.85),
    ("review",       ["rating", "body"],                            ["approved"],             0.88),
    ("category",     ["name", "slug"],                              ["parent_id"],            0.85),
    ("tag",          ["name", "slug"],                              [],                       0.85),
    ("notification", ["type", "data"],                              ["read_at", "user_id"],   0.88),
    ("message",      ["body"],                                      ["sender_id", "read_at"], 0.85),
    ("event",        ["title", "starts_at"],                        ["ends_at", "location"],  0.85),
    ("address",      ["line1", "city", "country"],                  ["postal_code"],          0.88),
    ("setting",      ["key", "value"],                              ["group"],                0.85),
    ("team",         ["name"],                                      ["slug", "owner_id"],     0.85),
    ("role",         ["name"],                                      ["guard"],                0.82),
    ("permission",   ["name"],                                      ["guard"],                0.82),
    ("media",        ["path"],                                      ["disk", "size"],         0.85),
    ("audit_log",    ["action", "model_type", "model_id"],          ["payload"],              0.90),
]


class Analyzer:

    def __init__(self, ctx: ProjectContext):
        self.ctx = ctx

    def analyze(self, graph: SchemaGraph) -> SchemaGraph:
        """Run all analysis passes in order and return the enriched graph."""
        self._assign_archetypes(graph)
        self._infer_missing_relations(graph)
        self._detect_pivot_models(graph)
        self._ensure_fk_fields(graph)
        self._suggest_casts(graph)
        self._suggest_indexes(graph)
        self._flag_soft_deletes(graph)
        self._cross_check_fk_references(graph)
        self._check_missing_timestamps(graph)
        self._flag_orphan_models(graph)
        return graph

    # ── Archetype assignment ──────────────────────────────────────────────────

    def _assign_archetypes(self, graph: SchemaGraph) -> None:
        for model in graph.models.values():
            if model.archetype:
                continue  # already set explicitly

            field_names = {f.name.lower() for f in model.fields}
            best_arch: Optional[str] = None
            best_score: float        = 0.0

            name_lower = model.name.lower()

            for arch, required, boosters, base_score in ARCHETYPES:
                # Name match gives a direct boost
                name_bonus = 0.05 if name_lower == arch or name_lower.endswith(arch) else 0.0

                # Required fields must all be present (or nearly)
                req_hit = sum(1 for r in required if r in field_names)
                if req_hit == 0 and not name_bonus:
                    continue

                req_ratio = req_hit / len(required) if required else 1.0
                boost_hit = sum(1 for b in boosters if b in field_names)
                boost_ratio = boost_hit / len(boosters) if boosters else 0.0

                score = base_score * (0.7 * req_ratio + 0.2 * boost_ratio + 0.1) + name_bonus

                if score > best_score:
                    best_score = score
                    best_arch  = arch

            if best_arch and best_score >= LOW:
                model.archetype = best_arch
                model.confidence = round(min(1.0, model.confidence * 0.5 + best_score * 0.5), 3)

    # ── Relation inference ────────────────────────────────────────────────────

    def _infer_missing_relations(self, graph: SchemaGraph) -> None:
        """
        For every belongsTo relation, ensure the target has a hasMany back.
        For every hasOne relation, ensure the target has a belongsTo back.
        """
        additions: List[Tuple[str, Relation]] = []

        for model_name, model in graph.models.items():
            for rel in model.relations:
                target = graph.get_model(rel.target)
                if not target:
                    continue

                if rel.type == "belongsTo":
                    if not target.has_relation_to(model_name):
                        additions.append((rel.target, Relation(
                            type="hasMany",
                            target=model_name,
                            foreign_key=rel.foreign_key,
                            confidence=round(rel.confidence * 0.90, 3),
                            source="inferred",
                        )))

                elif rel.type == "hasMany":
                    if not target.has_relation_to(model_name):
                        fk = to_snake(model_name) + "_id"
                        additions.append((rel.target, Relation(
                            type="belongsTo",
                            target=model_name,
                            foreign_key=fk,
                            confidence=round(rel.confidence * 0.90, 3),
                            source="inferred",
                        )))

                elif rel.type == "hasOne":
                    if not target.has_relation_to(model_name):
                        fk = to_snake(model_name) + "_id"
                        additions.append((rel.target, Relation(
                            type="belongsTo",
                            target=model_name,
                            foreign_key=fk,
                            confidence=round(rel.confidence * 0.88, 3),
                            source="inferred",
                        )))

        for target_name, rel in additions:
            node = graph.get_model(target_name)
            if node and not node.has_relation_to(rel.target):
                node.relations.append(rel)

    # ── Pivot detection ───────────────────────────────────────────────────────

    def _detect_pivot_models(self, graph: SchemaGraph) -> None:
        """
        Models that look like pivots → upgrade their parents to belongsToMany.
        """
        for model in list(graph.models.values()):
            if not model.is_pivot():
                continue

            fk_fields = model.get_fk_fields()
            if len(fk_fields) < 2:
                continue

            graph.pivot_tables.append(model.table or to_snake_plural(model.name))

            # Resolve the two sides of the pivot
            sides = []
            for fk in fk_fields[:2]:
                from engine.utils import fk_prefix
                prefix  = fk_prefix(fk.name)
                related  = to_studly(to_singular(prefix))
                sides.append((related, fk.name))

            if len(sides) == 2:
                (a_name, a_fk), (b_name, b_fk) = sides
                pivot_table = model.table or to_snake_plural(model.name)

                for src, tgt, src_fk, tgt_fk in [
                    (a_name, b_name, a_fk, b_fk),
                    (b_name, a_name, b_fk, a_fk),
                ]:
                    src_model = graph.get_model(src)
                    if src_model and not src_model.has_relation_to(tgt):
                        src_model.relations.append(Relation(
                            type="belongsToMany",
                            target=tgt,
                            foreign_key=src_fk,
                            local_key=tgt_fk,
                            pivot=pivot_table,
                            confidence=0.80,
                            source="inferred",
                        ))

    # ── FK field completeness ─────────────────────────────────────────────────

    def _ensure_fk_fields(self, graph: SchemaGraph) -> None:
        """
        Every belongsTo relation should have its FK column in the model's
        field list.  Add it as a foreignId if missing.
        """
        for model in graph.models.values():
            for rel in model.relations:
                if rel.type != "belongsTo":
                    continue
                fk = rel.foreign_key or (to_snake(rel.target) + "_id")
                if not model.get_field(fk):
                    target = graph.get_model(rel.target)
                    table  = (target.table if target else None) or to_snake_plural(rel.target)
                    model.fields.append(Field(
                        name=fk,
                        type="foreignId",
                        references=table,
                        confidence=0.85,
                    ))

    # ── Cast suggestions ──────────────────────────────────────────────────────

    def _suggest_casts(self, graph: SchemaGraph) -> None:
        TYPE_CAST = {
            "boolean":   "boolean",
            "timestamp": "datetime",
            "datetime":  "datetime",
            "date":      "date",
            "decimal":   "decimal:2",
            "float":     "decimal:2",
            "integer":   "integer",
            "bigInteger": "integer",
            "json":      "array",
            "jsonb":     "array",
        }
        for model in graph.models.values():
            existing = {c.field for c in model.casts}
            for f in model.fields:
                cast_to = TYPE_CAST.get(f.type)
                if cast_to and f.name not in existing:
                    model.casts.append(Cast(field=f.name, cast_to=cast_to))

    # ── Index suggestions ─────────────────────────────────────────────────────

    def _suggest_indexes(self, graph: SchemaGraph) -> None:
        for model in graph.models.values():
            existing_cols: Set[str] = {tuple(i.columns) for i in model.indexes}  # type: ignore

            for f in model.fields:
                # Unique fields already get a unique index from the migration
                if f.unique:
                    continue
                # FK fields should always be indexed
                if f.type == "foreignId" and (f.name,) not in existing_cols:
                    model.indexes.append(Index(columns=[f.name], unique=False))
                # Slug and email are frequently queried
                if f.name in ("slug", "email") and not f.unique:
                    model.indexes.append(Index(columns=[f.name], unique=True))

    # ── Soft-delete flag ──────────────────────────────────────────────────────

    def _flag_soft_deletes(self, graph: SchemaGraph) -> None:
        SOFT_DELETE_ARCHETYPES = {
            "user", "order", "product", "post", "article",
            "subscription", "invoice", "payment",
        }
        for model in graph.models.values():
            if model.soft_deletes:
                continue
            if model.archetype in SOFT_DELETE_ARCHETYPES:
                model.soft_deletes = True
                model.warnings.append(
                    f"soft_deletes enabled automatically (archetype: {model.archetype})"
                )

    # ── FK cross-check ────────────────────────────────────────────────────────

    def _cross_check_fk_references(self, graph: SchemaGraph) -> None:
        all_tables = set(graph.all_tables())
        known_tables = set(self.ctx.known_tables())
        all_known = all_tables | known_tables

        for model in graph.models.values():
            for f in model.get_fk_fields():
                refs = f.references
                if refs and refs not in all_known and refs != "users":
                    graph.add_warning(
                        f"{model.name}.{f.name} references '{refs}' but that table was not found",
                        model=model.name,
                        confidence=0.85,
                    )

    # ── Missing timestamps ────────────────────────────────────────────────────

    def _check_missing_timestamps(self, graph: SchemaGraph) -> None:
        for model in graph.models.values():
            if not model.timestamps:
                continue
            names = {f.name for f in model.fields}
            # Only warn if the user explicitly defined fields but omitted them
            if model.fields and "created_at" not in names and "updated_at" not in names:
                model.warnings.append(
                    "timestamps() is ON but created_at/updated_at not listed as explicit fields — "
                    "they will be added by Laravel automatically"
                )

    # ── Orphan models ─────────────────────────────────────────────────────────

    def _flag_orphan_models(self, graph: SchemaGraph) -> None:
        if len(graph.models) < 2:
            return
        for name, model in graph.models.items():
            has_incoming = bool(graph.incoming_relations(name))
            has_outgoing = bool(model.relations)
            if not has_incoming and not has_outgoing:
                graph.add_warning(
                    f"{name} has no relations to any other model — isolated model?",
                    model=name,
                    confidence=0.55,
                )

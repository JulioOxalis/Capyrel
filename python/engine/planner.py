"""
engine/planner.py

Converts an analyzed SchemaGraph into a structured Plan — a pure-data
representation of everything the renderers need.  No PHP is generated here.

The Plan is a dict that mirrors what NewProjectCommand.php expects when it
calls the Python bridge, so the PHP side can consume it directly.
"""

from __future__ import annotations
from typing import Any, Dict, List, Optional

from engine.schema_graph import SchemaGraph, ModelNode, Field, Relation
from engine.context import ProjectContext
from engine.utils import to_snake_plural, to_snake, to_studly, to_singular


# ── Plan shape ────────────────────────────────────────────────────────────────
#
# {
#   "models": {
#     "Post": {
#       "entity":      "Post",
#       "table":       "posts",
#       "fillable":    ["title", "body", "user_id"],
#       "casts":       {"body": "string", "published_at": "datetime"},
#       "timestamps":  true,
#       "soft_deletes": false,
#       "relations":   [{"type": "belongsTo", "related": "User", "foreign_key": "user_id"}],
#       "traits":      ["Searchable"],
#       "interfaces":  [],
#       "fields":      [...],
#       "indexes":     [...],
#       "warnings":    [...],
#       "archetype":   "post",
#       "confidence":  0.90,
#     },
#     ...
#   },
#   "pivot_tables": ["post_tag"],
#   "warnings":     [...],
#   "metadata":     {...},
# }


_SKIP_FILLABLE = frozenset({
    "id", "created_at", "updated_at", "deleted_at",
    "remember_token", "email_verified_at",
    "two_factor_secret", "two_factor_recovery_codes",
})


class Planner:

    def __init__(self, ctx: ProjectContext):
        self.ctx = ctx

    def plan(self, graph: SchemaGraph) -> Dict[str, Any]:
        """Produce the full plan dict from an analyzed graph."""
        models: Dict[str, Any] = {}

        for name, node in graph.models.items():
            models[name] = self._plan_model(node, graph)

        return {
            "models":       models,
            "pivot_tables": graph.pivot_tables,
            "warnings":     graph.warnings,
            "metadata":     {
                **graph.metadata,
                "context": self.ctx.summary_line(),
            },
        }

    # ── Per-model plan ────────────────────────────────────────────────────────

    def _plan_model(self, node: ModelNode, graph: SchemaGraph) -> Dict[str, Any]:
        table     = node.table or to_snake_plural(node.name)
        fillable  = self._fillable(node)
        casts     = {c.field: c.cast_to for c in node.casts}
        relations = [self._plan_relation(r) for r in node.relations]
        traits    = self._traits(node)
        fields    = [self._plan_field(f) for f in node.fields]
        indexes   = [{"columns": i.columns, "unique": i.unique} for i in node.indexes]

        return {
            "entity":       node.name,
            "table":        table,
            "fillable":     fillable,
            "casts":        casts,
            "timestamps":   node.timestamps,
            "soft_deletes": node.soft_deletes,
            "relations":    relations,
            "traits":       traits,
            "interfaces":   node.interfaces,
            "fields":       fields,
            "indexes":      indexes,
            "warnings":     node.warnings,
            "archetype":    node.archetype,
            "confidence":   node.confidence,
        }

    @staticmethod
    def _fillable(node: ModelNode) -> List[str]:
        return [
            f.name for f in node.fields
            if f.name not in _SKIP_FILLABLE
        ]

    @staticmethod
    def _plan_field(f: Field) -> Dict[str, Any]:
        d: Dict[str, Any] = {
            "name":       f.name,
            "type":       f.type,
            "nullable":   f.nullable,
            "unique":     f.unique,
            "confidence": f.confidence,
        }
        if f.default is not None:
            d["default"] = f.default
        if f.references:
            d["references"] = f.references
        if f.precision is not None:
            d["precision"] = f.precision
        if f.scale is not None:
            d["scale"] = f.scale
        if f.hint:
            d["hint"] = f.hint
        return d

    @staticmethod
    def _plan_relation(r: Relation) -> Dict[str, Any]:
        d: Dict[str, Any] = {
            "type":       r.type,
            "related":    r.target,
            "confidence": r.confidence,
            "source":     r.source,
        }
        if r.foreign_key:
            d["foreign_key"] = r.foreign_key
        if r.local_key:
            d["local_key"] = r.local_key
        if r.pivot:
            d["pivot"] = r.pivot
        if r.through:
            d["through"] = r.through
        if r.morph_name:
            d["morph_name"] = r.morph_name
        if r.eager_load:
            d["eager_load"] = True
        return d

    def _traits(self, node: ModelNode) -> List[str]:
        traits = list(node.traits)
        if self.ctx.has_scout() and "Searchable" not in traits:
            if node.archetype in ("post", "product", "user", "article"):
                traits.append("Searchable")
        if self.ctx.has_spatie_media() and "InteractsWithMedia" not in traits:
            if node.archetype in ("product", "user", "post"):
                traits.append("InteractsWithMedia")
        return traits

"""
modules/relations/detector.py

Relation detector with full Laravel relation type support:
  - hasOne, hasMany
  - belongsTo
  - belongsToMany (via pivot)
  - hasManyThrough
  - morphTo / morphMany / morphOne / morphToMany
  - Inverse completeness checker

Works on an already-built SchemaGraph to add or validate relations.
"""

from __future__ import annotations
from typing import List, Optional, Tuple

from engine.schema_graph import SchemaGraph, ModelNode, Relation
from engine.confidence import from_pattern, from_heuristic, ConfidenceScore
from engine.context import ProjectContext
from engine.utils import to_snake, to_studly, to_singular, to_snake_plural


MORPH_SUFFIXES = ("_type", "_id")   # polymorphic column pairs

# Models that are commonly morphable targets
MORPH_TARGETS = frozenset({
    "Comment", "Like", "Reaction", "Tag", "Activity",
    "Notification", "AuditLog", "Media", "Rating", "Report",
})


class RelationDetector:

    def __init__(self, ctx: ProjectContext):
        self.ctx = ctx

    def detect_all(self, graph: SchemaGraph) -> SchemaGraph:
        """Run all relation detection passes and return enriched graph."""
        self._detect_morph_columns(graph)
        self._detect_has_many_through(graph)
        self._ensure_inverses(graph)
        return graph

    # ── Polymorphic detection ─────────────────────────────────────────────────

    def _detect_morph_columns(self, graph: SchemaGraph) -> None:
        """
        Find models with {name}_type + {name}_id column pairs.
        Those models can morphTo; potential parents get morphMany.
        """
        for model in graph.models.values():
            field_names = {f.name for f in model.fields}
            seen_morphs = set()

            for f in model.fields:
                if f.name.endswith("_type"):
                    base = f.name[:-5]  # strip _type
                    fk   = base + "_id"
                    if fk in field_names and base not in seen_morphs:
                        seen_morphs.add(base)
                        # This model can morphTo
                        if not any(r.type == "morphTo" for r in model.relations):
                            model.relations.append(Relation(
                                type="morphTo",
                                target=to_studly(base),
                                morph_name=base,
                                confidence=0.88,
                                source="inferred",
                            ))
                        # Add morphMany to any model in the graph that might own this
                        for other_name, other in graph.models.items():
                            if other_name != model.name and other_name in MORPH_TARGETS:
                                continue
                            if model.name in MORPH_TARGETS and not other.has_relation_to(model.name):
                                other.relations.append(Relation(
                                    type="morphMany",
                                    target=model.name,
                                    morph_name=base,
                                    confidence=0.60,
                                    source="inferred",
                                ))

    # ── hasManyThrough ────────────────────────────────────────────────────────

    def _detect_has_many_through(self, graph: SchemaGraph) -> None:
        """
        Detect A → B → C chains where A should have hasManyThrough C via B.
        Only fires when confidence is high (explicit FK chain).
        """
        for a_name, a_model in graph.models.items():
            for rel_ab in a_model.relations:
                if rel_ab.type != "hasMany":
                    continue
                b_name  = rel_ab.target
                b_model = graph.get_model(b_name)
                if not b_model:
                    continue
                for rel_bc in b_model.relations:
                    if rel_bc.type != "hasMany":
                        continue
                    c_name = rel_bc.target
                    if c_name == a_name:
                        continue
                    if not a_model.has_relation_to(c_name):
                        a_model.relations.append(Relation(
                            type="hasManyThrough",
                            target=c_name,
                            through=b_name,
                            confidence=0.72,
                            source="inferred",
                        ))

    # ── Inverse completeness ──────────────────────────────────────────────────

    def _ensure_inverses(self, graph: SchemaGraph) -> None:
        additions: List[Tuple[str, Relation]] = []

        for model_name, model in graph.models.items():
            for rel in model.relations:
                target = graph.get_model(rel.target)
                if not target:
                    continue

                if rel.type == "belongsToMany" and not target.has_relation_to(model_name):
                    additions.append((rel.target, Relation(
                        type="belongsToMany",
                        target=model_name,
                        foreign_key=rel.local_key,
                        local_key=rel.foreign_key,
                        pivot=rel.pivot,
                        confidence=round(rel.confidence * 0.90, 3),
                        source="inferred",
                    )))

                elif rel.type == "morphMany" and not target.has_relation_to(model_name):
                    additions.append((rel.target, Relation(
                        type="morphTo",
                        target=model_name,
                        morph_name=rel.morph_name,
                        confidence=round(rel.confidence * 0.85, 3),
                        source="inferred",
                    )))

        for target_name, rel in additions:
            node = graph.get_model(target_name)
            if node and not node.has_relation_to(rel.target):
                node.relations.append(rel)

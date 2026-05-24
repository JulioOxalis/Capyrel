"""
engine/schema_graph.py

The single source of truth for all Capyrel intelligence.

Built on the starter foundation provided, extended with:
- Index and cast definitions
- Soft-delete / timestamps flags
- Pivot table tracking
- Confidence scores on every node
- Graph traversal helpers
"""

from __future__ import annotations
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Any


# ── Leaf types ────────────────────────────────────────────────────────────────

@dataclass
class Field:
    name: str
    type: str
    nullable: bool = False
    unique: bool = False
    indexed: bool = False
    default: Optional[Any] = None
    precision: Optional[int] = None   # for decimal types
    scale: Optional[int] = None
    references: Optional[str] = None  # table name for foreignId
    hint: Optional[str] = None        # human-readable note (e.g. "Consider Enum cast")
    confidence: float = 1.0


@dataclass
class Relation:
    type: str                          # hasMany, belongsTo, belongsToMany, morphMany…
    target: str                        # StudlyCase model name
    foreign_key: Optional[str] = None
    local_key: Optional[str] = None
    pivot: Optional[str] = None        # pivot table name for BTM
    morph_name: Optional[str] = None   # for polymorphic relations
    through: Optional[str] = None      # for hasManyThrough
    eager_load: bool = False           # whether to suggest with()
    confidence: float = 1.0
    source: str = "inferred"           # "explicit" | "inferred" | "pattern"


@dataclass
class Index:
    columns: List[str]
    unique: bool = False
    name: Optional[str] = None


@dataclass
class Cast:
    field: str
    cast_to: str                       # "datetime", "boolean", "decimal:2", "array"…


# ── Model node ────────────────────────────────────────────────────────────────

@dataclass
class ModelNode:
    name: str
    table: Optional[str] = None        # snake_plural; inferred if None
    fields: List[Field] = field(default_factory=list)
    relations: List[Relation] = field(default_factory=list)
    indexes: List[Index] = field(default_factory=list)
    casts: List[Cast] = field(default_factory=list)
    traits: List[str] = field(default_factory=list)
    interfaces: List[str] = field(default_factory=list)
    timestamps: bool = True
    soft_deletes: bool = False
    fillable: List[str] = field(default_factory=list)
    archetype: Optional[str] = None   # "post", "product", "user"…
    confidence: float = 1.0
    source: str = "inferred"          # "explicit" | "inferred" | "existing"
    warnings: List[str] = field(default_factory=list)

    def get_field(self, name: str) -> Optional[Field]:
        return next((f for f in self.fields if f.name == name), None)

    def get_fk_fields(self) -> List[Field]:
        return [f for f in self.fields if f.type == "foreignId" or f.name.endswith("_id")]

    def get_relations_of_type(self, rel_type: str) -> List[Relation]:
        return [r for r in self.relations if r.type == rel_type]

    def has_relation_to(self, target: str) -> bool:
        return any(r.target == target for r in self.relations)

    def is_pivot(self) -> bool:
        """A model is likely a pivot/junction if it has exactly 2+ FK fields and few others."""
        fks = self.get_fk_fields()
        non_fks = [f for f in self.fields if not f.name.endswith("_id") and f.name != "id"]
        return len(fks) >= 2 and len(non_fks) <= 3


# ── Schema graph ──────────────────────────────────────────────────────────────

@dataclass
class SchemaGraph:
    models: Dict[str, ModelNode] = field(default_factory=dict)
    pivot_tables: List[str] = field(default_factory=list)
    warnings: List[Dict[str, Any]] = field(default_factory=list)
    metadata: Dict[str, Any] = field(default_factory=dict)

    # ── Mutation ──────────────────────────────────────────────────────────────

    def add_model(self, model: ModelNode) -> None:
        self.models[model.name] = model

    def add_warning(self, message: str, model: Optional[str] = None,
                    confidence: float = 1.0) -> None:
        self.warnings.append({
            "message": message,
            "model": model,
            "confidence": confidence,
        })

    # ── Lookup ────────────────────────────────────────────────────────────────

    def get_model(self, name: str) -> Optional[ModelNode]:
        return self.models.get(name)

    def has_model(self, name: str) -> bool:
        return name in self.models

    def has_relation(self, source: str, target: str) -> bool:
        model = self.get_model(source)
        return bool(model and model.has_relation_to(target))

    def model_names(self) -> List[str]:
        return list(self.models.keys())

    # ── Graph traversal ───────────────────────────────────────────────────────

    def neighbours(self, model_name: str) -> List[str]:
        """All models directly related to the given model."""
        model = self.get_model(model_name)
        if not model:
            return []
        return [r.target for r in model.relations if r.target in self.models]

    def incoming_relations(self, target: str) -> List[tuple[str, Relation]]:
        """All (source_name, relation) pairs that point at target."""
        result = []
        for name, model in self.models.items():
            for rel in model.relations:
                if rel.target == target:
                    result.append((name, rel))
        return result

    def all_tables(self) -> List[str]:
        """snake_plural table names for all models."""
        import re
        tables = []
        for name, model in self.models.items():
            if model.table:
                tables.append(model.table)
            else:
                # Convert StudlyCase → snake_plural
                snake = re.sub(r'(?<=[a-z0-9])(?=[A-Z])', '_', name).lower()
                tables.append(snake + "s")
        return tables

    def pivot_models(self) -> List[ModelNode]:
        return [m for m in self.models.values() if m.is_pivot()]

    # ── Serialisation ─────────────────────────────────────────────────────────

    def to_dict(self) -> Dict[str, Any]:
        """Full JSON-serialisable representation."""
        return {
            "models": {
                name: {
                    "table": m.table,
                    "archetype": m.archetype,
                    "confidence": m.confidence,
                    "source": m.source,
                    "timestamps": m.timestamps,
                    "soft_deletes": m.soft_deletes,
                    "traits": m.traits,
                    "fillable": m.fillable,
                    "fields": [
                        {
                            "name": f.name,
                            "type": f.type,
                            "nullable": f.nullable,
                            "unique": f.unique,
                            "indexed": f.indexed,
                            "references": f.references,
                            "confidence": f.confidence,
                        }
                        for f in m.fields
                    ],
                    "relations": [
                        {
                            "type": r.type,
                            "target": r.target,
                            "foreign_key": r.foreign_key,
                            "pivot": r.pivot,
                            "through": r.through,
                            "eager_load": r.eager_load,
                            "confidence": r.confidence,
                            "source": r.source,
                        }
                        for r in m.relations
                    ],
                    "casts": [{"field": c.field, "cast_to": c.cast_to} for c in m.casts],
                    "indexes": [{"columns": i.columns, "unique": i.unique} for i in m.indexes],
                    "warnings": m.warnings,
                }
                for name, m in self.models.items()
            },
            "pivot_tables": self.pivot_tables,
            "warnings": self.warnings,
            "metadata": self.metadata,
        }

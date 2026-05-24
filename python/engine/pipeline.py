"""
engine/pipeline.py

Orchestrates the full engine pipeline:

  Input text / field specs
       ↓
    Parser          → partial SchemaGraph
       ↓
    Analyzer        → enriched SchemaGraph
       ↓
    Planner         → Plan dict
       ↓
    Renderer        → JSON / summary string

The Pipeline is the only public surface the CLI (capyrel_nlp.py) needs.
"""

from __future__ import annotations
from typing import Any, Dict, List, Optional, Tuple

from engine.schema_graph import SchemaGraph, ModelNode, Field, Relation
from engine.context import ProjectContext
from engine.parser import Parser
from engine.analyzer import Analyzer
from engine.planner import Planner
from engine.renderer import JsonRenderer, SummaryRenderer
from engine.utils import to_snake_plural


class Pipeline:

    def __init__(self, ctx: Optional[ProjectContext] = None):
        self.ctx      = ctx or ProjectContext()
        self.parser   = Parser(self.ctx)
        self.analyzer = Analyzer(self.ctx)
        self.planner  = Planner(self.ctx)
        self.json_r   = JsonRenderer()
        self.summary_r = SummaryRenderer()

    # ── High-level entry points ───────────────────────────────────────────────

    def describe(self, text: str) -> Dict[str, Any]:
        """
        Extract entities + relations from a free-text project description.
        Returns the same shape as the old capyrel_nlp.py describe command,
        plus a richer 'plan' key with the full engine output.
        """
        graph, ambiguities = self.parser.parse_description(text)
        graph = self.analyzer.analyze(graph)
        plan  = self.planner.plan(graph)

        # Back-compat shape: entities list + relations list
        entities = list(graph.models.keys())
        relations = []
        for name, model in graph.models.items():
            for rel in model.relations:
                relations.append({
                    "from": name,
                    "to":   rel.target,
                    "type": rel.type,
                })

        return {
            "entities":    entities,
            "relations":   relations,
            "plan":        plan,
            "ambiguities": ambiguities,
        }

    def analyze_fields(
        self,
        field_string: str,
        known_tables: Optional[List[str]] = None,
    ) -> Dict[str, Any]:
        """
        Parse and type-infer a comma-separated field spec string.
        Returns the same shape as the old analyze command.
        """
        import re
        specs  = [s.strip() for s in re.split(r"[,;]+", field_string) if s.strip()]
        fields, warnings = self.parser.parse_field_specs(specs, known_tables)

        return {
            "fields": [
                {
                    "name":       f.name,
                    "type":       f.type,
                    "nullable":   f.nullable,
                    "unique":     f.unique,
                    **({"default": f.default} if f.default is not None else {}),
                    **({"references": f.references} if f.references else {}),
                    "confidence": f.confidence,
                }
                for f in fields
            ],
            "warnings": warnings,
        }

    def suggest_fields(self, entity: str) -> Dict[str, Any]:
        """
        Suggest default field specs for a named entity archetype.
        Delegates to the knowledge base; falls back to generic.
        """
        from engine.analyzer import ARCHETYPES
        from knowledge.archetypes import registry as archetype_registry

        lower = entity.lower()
        result = archetype_registry.get(lower)
        if result:
            return result

        # Try suffix match
        for arch, *_ in ARCHETYPES:
            if lower.endswith(arch) or lower.startswith(arch):
                result = archetype_registry.get(arch)
                if result:
                    return {**result, "matched_as": arch}

        return {
            "fields":    ["name", "description:text:null", "is_active:boolean"],
            "archetype": "generic",
        }

    def build_model_plan(
        self,
        entity:         str,
        field_specs:    List[str],
        soft_deletes:   bool = False,
        known_entities: Optional[List[str]] = None,
    ) -> Dict[str, Any]:
        """
        Full single-model plan from an entity name + field specs.
        Used when PHP calls python for a specific model.
        """
        ctx = self.ctx
        if known_entities:
            for e in known_entities:
                ctx = ctx.with_entity(e)

        parser   = Parser(ctx)
        analyzer = Analyzer(ctx)
        planner  = Planner(ctx)

        known_tables = ctx.known_tables()
        fields, warnings = parser.parse_field_specs(field_specs, known_tables)

        node = ModelNode(
            name=entity,
            fields=fields,
            soft_deletes=soft_deletes,
            source="explicit",
        )
        graph = SchemaGraph()
        graph.add_model(node)

        # Add ghost models for known entities so the analyzer can resolve relations
        for ke in (known_entities or []):
            if ke != entity and not graph.has_model(ke):
                ghost = ModelNode(name=ke, source="existing", confidence=0.90)
                graph.add_model(ghost)

        graph = analyzer.analyze(graph)

        plan = planner.plan(graph)
        model_plan = plan["models"].get(entity, {})
        model_plan["warnings"] = list(set(
            model_plan.get("warnings", []) + warnings
        ))
        return model_plan

    def analyze_graph_dict(self, schema: Dict[str, Any]) -> Dict[str, Any]:
        """
        Full analysis on a pre-built schema dict from PHP's RelationshipDetector.

        Relations are ground truth (real FK constraints) — we skip parser
        inference and run enrichment-only passes, then layer on optimization,
        security, and architecture modules.

        Expected shape:
        {
          "models": {
            "Post": {
              "fields":    [{"name":"title","type":"string","nullable":false,...}],
              "relations": [{"type":"hasMany","target":"Comment","foreign_key":"post_id",...}],
              "fillable":  ["title","body"]   // optional, from existing model file
            }
          }
        }
        """
        graph = self._graph_from_dict(schema)

        # Enrichment only — do not infer relations we already have
        self.analyzer.enrich_ground_truth(graph)

        plan = self.planner.plan(graph)

        from modules.optimization.analyzer import OptimizationAnalyzer
        from modules.security.analyzer import SecurityAnalyzer
        from modules.architecture.analyzer import ArchitectureAnalyzer
        from modules.relations.detector import RelationDetector

        # Run polymorphic / hasManyThrough detection on top of ground truth
        RelationDetector(self.ctx).detect_all(graph)

        optimization  = OptimizationAnalyzer().analyze(graph)
        security      = SecurityAnalyzer().analyze(graph)
        architecture  = ArchitectureAnalyzer(self.ctx).analyze(graph)

        return {
            "plan":         plan,
            "optimization": optimization,
            "security":     security,
            "architecture": architecture,
        }

    def _graph_from_dict(self, schema: Dict[str, Any]) -> SchemaGraph:
        """
        Build a SchemaGraph from a PHP-serialised schema dict.
        All nodes start with confidence=1.0 (ground truth from live DB).
        """
        graph = SchemaGraph()

        # PHP type_name → Blueprint type
        TYPE_MAP: Dict[str, str] = {
            "varchar": "string",    "char": "string",
            "text": "text",         "mediumtext": "text",   "longtext": "text",
            "tinytext": "text",
            "int": "integer",       "integer": "integer",   "bigint": "integer",
            "smallint": "integer",  "mediumint": "integer",
            "tinyint": "boolean",   "boolean": "boolean",   "bool": "boolean",
            "decimal": "decimal",   "float": "decimal",     "double": "decimal",
            "numeric": "decimal",   "real": "decimal",
            "datetime": "timestamp","timestamp": "timestamp",
            "date": "date",
            "json": "json",         "jsonb": "json",
            "enum": "string",
            "uuid": "uuid",         "ulid": "string",
            "binary": "string",
        }

        for model_name, model_data in schema.get("models", {}).items():
            fields: List[Field] = []

            for f in model_data.get("fields", []):
                name     = f["name"]
                db_type  = f.get("type", "string")
                nullable = bool(f.get("nullable", False))
                unique   = bool(f.get("unique", False))

                # FK columns override type regardless of DB type
                if name.endswith("_id") and db_type not in ("uuid",):
                    field_type = "foreignId"
                    refs = f.get("references") or (name[:-3] + "s")  # naive plural
                else:
                    field_type = TYPE_MAP.get(db_type, "string")
                    refs = None

                fields.append(Field(
                    name=name,
                    type=field_type,
                    nullable=nullable,
                    unique=unique,
                    references=refs,
                    confidence=1.0,
                ))

            relations: List[Relation] = []

            for r in model_data.get("relations", []):
                target = r.get("target") or r.get("related", "")
                if not target:
                    continue

                relations.append(Relation(
                    type=r["type"],
                    target=target,
                    foreign_key=r.get("foreign_key") or r.get("via"),
                    local_key=r.get("local_key"),
                    pivot=r.get("pivot"),
                    through=r.get("through"),
                    morph_name=r.get("morph_name"),
                    confidence=1.0,
                    source="explicit",
                ))

            fillable = model_data.get("fillable", [f.name for f in fields
                                                   if f.name not in ("id", "created_at", "updated_at", "deleted_at")])

            node = ModelNode(
                name=model_name,
                fields=fields,
                relations=relations,
                fillable=fillable,
                confidence=1.0,
                source="existing",
            )
            graph.add_model(node)

        return graph

    def full_plan(self, text: str) -> str:
        """Convenience: describe → analyze → plan → JSON string."""
        result = self.describe(text)
        return self.json_r.render(result["plan"])

    def summary(self, text: str) -> str:
        """Human-readable summary for console preview."""
        result  = self.describe(text)
        return self.summary_r.render(result["plan"])

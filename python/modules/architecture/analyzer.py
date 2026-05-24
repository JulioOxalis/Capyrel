"""
modules/architecture/analyzer.py

High-level architecture analyser.  Looks at the whole SchemaGraph and
suggests patterns: repository pattern, service layer, event sourcing, etc.
"""

from __future__ import annotations
from typing import Any, Dict, List

from engine.schema_graph import SchemaGraph
from engine.context import ProjectContext


class ArchitectureAnalyzer:

    def __init__(self, ctx: ProjectContext):
        self.ctx = ctx

    def analyze(self, graph: SchemaGraph) -> List[Dict[str, Any]]:
        suggestions: List[Dict[str, Any]] = []

        model_count = len(graph.models)

        if model_count >= 10:
            suggestions.append({
                "pattern": "repository",
                "reason":  f"Schema has {model_count} models — consider Repository pattern for complex queries.",
                "priority": "medium",
            })

        if model_count >= 6:
            suggestions.append({
                "pattern": "service_layer",
                "reason":  "Multiple models with cross-cutting concerns — Service classes keep controllers thin.",
                "priority": "medium",
            })

        # Check for financial models
        financial = [n for n, m in graph.models.items()
                     if m.archetype in ("order", "invoice", "payment", "subscription")]
        if len(financial) >= 2:
            suggestions.append({
                "pattern": "event_sourcing",
                "reason":  f"Financial models found ({', '.join(financial)}) — consider events/listeners for audit trail.",
                "priority": "high",
            })

        # Check for user-owned content
        user_owned = [n for n, m in graph.models.items()
                      if any(f.name == "user_id" for f in m.fields)]
        if len(user_owned) >= 3:
            suggestions.append({
                "pattern": "policy_authorization",
                "reason":  f"{len(user_owned)} models have user_id — use Laravel Policies for ownership checks.",
                "priority": "high",
            })

        if self.ctx.has_scout():
            searchable = [n for n, m in graph.models.items()
                          if "Searchable" in m.traits]
            if searchable:
                suggestions.append({
                    "pattern": "scout_search",
                    "reason":  f"Laravel Scout active; {', '.join(searchable)} have Searchable trait.",
                    "priority": "low",
                })

        return suggestions

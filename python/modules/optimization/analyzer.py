"""
modules/optimization/analyzer.py

Analyses a SchemaGraph for potential N+1 problems, missing indexes,
and eager-load opportunities.  Produces a list of recommendations.
"""

from __future__ import annotations
from typing import Any, Dict, List

from engine.schema_graph import SchemaGraph, ModelNode


class OptimizationAnalyzer:

    def analyze(self, graph: SchemaGraph) -> List[Dict[str, Any]]:
        """Return list of optimization recommendations."""
        recs: List[Dict[str, Any]] = []
        recs.extend(self._n_plus_one_risks(graph))
        recs.extend(self._missing_indexes(graph))
        recs.extend(self._eager_load_candidates(graph))
        return recs

    def _n_plus_one_risks(self, graph: SchemaGraph) -> List[Dict[str, Any]]:
        recs = []
        for name, model in graph.models.items():
            has_many_rels = model.get_relations_of_type("hasMany")
            if len(has_many_rels) >= 2:
                targets = [r.target for r in has_many_rels]
                recs.append({
                    "type":    "n_plus_one_risk",
                    "model":   name,
                    "message": (
                        f"{name} has {len(has_many_rels)} hasMany relations "
                        f"({', '.join(targets)}). Always eager-load with with()."
                    ),
                    "severity": "warning",
                })
        return recs

    def _missing_indexes(self, graph: SchemaGraph) -> List[Dict[str, Any]]:
        recs = []
        for name, model in graph.models.items():
            indexed_cols = {col for idx in model.indexes for col in idx.columns}
            for f in model.get_fk_fields():
                if f.name not in indexed_cols:
                    recs.append({
                        "type":    "missing_index",
                        "model":   name,
                        "column":  f.name,
                        "message": f"{name}.{f.name} is a FK but has no index.",
                        "severity": "warning",
                    })
        return recs

    def _eager_load_candidates(self, graph: SchemaGraph) -> List[Dict[str, Any]]:
        recs = []
        for name, model in graph.models.items():
            btm = model.get_relations_of_type("belongsToMany")
            if btm:
                targets = [r.target for r in btm]
                recs.append({
                    "type":    "eager_load_candidate",
                    "model":   name,
                    "message": (
                        f"{name} has belongsToMany to {', '.join(targets)}. "
                        "Consider adding $with = [...] or always with()."
                    ),
                    "severity": "info",
                })
        return recs

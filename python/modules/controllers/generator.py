"""
modules/controllers/generator.py

Generates controller method signatures and logic hints for a model plan.
Does NOT produce full PHP files — that is the renderer's job.
Produces a structured spec that the PHP ScaffoldCommand can consume.
"""

from __future__ import annotations
from typing import Any, Dict, List, Optional

from engine.schema_graph import ModelNode
from engine.context import ProjectContext


class ControllerGenerator:

    def __init__(self, ctx: ProjectContext):
        self.ctx = ctx

    def spec(self, model: ModelNode, api_only: bool = False) -> Dict[str, Any]:
        """
        Return a controller spec dict for a model.
        {
          "class":   "PostController",
          "model":   "Post",
          "actions": ["index", "store", "show", "update", "destroy"],
          "api":     true,
          "hints":   {...}
        }
        """
        actions = ["index", "store", "show", "update", "destroy"]
        if not api_only:
            actions = ["index", "create", "store", "show", "edit", "update", "destroy"]

        has_many = model.get_relations_of_type("hasMany")
        btm      = model.get_relations_of_type("belongsToMany")

        hints: Dict[str, Any] = {}

        if has_many:
            hints["eager_load"] = [r.target for r in has_many[:3]]
        if btm:
            hints["sync_relations"] = [r.target for r in btm]
        if model.soft_deletes:
            hints["restore_action"] = True
            actions.append("restore")

        authorization = self._authorization_hint(model)
        if authorization:
            hints["authorization"] = authorization

        return {
            "class":   model.name + "Controller",
            "model":   model.name,
            "actions": actions,
            "api":     api_only,
            "hints":   hints,
        }

    def _authorization_hint(self, model: ModelNode) -> Optional[str]:
        """Suggest whether to use a Policy."""
        sensitive_archetypes = {
            "order", "invoice", "payment", "subscription",
            "user", "report", "audit_log",
        }
        if model.archetype in sensitive_archetypes:
            return f"{model.name}Policy"
        # If model has a user_id FK, likely needs ownership check
        fk_names = {f.name for f in model.get_fk_fields()}
        if "user_id" in fk_names or "owner_id" in fk_names:
            return f"{model.name}Policy"
        return None

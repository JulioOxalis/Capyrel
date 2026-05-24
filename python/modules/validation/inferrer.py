"""
modules/validation/inferrer.py

Infers Laravel validation rules from SchemaGraph field definitions.
Produces FormRequest-style rule arrays.
"""

from __future__ import annotations
from typing import Any, Dict, List, Optional

from engine.schema_graph import ModelNode, Field


class ValidationInferrer:

    def infer_rules(self, model: ModelNode) -> Dict[str, List[str]]:
        """Return a dict of {field_name: [rule, rule, ...]}."""
        rules: Dict[str, List[str]] = {}

        for f in model.fields:
            field_rules = self._rules_for(f)
            if field_rules:
                rules[f.name] = field_rules

        return rules

    def _rules_for(self, f: Field) -> List[str]:
        rules: List[str] = []

        # required vs nullable
        if f.nullable:
            rules.append("nullable")
        else:
            rules.append("required")

        # Type-based rules
        type_rules = self._type_rules(f)
        rules.extend(type_rules)

        # unique
        if f.unique:
            rules.append("unique:" + (f.references or "table") + "," + f.name)

        return rules

    @staticmethod
    def _type_rules(f: Field) -> List[str]:
        lower = f.name.lower()
        rules: List[str] = []

        if f.type == "string":
            if "email" in lower:
                return ["string", "email", "max:254"]
            if "url" in lower or "link" in lower:
                return ["string", "url", "max:2048"]
            if lower in ("slug",) or lower.endswith("_slug"):
                return ["string", "alpha_dash", "max:255"]
            if "password" in lower:
                return ["string", "min:8"]
            if "phone" in lower or "mobile" in lower:
                return ["string", "max:20"]
            return ["string", "max:255"]

        if f.type == "text":
            return ["string"]

        if f.type == "integer":
            rules = ["integer"]
            if lower in ("rating", "rank", "position", "priority"):
                rules.append("min:1")
            if lower == "rating":
                rules.append("max:5")
            return rules

        if f.type == "decimal":
            return ["numeric", "min:0"]

        if f.type == "boolean":
            return ["boolean"]

        if f.type == "timestamp":
            return ["date"]

        if f.type == "date":
            return ["date"]

        if f.type == "json":
            return ["array"]

        if f.type == "foreignId":
            table = f.references or "table"
            model_name = table.rstrip("s")  # rough singular
            return [f"exists:{table},id"]

        if f.type == "uuid":
            return ["uuid"]

        return ["string"]

    def to_php_array(self, rules: Dict[str, List[str]]) -> str:
        """Render rules as a PHP array string (for FormRequest stub)."""
        lines = ["["]
        for field, field_rules in rules.items():
            rule_str = ", ".join(f"'{r}'" for r in field_rules)
            lines.append(f"    '{field}' => [{rule_str}],")
        lines.append("]")
        return "\n".join(lines)

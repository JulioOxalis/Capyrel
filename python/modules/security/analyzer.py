"""
modules/security/analyzer.py

Security-focused schema analysis.  Flags fields that need special handling:
  - Sensitive fields (password, token, secret) → ensure hidden/guarded
  - Mass-assignment risks
  - Unprotected admin fields
"""

from __future__ import annotations
from typing import Any, Dict, List

from engine.schema_graph import SchemaGraph, ModelNode


SENSITIVE_KEYWORDS = frozenset({
    "password", "token", "secret", "key", "api_key", "private",
    "two_factor", "recovery", "salt", "hash", "credential",
})

ADMIN_FIELDS = frozenset({
    "is_admin", "is_superuser", "role", "permissions", "access_level",
    "can_admin", "admin_note",
})


class SecurityAnalyzer:

    def analyze(self, graph: SchemaGraph) -> List[Dict[str, Any]]:
        findings: List[Dict[str, Any]] = []

        for name, model in graph.models.items():
            findings.extend(self._check_sensitive_fields(model))
            findings.extend(self._check_admin_fields(model))
            findings.extend(self._check_fillable_risk(model))

        return findings

    def _check_sensitive_fields(self, model: ModelNode) -> List[Dict[str, Any]]:
        findings = []
        for f in model.fields:
            lower = f.name.lower()
            if any(kw in lower for kw in SENSITIVE_KEYWORDS):
                if f.name in model.fillable:
                    findings.append({
                        "severity": "high",
                        "model":    model.name,
                        "field":    f.name,
                        "message":  f"{model.name}.{f.name} is sensitive but in $fillable — remove it.",
                    })
                else:
                    findings.append({
                        "severity": "info",
                        "model":    model.name,
                        "field":    f.name,
                        "message":  f"{model.name}.{f.name} is sensitive — ensure it is in $hidden.",
                    })
        return findings

    def _check_admin_fields(self, model: ModelNode) -> List[Dict[str, Any]]:
        findings = []
        for f in model.fields:
            if f.name.lower() in ADMIN_FIELDS and f.name in model.fillable:
                findings.append({
                    "severity": "high",
                    "model":    model.name,
                    "field":    f.name,
                    "message":  (
                        f"{model.name}.{f.name} looks like an admin/privilege field "
                        "but is in $fillable — mass-assignment risk."
                    ),
                })
        return findings

    def _check_fillable_risk(self, model: ModelNode) -> List[Dict[str, Any]]:
        findings = []
        fillable_set = set(model.fillable)
        # Warn if timestamps are in fillable
        for ts in ("created_at", "updated_at", "deleted_at"):
            if ts in fillable_set:
                findings.append({
                    "severity": "medium",
                    "model":    model.name,
                    "field":    ts,
                    "message":  f"{model.name}.{ts} should not be in $fillable.",
                })
        return findings

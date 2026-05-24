"""
engine/renderer.py

Converts a Plan (from Planner) into final artefacts.

Currently ships one renderer: JSON (used by the PHP bridge).
The PHP side handles the actual file-writing; the renderer's job is to
produce a clean, stable JSON representation the PHP layer can consume.

A future PhpRenderer (pure Python → PHP stub generation) lives in
renderers/php/ and is not wired here yet.
"""

from __future__ import annotations
import json
from typing import Any, Dict


class JsonRenderer:
    """Serialise a plan dict to a compact JSON string."""

    def render(self, plan: Dict[str, Any]) -> str:
        return json.dumps(plan, ensure_ascii=False, indent=2)

    def render_compact(self, plan: Dict[str, Any]) -> str:
        return json.dumps(plan, ensure_ascii=False)


class SummaryRenderer:
    """Human-readable text summary of a plan (used for console output)."""

    def render(self, plan: Dict[str, Any]) -> str:
        lines = []
        models = plan.get("models", {})
        lines.append(f"  {len(models)} model(s) planned\n")

        for name, m in models.items():
            conf   = m.get("confidence", 1.0)
            arch   = m.get("archetype", "—")
            table  = m.get("table", "—")
            fields = m.get("fields", [])
            rels   = m.get("relations", [])
            warns  = m.get("warnings", [])

            lines.append(f"  ┌── {name}  (table: {table}, archetype: {arch}, conf: {conf:.2f})")
            lines.append(f"  │   Fields   : {', '.join(f['name'] for f in fields) or '—'}")
            lines.append(f"  │   Relations: {', '.join(r['type'] + '→' + r['related'] for r in rels) or '—'}")
            if warns:
                for w in warns:
                    lines.append(f"  │   ⚠  {w}")
            lines.append("  └" + "─" * 60)

        global_warns = plan.get("warnings", [])
        if global_warns:
            lines.append("")
            lines.append("  Global warnings:")
            for w in global_warns:
                msg = w.get("message", str(w)) if isinstance(w, dict) else str(w)
                lines.append(f"    ⚠  {msg}")

        return "\n".join(lines)

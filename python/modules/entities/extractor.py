"""
modules/entities/extractor.py

Higher-level entity extractor that wraps the engine Parser and applies
additional heuristics:
  - Composite-name detection (TeamMember → Team + Member)
  - Domain vocabulary boosting (blog → Post, Tag, Comment, Author)
  - Deduplication against existing models in the project context
"""

from __future__ import annotations
from typing import List, Tuple, Optional

from engine.context import ProjectContext
from engine.parser import Parser
from engine.schema_graph import SchemaGraph, ModelNode
from engine.confidence import from_pattern, from_heuristic, ConfidenceScore
from engine.utils import to_studly, to_singular, split_studly


# ── Domain vocabulary ─────────────────────────────────────────────────────────
# When these keywords appear in a description, suggest their typical companions.

DOMAIN_EXPANSIONS = {
    "blog":         ["Post", "Category", "Tag", "Comment", "Author"],
    "ecommerce":    ["Product", "Order", "Cart", "Category", "Review", "Coupon"],
    "shop":         ["Product", "Order", "Cart", "Category", "Review"],
    "store":        ["Product", "Order", "Cart", "Inventory"],
    "saas":         ["Subscription", "Plan", "Invoice", "Team", "Feature"],
    "marketplace":  ["Product", "Order", "Seller", "Review", "Category"],
    "forum":        ["Thread", "Post", "Reply", "Category", "Tag", "Vote"],
    "social":       ["Profile", "Post", "Comment", "Like", "Follow", "Message"],
    "crm":          ["Contact", "Lead", "Deal", "Activity", "Company", "Note"],
    "booking":      ["Booking", "Service", "Provider", "Slot", "Review"],
    "event":        ["Event", "Ticket", "Venue", "Attendee", "Speaker"],
    "lms":          ["Course", "Lesson", "Enrollment", "Quiz", "Certificate"],
    "hrm":          ["Employee", "Department", "Leave", "Payroll", "Attendance"],
    "inventory":    ["Product", "Warehouse", "Stock", "Supplier", "Movement"],
    "restaurant":   ["Menu", "Item", "Order", "Table", "Reservation"],
    "real_estate":  ["Property", "Listing", "Agent", "Booking", "Review"],
}


class EntityExtractor:

    def __init__(self, ctx: ProjectContext):
        self.ctx    = ctx
        self.parser = Parser(ctx)

    def extract(self, text: str) -> List[Tuple[str, ConfidenceScore]]:
        """
        Extract entity names + confidence from a description string.
        Applies domain expansion and deduplication.
        """
        # Base extraction from parser
        graph, _ = self.parser.parse_description(text)
        entities: dict[str, ConfidenceScore] = {
            name: from_pattern(node.confidence, "parser extraction")
            for name, node in graph.models.items()
        }

        # Domain expansion
        lower = text.lower()
        for domain, suggestions in DOMAIN_EXPANSIONS.items():
            if domain.replace("_", " ") in lower or domain in lower:
                for s in suggestions:
                    if s not in entities:
                        entities[s] = from_heuristic(0.65, f"domain expansion: {domain}")

        # Remove entities that already exist in the project
        existing = self.ctx.model_names()
        filtered = {
            name: conf for name, conf in entities.items()
            if name not in existing
        }

        # Sort by confidence desc
        return sorted(filtered.items(), key=lambda x: -x[1].score)

    def split_composite(self, entity: str) -> List[str]:
        """
        'TeamMember' → ['Team', 'Member']
        'ProjectTaskAssignment' → ['Project', 'Task', 'Assignment']
        """
        return split_studly(entity)

    def is_likely_pivot(self, entity: str, known: List[str]) -> bool:
        """
        Heuristic: if the entity name is composed of 2 known entity names,
        it is likely a pivot/junction table.
        """
        parts = self.split_composite(entity)
        if len(parts) < 2:
            return False
        known_set = set(known)
        return all(p in known_set for p in parts[:2])

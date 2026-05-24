"""
engine/confidence.py

Confidence scoring primitives used throughout the engine.

Every inference — field type, relation, archetype, index — carries a
ConfidenceScore so the planner can decide what to emit unconditionally,
what to emit with a comment, and what to surface as a warning.
"""

from __future__ import annotations
from dataclasses import dataclass, field
from typing import List, Optional


# ── Score thresholds ──────────────────────────────────────────────────────────

HIGH   = 0.85   # emit without reservation
MEDIUM = 0.60   # emit with optional hint
LOW    = 0.35   # emit as warning / require user confirmation


@dataclass
class ConfidenceScore:
    score:     float           # 0.0 – 1.0
    reasoning: str             # human-readable explanation
    source:    str             # "explicit" | "inferred" | "pattern" | "archetype" | "heuristic"
    override:  bool = False    # True if a higher-priority rule overrode a lower one

    def is_high(self)   -> bool: return self.score >= HIGH
    def is_medium(self) -> bool: return MEDIUM <= self.score < HIGH
    def is_low(self)    -> bool: return self.score < MEDIUM

    def __str__(self) -> str:
        return f"{self.score:.2f} [{self.source}] {self.reasoning}"


@dataclass
class InferenceResult:
    """Wraps any inferred value together with its confidence."""
    value:      object
    confidence: ConfidenceScore
    warnings:   List[str] = field(default_factory=list)
    hints:      List[str] = field(default_factory=list)


# ── Builders ──────────────────────────────────────────────────────────────────

def explicit(reasoning: str = "user-specified") -> ConfidenceScore:
    return ConfidenceScore(score=1.0, reasoning=reasoning, source="explicit")


def from_pattern(score: float, reasoning: str) -> ConfidenceScore:
    return ConfidenceScore(score=score, reasoning=reasoning, source="pattern")


def from_archetype(score: float, reasoning: str) -> ConfidenceScore:
    return ConfidenceScore(score=score, reasoning=reasoning, source="archetype")


def from_heuristic(score: float, reasoning: str) -> ConfidenceScore:
    return ConfidenceScore(score=score, reasoning=reasoning, source="heuristic")


def inferred(score: float, reasoning: str) -> ConfidenceScore:
    return ConfidenceScore(score=score, reasoning=reasoning, source="inferred")


def combine(*scores: ConfidenceScore, weights: Optional[List[float]] = None) -> ConfidenceScore:
    """
    Weighted average of multiple confidence scores.
    Uses equal weights when none supplied.
    The source of the result is the source of the highest-weight score.
    """
    if not scores:
        return from_heuristic(0.0, "no evidence")

    if weights is None:
        weights = [1.0] * len(scores)

    total_weight = sum(weights)
    weighted_sum = sum(s.score * w for s, w in zip(scores, weights))
    combined = weighted_sum / total_weight

    dominant = max(zip(scores, weights), key=lambda sw: sw[1])[0]

    return ConfidenceScore(
        score=round(combined, 3),
        reasoning="combined: " + "; ".join(s.reasoning for s in scores),
        source=dominant.source,
    )


def degrade(score: ConfidenceScore, factor: float, reason: str) -> ConfidenceScore:
    """Reduce a score by a factor (0–1), appending reason."""
    return ConfidenceScore(
        score=round(score.score * factor, 3),
        reasoning=f"{score.reasoning} | degraded: {reason}",
        source=score.source,
        override=score.override,
    )


def boost(score: ConfidenceScore, amount: float, reason: str) -> ConfidenceScore:
    """Increase a score by a fixed amount, capped at 1.0."""
    return ConfidenceScore(
        score=round(min(1.0, score.score + amount), 3),
        reasoning=f"{score.reasoning} | boosted: {reason}",
        source=score.source,
        override=score.override,
    )

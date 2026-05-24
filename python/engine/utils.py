"""
engine/utils.py

Shared string-manipulation utilities used across the engine.
No external dependencies.
"""

from __future__ import annotations
import re


_IRREGULARS_PLURAL = {
    "person": "people", "man": "men", "woman": "women",
    "child": "children", "mouse": "mice", "goose": "geese",
    "ox": "oxen", "foot": "feet", "tooth": "teeth",
    "leaf": "leaves", "half": "halves", "knife": "knives",
    "life": "lives", "wolf": "wolves", "shelf": "shelves",
    "datum": "data", "medium": "media", "criterion": "criteria",
    "analysis": "analyses", "basis": "bases", "crisis": "crises",
    "status": "statuses",
}

_IRREGULARS_SINGULAR = {v: k for k, v in _IRREGULARS_PLURAL.items()}


def to_singular(word: str) -> str:
    """Best-effort English singulariser."""
    lower = word.lower()
    if lower in _IRREGULARS_SINGULAR:
        return _IRREGULARS_SINGULAR[lower]
    if lower.endswith("ies") and len(lower) > 4:
        return lower[:-3] + "y"
    if lower.endswith("ves"):
        return lower[:-3] + "f"
    if lower.endswith("sses") or lower.endswith("xes") or \
       lower.endswith("ches") or lower.endswith("shes"):
        return lower[:-2]
    if lower.endswith("s") and not lower.endswith("ss") and len(lower) > 3:
        return lower[:-1]
    return lower


def to_plural(word: str) -> str:
    """Best-effort English pluraliser."""
    lower = word.lower()
    if lower in _IRREGULARS_PLURAL:
        return _IRREGULARS_PLURAL[lower]
    if lower.endswith("y") and len(lower) > 2 and lower[-2] not in "aeiou":
        return lower[:-1] + "ies"
    if lower.endswith(("s", "x", "z", "ch", "sh")):
        return lower + "es"
    if lower.endswith("f"):
        return lower[:-1] + "ves"
    if lower.endswith("fe"):
        return lower[:-2] + "ves"
    return lower + "s"


def to_studly(word: str) -> str:
    """'blog_post' or 'blog-post' → 'BlogPost'"""
    word = re.sub(r"[-\s]+", "_", word)
    return "".join(part.capitalize() for part in word.split("_") if part)


def to_snake(word: str) -> str:
    """'BlogPost' → 'blog_post'"""
    word = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", word)
    word = re.sub(r"(?<=[A-Z])(?=[A-Z][a-z])", "_", word)
    return word.lower()


def to_snake_plural(studly: str) -> str:
    """'BlogPost' → 'blog_posts'"""
    return to_plural(to_snake(studly))


def to_camel(word: str) -> str:
    """'blog_post' → 'blogPost'"""
    parts = word.split("_")
    return parts[0].lower() + "".join(p.capitalize() for p in parts[1:])


def split_studly(name: str) -> list[str]:
    """
    Split a StudlyCase name into its component words.
    'TeamMember' → ['Team', 'Member']
    'ProjectTaskAssignment' → ['Project', 'Task', 'Assignment']
    """
    parts = re.split(r"(?<=[a-z])(?=[A-Z])", name)
    return [p for p in parts if p]


def clean_word(word: str) -> str:
    return re.sub(r"[^a-zA-Z0-9_]", "", word)


def is_fk(name: str) -> bool:
    return name.endswith("_id")


def fk_prefix(name: str) -> str:
    """'user_id' → 'user'"""
    return re.sub(r"_id$", "", name.lower())

"""
engine/context.py

ProjectContext — carries everything the engine knows about the target Laravel
project.  Modules read from it; they never mutate it directly (they return
SchemaGraph mutations to the pipeline).
"""

from __future__ import annotations
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Set, Any


@dataclass
class InstalledPackage:
    name:    str           # composer package name e.g. "laravel/scout"
    version: str = "*"


@dataclass
class ExistingModel:
    name:       str        # StudlyCase
    table:      str        # snake_plural
    fields:     List[str] = field(default_factory=list)   # column names only
    relations:  List[str] = field(default_factory=list)   # method names


@dataclass
class ProjectContext:
    """
    Immutable snapshot of the Laravel project's environment.
    Constructed once at wizard start-up from LaravelAwareness output and
    threaded through every module unchanged.
    """

    # ── Framework / runtime ───────────────────────────────────────────────────
    laravel_version:  str  = "unknown"
    php_version:      str  = "unknown"

    # ── Installed packages ────────────────────────────────────────────────────
    packages: List[InstalledPackage] = field(default_factory=list)

    # ── Existing app state ────────────────────────────────────────────────────
    existing_models:  List[ExistingModel] = field(default_factory=list)
    migrated_tables:  List[str]           = field(default_factory=list)

    # ── Session state (grows during the wizard) ───────────────────────────────
    session_entities: List[str] = field(default_factory=list)  # StudlyCase, ordered

    # ── Raw user input ────────────────────────────────────────────────────────
    description:      str  = ""
    project_name:     str  = "MyApp"

    # ── User preferences ──────────────────────────────────────────────────────
    prefer_uuid:       bool = False
    prefer_soft_delete: bool = False

    # ── Arbitrary extra metadata ──────────────────────────────────────────────
    meta: Dict[str, Any] = field(default_factory=dict)

    # ── Convenience checks ────────────────────────────────────────────────────

    def has_package(self, name: str) -> bool:
        return any(p.name == name for p in self.packages)

    def has_livewire(self)     -> bool: return self.has_package("livewire/livewire")
    def has_sanctum(self)      -> bool: return self.has_package("laravel/sanctum")
    def has_passport(self)     -> bool: return self.has_package("laravel/passport")
    def has_scout(self)        -> bool: return self.has_package("laravel/scout")
    def has_cashier(self)      -> bool: return (
        self.has_package("laravel/cashier") or
        self.has_package("laravel/cashier-paddle")
    )
    def has_spatie_media(self) -> bool: return self.has_package("spatie/laravel-medialibrary")
    def has_spatie_roles(self) -> bool: return self.has_package("spatie/laravel-permission")
    def has_telescope(self)    -> bool: return self.has_package("laravel/telescope")
    def has_filament(self)     -> bool: return self.has_package("filament/filament")

    def known_tables(self) -> List[str]:
        """All tables the engine is confident exist (migrated + session entities)."""
        from engine.utils import to_snake_plural
        session_tables = [to_snake_plural(e) for e in self.session_entities]
        return list(dict.fromkeys(self.migrated_tables + session_tables))

    def model_names(self) -> Set[str]:
        return {m.name for m in self.existing_models}

    def with_entity(self, entity: str) -> "ProjectContext":
        """Return a new context with one more session entity appended."""
        import copy
        clone = copy.copy(self)
        clone.session_entities = self.session_entities + [entity]
        return clone

    def summary_line(self) -> str:
        parts = [f"Laravel {self.laravel_version}", f"PHP {self.php_version}"]
        badges = []
        if self.has_livewire():      badges.append("Livewire")
        if self.has_sanctum():       badges.append("Sanctum")
        if self.has_scout():         badges.append("Scout")
        if self.has_spatie_media():  badges.append("MediaLibrary")
        if self.has_spatie_roles():  badges.append("Permissions")
        if self.has_cashier():       badges.append("Cashier")
        if self.has_filament():      badges.append("Filament")
        return " · ".join(parts + badges)

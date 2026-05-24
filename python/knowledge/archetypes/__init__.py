"""
knowledge/archetypes/__init__.py

Registry mapping archetype name → {fields, archetype} dicts.
Consumed by Pipeline.suggest_fields() and the 'fields' CLI command.
"""

from __future__ import annotations
from typing import Any, Dict, List, Optional

# Each entry: list of field specs in 'name[:type[:modifier]]' format
_ARCHETYPES: Dict[str, List[str]] = {
    "user": [
        "name",
        "email:string:unique",
        "password",
        "avatar:string:null",
        "role:string",
        "email_verified_at:timestamp:null",
    ],
    "post": [
        "title",
        "slug:string:unique",
        "body:text",
        "excerpt:text:null",
        "published_at:timestamp:null",
        "is_published:boolean",
        "user_id",
    ],
    "article": [
        "title",
        "slug:string:unique",
        "body:text",
        "published_at:timestamp:null",
        "reading_time:integer",
        "user_id",
    ],
    "product": [
        "name",
        "slug:string:unique",
        "description:text:null",
        "price:decimal",
        "compare_price:decimal:null",
        "stock:integer",
        "sku:string:unique",
        "is_active:boolean",
    ],
    "order": [
        "reference:string:unique",
        "total:decimal",
        "subtotal:decimal",
        "tax:decimal",
        "status:string",
        "notes:text:null",
        "user_id",
    ],
    "invoice": [
        "number:string:unique",
        "amount:decimal",
        "tax:decimal",
        "status:string",
        "due_at:timestamp:null",
        "paid_at:timestamp:null",
        "user_id",
    ],
    "payment": [
        "amount:decimal",
        "method:string",
        "status:string",
        "reference:string:null",
        "processed_at:timestamp:null",
        "user_id",
    ],
    "subscription": [
        "plan:string",
        "status:string",
        "trial_ends_at:timestamp:null",
        "ends_at:timestamp:null",
        "user_id",
    ],
    "comment": [
        "body:text",
        "approved:boolean",
        "user_id",
        "post_id",
    ],
    "review": [
        "rating:integer",
        "body:text:null",
        "approved:boolean",
        "user_id",
    ],
    "category": [
        "name",
        "slug:string:unique",
        "description:text:null",
        "parent_id:integer:null",
    ],
    "tag": [
        "name",
        "slug:string:unique",
    ],
    "notification": [
        "type",
        "data:json",
        "read_at:timestamp:null",
        "user_id",
    ],
    "message": [
        "body:text",
        "read_at:timestamp:null",
        "sender_id",
        "receiver_id",
    ],
    "event": [
        "title",
        "description:text:null",
        "starts_at:timestamp",
        "ends_at:timestamp:null",
        "location:string:null",
        "is_online:boolean",
    ],
    "address": [
        "line1:string",
        "line2:string:null",
        "city:string",
        "state:string:null",
        "country:string",
        "postal_code:string",
    ],
    "setting": [
        "key:string:unique",
        "value:text:null",
        "group:string:null",
    ],
    "team": [
        "name",
        "slug:string:unique",
        "owner_id",
    ],
    "role": [
        "name:string:unique",
        "description:string:null",
        "guard:string",
    ],
    "permission": [
        "name:string:unique",
        "guard:string",
    ],
    "media": [
        "path",
        "disk:string",
        "size:integer:null",
        "mime_type:string:null",
        "alt:string:null",
    ],
    "image": [
        "path",
        "alt:string:null",
        "disk:string",
        "size:integer:null",
    ],
    "audit_log": [
        "action:string",
        "model_type:string",
        "model_id:integer",
        "payload:json:null",
        "user_id:integer:null",
        "ip_address:string:null",
    ],
    "coupon": [
        "code:string:unique",
        "type:string",
        "value:decimal",
        "uses_remaining:integer:null",
        "expires_at:timestamp:null",
        "is_active:boolean",
    ],
    "cart": [
        "session_id:string:null",
        "total:decimal",
        "user_id:integer:null",
    ],
    "wishlist": [
        "user_id",
    ],
    "report": [
        "title",
        "type:string",
        "data:json",
        "generated_at:timestamp:null",
        "user_id",
    ],
    "profile": [
        "bio:text:null",
        "avatar:string:null",
        "website:string:null",
        "location:string:null",
        "user_id",
    ],
    "booking": [
        "starts_at:timestamp",
        "ends_at:timestamp:null",
        "status:string",
        "notes:text:null",
        "user_id",
    ],
    "ticket": [
        "subject",
        "body:text",
        "status:string",
        "priority:string",
        "user_id",
    ],
    "transaction": [
        "amount:decimal",
        "type:string",
        "status:string",
        "reference:string:null",
        "user_id",
    ],
}


def get(name: str) -> Optional[Dict[str, Any]]:
    """Return archetype field list or None if unknown."""
    fields = _ARCHETYPES.get(name.lower())
    if fields is None:
        return None
    return {"fields": fields, "archetype": name.lower()}


def all_names() -> List[str]:
    return list(_ARCHETYPES.keys())


# Dict-style access for pipeline
registry = {name: {"fields": fields, "archetype": name} for name, fields in _ARCHETYPES.items()}

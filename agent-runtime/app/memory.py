from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from threading import Lock
from uuid import uuid4


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


@dataclass(slots=True)
class PendingAction:
    action_id: str
    action: str
    records: list[dict]
    user_id: int
    session_id: str
    metadata: dict = field(default_factory=dict)
    created_at: datetime = field(default_factory=utcnow)


class PendingActionStore:
    def __init__(self, ttl_minutes: int = 30) -> None:
        self._ttl = timedelta(minutes=ttl_minutes)
        self._items: dict[str, PendingAction] = {}
        self._lock = Lock()

    def save(
        self,
        *,
        action: str,
        records: list[dict],
        user_id: int,
        session_id: str,
        metadata: dict | None = None,
    ) -> PendingAction:
        pending = PendingAction(
            action_id=str(uuid4()),
            action=action,
            records=records,
            user_id=user_id,
            session_id=session_id,
            metadata=metadata or {},
        )

        with self._lock:
            self._cleanup_locked()
            self._items[pending.action_id] = pending

        return pending

    def get(self, action_id: str) -> PendingAction | None:
        with self._lock:
            self._cleanup_locked()
            return self._items.get(action_id)

    def pop(self, action_id: str) -> PendingAction | None:
        with self._lock:
            self._cleanup_locked()
            return self._items.pop(action_id, None)

    def _cleanup_locked(self) -> None:
        expired_before = utcnow() - self._ttl
        expired_ids = [
            action_id
            for action_id, pending in self._items.items()
            if pending.created_at < expired_before
        ]

        for action_id in expired_ids:
            self._items.pop(action_id, None)

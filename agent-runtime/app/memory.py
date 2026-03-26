from __future__ import annotations

from contextlib import closing, suppress
import json
import sqlite3
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path
from threading import Lock
from typing import Protocol
from uuid import uuid4

try:
    import psycopg
    from psycopg.rows import dict_row
except ImportError:  # pragma: no cover - optional dependency resolved at runtime
    psycopg = None
    dict_row = None


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


class PendingActionStoreProtocol(Protocol):
    def save(
        self,
        *,
        action: str,
        records: list[dict],
        user_id: int,
        session_id: str,
        metadata: dict | None = None,
    ) -> PendingAction: ...

    def get(self, action_id: str) -> PendingAction | None: ...

    def pop(self, action_id: str) -> PendingAction | None: ...

    def latest_for_session(
        self,
        *,
        user_id: int,
        session_id: str,
        states: set[str] | None = None,
    ) -> PendingAction | None: ...

    def mark_completed(self, action_id: str, result: dict) -> PendingAction | None: ...

    def list_for_diagnostics(
        self,
        *,
        user_id: int | None = None,
        session_id: str | None = None,
        states: set[str] | None = None,
        limit: int | None = 20,
    ) -> list[PendingAction]: ...


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

    def latest_for_session(
        self,
        *,
        user_id: int,
        session_id: str,
        states: set[str] | None = None,
    ) -> PendingAction | None:
        with self._lock:
            self._cleanup_locked()

            matches = [
                pending
                for pending in self._items.values()
                if pending.user_id == user_id
                and pending.session_id == session_id
                and (
                    not states
                    or str((pending.metadata or {}).get("state") or "").strip() in states
                )
            ]

            if not matches:
                return None

            return max(matches, key=lambda pending: pending.created_at)

    def mark_completed(self, action_id: str, result: dict) -> PendingAction | None:
        with self._lock:
            self._cleanup_locked()
            pending = self._items.get(action_id)
            if pending is None:
                return None

            pending.metadata = {
                **dict(pending.metadata or {}),
                "state": "completed",
                "completed_result": result,
                "completed_at": utcnow().isoformat(),
            }
            return pending

    def list_for_diagnostics(
        self,
        *,
        user_id: int | None = None,
        session_id: str | None = None,
        states: set[str] | None = None,
        limit: int | None = 20,
    ) -> list[PendingAction]:
        with self._lock:
            self._cleanup_locked()
            items = sorted(
                self._items.values(),
                key=lambda pending: pending.created_at,
                reverse=True,
            )

        filtered = [
            pending
            for pending in items
            if _matches_diagnostic_filters(
                pending=pending,
                user_id=user_id,
                session_id=session_id,
                states=states,
            )
        ]
        if limit is None:
            return filtered
        return filtered[: max(0, limit)]

    def _cleanup_locked(self) -> None:
        expired_before = utcnow() - self._ttl
        expired_ids = [
            action_id
            for action_id, pending in self._items.items()
            if pending.created_at < expired_before
        ]

        for action_id in expired_ids:
            self._items.pop(action_id, None)


class SqlitePendingActionStore:
    def __init__(self, db_path: str, ttl_minutes: int = 30) -> None:
        self._ttl = timedelta(minutes=ttl_minutes)
        self._cleanup_interval = timedelta(seconds=60)
        self._last_cleanup_at: datetime | None = None
        self._db_path = Path(db_path)
        self._db_path.parent.mkdir(parents=True, exist_ok=True)
        self._lock = Lock()
        self._init_db()

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

        with self._lock, closing(self._connect()) as connection:
            self._cleanup_locked(connection)
            connection.execute(
                """
                INSERT INTO pending_actions (
                    action_id,
                    action,
                    records_json,
                    user_id,
                    session_id,
                    metadata_json,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    pending.action_id,
                    pending.action,
                    json.dumps(pending.records, ensure_ascii=True),
                    pending.user_id,
                    pending.session_id,
                    json.dumps(pending.metadata, ensure_ascii=True),
                    pending.created_at.isoformat(),
                ),
            )
            connection.commit()

        return pending

    def get(self, action_id: str) -> PendingAction | None:
        with self._lock, closing(self._connect()) as connection:
            self._cleanup_locked(connection)
            row = connection.execute(
                """
                SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                FROM pending_actions
                WHERE action_id = ?
                """,
                (action_id,),
            ).fetchone()

        return self._row_to_pending_action(row)

    def pop(self, action_id: str) -> PendingAction | None:
        with self._lock, closing(self._connect()) as connection:
            self._cleanup_locked(connection)
            row = connection.execute(
                """
                SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                FROM pending_actions
                WHERE action_id = ?
                """,
                (action_id,),
            ).fetchone()
            if row is None:
                return None

            connection.execute(
                "DELETE FROM pending_actions WHERE action_id = ?",
                (action_id,),
            )
            connection.commit()

        return self._row_to_pending_action(row)

    def latest_for_session(
        self,
        *,
        user_id: int,
        session_id: str,
        states: set[str] | None = None,
    ) -> PendingAction | None:
        with self._lock, closing(self._connect()) as connection:
            self._cleanup_locked(connection)
            rows = connection.execute(
                """
                SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                FROM pending_actions
                WHERE user_id = ?
                  AND session_id = ?
                ORDER BY created_at DESC
                """,
                (user_id, session_id),
            ).fetchall()

        if not rows:
            return None

        for row in rows:
            pending = self._row_to_pending_action(row)
            if pending is None:
                continue
            if not states:
                return pending
            state = str((pending.metadata or {}).get("state") or "").strip()
            if state in states:
                return pending

        return None

    def mark_completed(self, action_id: str, result: dict) -> PendingAction | None:
        with self._lock, closing(self._connect()) as connection:
            self._cleanup_locked(connection)
            row = connection.execute(
                """
                SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                FROM pending_actions
                WHERE action_id = ?
                """,
                (action_id,),
            ).fetchone()
            if row is None:
                return None

            pending = self._row_to_pending_action(row)
            metadata = {
                **dict((pending.metadata if pending else {}) or {}),
                "state": "completed",
                "completed_result": result,
                "completed_at": utcnow().isoformat(),
            }
            connection.execute(
                "UPDATE pending_actions SET metadata_json = ? WHERE action_id = ?",
                (json.dumps(metadata, ensure_ascii=True), action_id),
            )
            connection.commit()

        return self.get(action_id)

    def list_for_diagnostics(
        self,
        *,
        user_id: int | None = None,
        session_id: str | None = None,
        states: set[str] | None = None,
        limit: int | None = 20,
    ) -> list[PendingAction]:
        with self._lock, closing(self._connect()) as connection:
            self._cleanup_locked(connection)

            query = [
                "SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at",
                "FROM pending_actions",
            ]
            params: list[object] = []
            clauses: list[str] = []
            if user_id is not None:
                clauses.append("user_id = ?")
                params.append(user_id)
            if session_id:
                clauses.append("session_id = ?")
                params.append(session_id)
            if clauses:
                query.append("WHERE " + " AND ".join(clauses))
            query.append("ORDER BY created_at DESC")

            rows = connection.execute("\n".join(query), tuple(params)).fetchall()

        items = [self._row_to_pending_action(row) for row in rows]
        filtered = [
            pending
            for pending in items
            if pending is not None
            and _matches_diagnostic_filters(
                pending=pending,
                user_id=user_id,
                session_id=session_id,
                states=states,
            )
        ]
        if limit is None:
            return filtered
        return filtered[: max(0, limit)]

    def _connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(str(self._db_path))
        connection.row_factory = sqlite3.Row
        return connection

    def _init_db(self) -> None:
        with self._lock, closing(self._connect()) as connection:
            connection.execute(
                """
                CREATE TABLE IF NOT EXISTS pending_actions (
                    action_id TEXT PRIMARY KEY,
                    action TEXT NOT NULL,
                    records_json TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    session_id TEXT NOT NULL,
                    metadata_json TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )
                """
            )
            connection.execute(
                """
                CREATE INDEX IF NOT EXISTS idx_pending_actions_session
                ON pending_actions (user_id, session_id, created_at DESC)
                """
            )
            connection.commit()

    def _cleanup_locked(self, connection: sqlite3.Connection) -> None:
        now = utcnow()
        if self._last_cleanup_at and (now - self._last_cleanup_at) < self._cleanup_interval:
            return

        expired_before = (utcnow() - self._ttl).isoformat()
        connection.execute(
            "DELETE FROM pending_actions WHERE created_at < ?",
            (expired_before,),
        )
        connection.commit()
        self._last_cleanup_at = now

    def _row_to_pending_action(self, row: sqlite3.Row | None) -> PendingAction | None:
        if row is None:
            return None

        created_at_raw = str(row["created_at"])
        created_at = datetime.fromisoformat(created_at_raw)
        if created_at.tzinfo is None:
            created_at = created_at.replace(tzinfo=timezone.utc)

        return PendingAction(
            action_id=str(row["action_id"]),
            action=str(row["action"]),
            records=self._load_json_list(row["records_json"]),
            user_id=int(row["user_id"]),
            session_id=str(row["session_id"]),
            metadata=self._load_json_dict(row["metadata_json"]),
            created_at=created_at,
        )

    def _load_json_list(self, value: str) -> list[dict]:
        try:
            loaded = json.loads(value)
        except json.JSONDecodeError:
            return []
        return loaded if isinstance(loaded, list) else []

    def _load_json_dict(self, value: str) -> dict:
        try:
            loaded = json.loads(value)
        except json.JSONDecodeError:
            return {}
        return loaded if isinstance(loaded, dict) else {}


class PostgresPendingActionStore:
    def __init__(self, dsn: str, ttl_minutes: int = 30) -> None:
        if psycopg is None:
            raise RuntimeError(
                "O backend postgres para pending actions requer a dependência 'psycopg'."
            )

        self._dsn = dsn
        self._ttl = timedelta(minutes=ttl_minutes)
        self._cleanup_interval = timedelta(seconds=60)
        self._last_cleanup_at: datetime | None = None
        self._connection = None
        self._lock = Lock()
        self._init_db()

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

        def operation(connection):
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO pending_actions (
                        action_id,
                        action,
                        records_json,
                        user_id,
                        session_id,
                        metadata_json,
                        created_at
                    )
                    VALUES (%s, %s, %s::jsonb, %s, %s, %s::jsonb, %s)
                    """,
                    (
                        pending.action_id,
                        pending.action,
                        json.dumps(pending.records, ensure_ascii=True),
                        pending.user_id,
                        pending.session_id,
                        json.dumps(pending.metadata, ensure_ascii=True),
                        pending.created_at.isoformat(),
                    ),
                )

        self._run_locked(operation)

        return pending

    def get(self, action_id: str) -> PendingAction | None:
        def operation(connection):
            with connection.cursor(row_factory=dict_row) as cursor:
                cursor.execute(
                    """
                    SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                    FROM pending_actions
                    WHERE action_id = %s
                    """,
                    (action_id,),
                )
                return cursor.fetchone()

        row = self._run_locked(operation)

        return self._dict_to_pending_action(row)

    def pop(self, action_id: str) -> PendingAction | None:
        def operation(connection):
            with connection.cursor(row_factory=dict_row) as cursor:
                cursor.execute(
                    """
                    SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                    FROM pending_actions
                    WHERE action_id = %s
                    """,
                    (action_id,),
                )
                row = cursor.fetchone()
                if row is None:
                    return None

                cursor.execute(
                    "DELETE FROM pending_actions WHERE action_id = %s",
                    (action_id,),
                )
                return row

        row = self._run_locked(operation)

        return self._dict_to_pending_action(row)

    def latest_for_session(
        self,
        *,
        user_id: int,
        session_id: str,
        states: set[str] | None = None,
    ) -> PendingAction | None:
        def operation(connection):
            with connection.cursor(row_factory=dict_row) as cursor:
                cursor.execute(
                    """
                    SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                    FROM pending_actions
                    WHERE user_id = %s
                      AND session_id = %s
                    ORDER BY created_at DESC
                    """,
                    (user_id, session_id),
                )
                return cursor.fetchall()

        rows = self._run_locked(operation)

        for row in rows:
            pending = self._dict_to_pending_action(row)
            if pending is None:
                continue
            if not states:
                return pending
            state = str((pending.metadata or {}).get("state") or "").strip()
            if state in states:
                return pending

        return None

    def mark_completed(self, action_id: str, result: dict) -> PendingAction | None:
        def operation(connection):
            with connection.cursor(row_factory=dict_row) as cursor:
                cursor.execute(
                    """
                    SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at
                    FROM pending_actions
                    WHERE action_id = %s
                    """,
                    (action_id,),
                )
                row = cursor.fetchone()
                if row is None:
                    return None

                pending = self._dict_to_pending_action(row)
                metadata = {
                    **dict((pending.metadata if pending else {}) or {}),
                    "state": "completed",
                    "completed_result": result,
                    "completed_at": utcnow().isoformat(),
                }
                cursor.execute(
                    """
                    UPDATE pending_actions
                    SET metadata_json = %s::jsonb
                    WHERE action_id = %s
                    """,
                    (json.dumps(metadata, ensure_ascii=True), action_id),
                )
                return row

        row = self._run_locked(operation)
        if row is None:
            return None

        return self.get(action_id)

    def list_for_diagnostics(
        self,
        *,
        user_id: int | None = None,
        session_id: str | None = None,
        states: set[str] | None = None,
        limit: int | None = 20,
    ) -> list[PendingAction]:
        def operation(connection):
            query = [
                "SELECT action_id, action, records_json, user_id, session_id, metadata_json, created_at",
                "FROM pending_actions",
            ]
            params: list[object] = []
            clauses: list[str] = []
            if user_id is not None:
                clauses.append("user_id = %s")
                params.append(user_id)
            if session_id:
                clauses.append("session_id = %s")
                params.append(session_id)
            if clauses:
                query.append("WHERE " + " AND ".join(clauses))
            query.append("ORDER BY created_at DESC")

            with connection.cursor(row_factory=dict_row) as cursor:
                cursor.execute("\n".join(query), tuple(params))
                return cursor.fetchall()

        rows = self._run_locked(operation)

        items = [self._dict_to_pending_action(row) for row in rows]
        filtered = [
            pending
            for pending in items
            if pending is not None
            and _matches_diagnostic_filters(
                pending=pending,
                user_id=user_id,
                session_id=session_id,
                states=states,
            )
        ]
        if limit is None:
            return filtered
        return filtered[: max(0, limit)]

    def _connect(self):
        if self._connection is None or getattr(self._connection, "closed", False):
            self._connection = psycopg.connect(self._dsn)
        return self._connection

    def _reset_connection(self) -> None:
        if self._connection is None:
            return
        with suppress(Exception):
            self._connection.close()
        self._connection = None

    def _run_locked(self, operation):
        with self._lock:
            connection = self._connect()
            try:
                self._cleanup_locked(connection)
                result = operation(connection)
                connection.commit()
                return result
            except Exception:
                with suppress(Exception):
                    connection.rollback()
                self._reset_connection()
                raise

    def _init_db(self) -> None:
        with self._lock:
            connection = self._connect()
            try:
                with connection.cursor() as cursor:
                    cursor.execute(
                        """
                        CREATE TABLE IF NOT EXISTS pending_actions (
                            action_id TEXT PRIMARY KEY,
                            action TEXT NOT NULL,
                            records_json JSONB NOT NULL,
                            user_id BIGINT NOT NULL,
                            session_id TEXT NOT NULL,
                            metadata_json JSONB NOT NULL,
                            created_at TIMESTAMPTZ NOT NULL
                        )
                        """
                    )
                    cursor.execute(
                        """
                        CREATE INDEX IF NOT EXISTS idx_pending_actions_session
                        ON pending_actions (user_id, session_id, created_at DESC)
                        """
                    )
                connection.commit()
            except Exception:
                with suppress(Exception):
                    connection.rollback()
                self._reset_connection()
                raise

    def _cleanup_locked(self, connection) -> None:
        now = utcnow()
        if self._last_cleanup_at and (now - self._last_cleanup_at) < self._cleanup_interval:
            return

        expired_before = utcnow().isoformat()
        with connection.cursor() as cursor:
            cursor.execute(
                """
                DELETE FROM pending_actions
                WHERE created_at < (%s::timestamptz - (%s || ' minutes')::interval)
                """,
                (expired_before, int(self._ttl.total_seconds() // 60)),
            )
        self._last_cleanup_at = now

    def _dict_to_pending_action(self, row: dict | None) -> PendingAction | None:
        if row is None:
            return None

        created_at = row["created_at"]
        if isinstance(created_at, str):
            created_at = datetime.fromisoformat(created_at)
        if created_at.tzinfo is None:
            created_at = created_at.replace(tzinfo=timezone.utc)

        records = row["records_json"] if isinstance(row["records_json"], list) else []
        metadata = row["metadata_json"] if isinstance(row["metadata_json"], dict) else {}

        return PendingAction(
            action_id=str(row["action_id"]),
            action=str(row["action"]),
            records=records,
            user_id=int(row["user_id"]),
            session_id=str(row["session_id"]),
            metadata=metadata,
            created_at=created_at,
        )


def _matches_diagnostic_filters(
    *,
    pending: PendingAction,
    user_id: int | None,
    session_id: str | None,
    states: set[str] | None,
) -> bool:
    if user_id is not None and pending.user_id != user_id:
        return False
    if session_id and pending.session_id != session_id:
        return False
    if states:
        state = str((pending.metadata or {}).get("state") or "").strip()
        if state not in states:
            return False
    return True

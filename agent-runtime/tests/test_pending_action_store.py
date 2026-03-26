from __future__ import annotations

import tempfile
import unittest
from datetime import timedelta
from pathlib import Path

from app.memory import SqlitePendingActionStore, utcnow


class SqlitePendingActionStoreTest(unittest.TestCase):
    def test_persistencia_entre_instancias_preserva_acao_pendente(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            db_path = Path(tmp_dir) / "pending-actions.sqlite3"

            first_store = SqlitePendingActionStore(str(db_path), ttl_minutes=30)
            saved = first_store.save(
                action="gerar_fatura",
                records=[{"cliente_id": 101, "data_vencimento": "2026-04-20"}],
                user_id=7,
                session_id="sessao-persistida",
                metadata={"state": "pending_confirmation", "fonte": "langchain-runtime"},
            )

            second_store = SqlitePendingActionStore(str(db_path), ttl_minutes=30)
            restored = second_store.get(saved.action_id)
            latest = second_store.latest_for_session(
                user_id=7,
                session_id="sessao-persistida",
                states={"pending_confirmation"},
            )

            self.assertIsNotNone(restored)
            self.assertEqual(restored.action, "gerar_fatura")
            self.assertEqual(restored.records[0]["cliente_id"], 101)
            self.assertEqual(restored.metadata["state"], "pending_confirmation")
            self.assertIsNotNone(latest)
            self.assertEqual(latest.action_id, saved.action_id)

    def test_cleanup_por_ttl_remove_registros_expirados(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            db_path = Path(tmp_dir) / "pending-actions.sqlite3"
            store = SqlitePendingActionStore(str(db_path), ttl_minutes=1)
            saved = store.save(
                action="criar_cliente",
                records=[{"cnpj": "12345678000190"}],
                user_id=9,
                session_id="sessao-expirada",
                metadata={"state": "draft"},
            )

            expired_at = (utcnow() - timedelta(minutes=5)).isoformat()
            with store._connect() as connection:
                connection.execute(
                    "UPDATE pending_actions SET created_at = ? WHERE action_id = ?",
                    (expired_at, saved.action_id),
                )
                connection.commit()

            reloaded_store = SqlitePendingActionStore(str(db_path), ttl_minutes=1)

            self.assertIsNone(reloaded_store.get(saved.action_id))
            self.assertIsNone(
                reloaded_store.latest_for_session(
                    user_id=9,
                    session_id="sessao-expirada",
                    states={"draft"},
                )
            )


if __name__ == "__main__":
    unittest.main()

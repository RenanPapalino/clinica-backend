from __future__ import annotations

from contextlib import closing
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
            with closing(store._connect()) as connection:
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

    def test_mark_completed_persiste_resultado_e_remove_estado_pendente(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            db_path = Path(tmp_dir) / "pending-actions.sqlite3"
            first_store = SqlitePendingActionStore(str(db_path), ttl_minutes=30)
            saved = first_store.save(
                action="gerar_fatura",
                records=[{"cliente_id": 101, "data_vencimento": "2026-04-20"}],
                user_id=7,
                session_id="sessao-concluida",
                metadata={"state": "pending_confirmation", "fonte": "langchain-runtime"},
            )

            first_store.mark_completed(
                saved.action_id,
                {
                    "success": True,
                    "message": "Confirmei a fatura FAT-202603-0001.",
                    "detalhes": {
                        "resumo": {"criados": 1, "erros": 0},
                        "registros": [{"id": 900, "numero_fatura": "FAT-202603-0001"}],
                        "erros_lista": [],
                    },
                },
            )

            second_store = SqlitePendingActionStore(str(db_path), ttl_minutes=30)
            restored = second_store.get(saved.action_id)

            self.assertIsNotNone(restored)
            self.assertEqual(restored.metadata["state"], "completed")
            self.assertEqual(
                restored.metadata["completed_result"]["message"],
                "Confirmei a fatura FAT-202603-0001.",
            )
            self.assertIsNone(
                second_store.latest_for_session(
                    user_id=7,
                    session_id="sessao-concluida",
                    states={"pending_confirmation"},
                )
            )
            completed = second_store.latest_for_session(
                user_id=7,
                session_id="sessao-concluida",
                states={"completed"},
            )
            self.assertIsNotNone(completed)
            self.assertEqual(completed.action_id, saved.action_id)

    def test_list_for_diagnostics_filtra_por_sessao_estado_e_limite(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            db_path = Path(tmp_dir) / "pending-actions.sqlite3"
            store = SqlitePendingActionStore(str(db_path), ttl_minutes=30)

            store.save(
                action="gerar_fatura",
                records=[{"cliente_id": 101}],
                user_id=7,
                session_id="sessao-diag",
                metadata={"state": "draft"},
            )
            store.save(
                action="gerar_fatura",
                records=[{"cliente_id": 101}],
                user_id=7,
                session_id="sessao-diag",
                metadata={"state": "suspended"},
            )
            store.save(
                action="criar_cliente",
                records=[{"cnpj": "12345678000190"}],
                user_id=9,
                session_id="sessao-outra",
                metadata={"state": "pending_confirmation"},
            )

            diagnostics = store.list_for_diagnostics(
                user_id=7,
                session_id="sessao-diag",
                states={"suspended"},
                limit=5,
            )

            self.assertEqual(len(diagnostics), 1)
            self.assertEqual(diagnostics[0].session_id, "sessao-diag")
            self.assertEqual(diagnostics[0].metadata["state"], "suspended")


if __name__ == "__main__":
    unittest.main()

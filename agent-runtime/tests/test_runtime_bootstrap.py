from __future__ import annotations

import unittest
from unittest.mock import patch

import app.main as runtime_main
from app.settings import Settings


class RuntimeBootstrapTest(unittest.TestCase):
    def tearDown(self) -> None:
        runtime_main.get_pending_store.cache_clear()
        runtime_main.get_laravel_client.cache_clear()
        runtime_main.get_runtime_service.cache_clear()

    def test_get_pending_store_faz_fallback_para_sqlite_quando_postgres_falha(self) -> None:
        settings = Settings(
            openai_api_key="test-key",
            laravel_agent_secret="agent-secret",
            laravel_base_url="http://laravel.test",
            pending_actions_backend="postgres",
            pending_actions_database_url="postgres://invalid",
            pending_actions_db_path="/tmp/runtime-fallback.sqlite3",
        )

        with patch.object(runtime_main, "get_settings", return_value=settings), patch.object(
            runtime_main,
            "PostgresPendingActionStore",
            side_effect=RuntimeError("postgres down"),
        ), patch.object(runtime_main, "SqlitePendingActionStore") as sqlite_store:
            sqlite_store.return_value = object()

            store = runtime_main.get_pending_store()

        self.assertIs(store, sqlite_store.return_value)
        sqlite_store.assert_called_once_with(
            db_path="/tmp/runtime-fallback.sqlite3",
            ttl_minutes=settings.pending_actions_ttl_minutes,
        )


if __name__ == "__main__":
    unittest.main()

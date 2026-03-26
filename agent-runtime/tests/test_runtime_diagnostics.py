from __future__ import annotations

import asyncio
import unittest
from unittest.mock import AsyncMock

from app.main import pending_actions_diagnostics, runtime_metrics_diagnostics
from app.memory import PendingActionStore
from app.service import ChatRuntimeService
from app.settings import Settings


class RuntimeDiagnosticsEndpointTest(unittest.TestCase):
    def setUp(self) -> None:
        self.settings = Settings(
            openai_api_key="test-key",
            laravel_agent_secret="agent-secret",
            laravel_base_url="http://laravel.test",
            runtime_service_token="",
        )
        self.laravel_client = AsyncMock()
        self.service = ChatRuntimeService(
            settings=self.settings,
            laravel_client=self.laravel_client,
            pending_actions=PendingActionStore(),
        )

    def test_endpoint_retorna_resumo_por_estado_e_metricas(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 101}],
            user_id=7,
            session_id="sessao-diag",
            metadata={"state": "draft", "pending_fields": ["cliente_missing"]},
        )
        self.service.pending_actions.save(
            action="criar_cliente",
            records=[{"cnpj": "12345678000190"}],
            user_id=7,
            session_id="sessao-diag",
            metadata={"state": "pending_confirmation"},
        )
        completed = self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 101}],
            user_id=7,
            session_id="sessao-diag",
            metadata={"state": "pending_confirmation"},
        )
        self.service.pending_actions.mark_completed(
            completed.action_id,
            {
                "success": True,
                "message": "Gerei a fatura FAT-202603-0001.",
                "detalhes": {
                    "resumo": {"criados": 1, "erros": 0},
                    "registros": [{"id": 900}],
                    "erros_lista": [],
                },
            },
        )
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 202}],
            user_id=7,
            session_id="sessao-outra",
            metadata={"state": "suspended", "suspend_reason": "max_repeat_count"},
        )

        self.service._audit_session_decision(
            event="confirmation_replayed",
            user_id=7,
            session_id="sessao-diag",
            action="gerar_fatura",
            state_from="completed",
            state_to="completed",
            decision="reuse_completed_result",
        )
        self.service._audit_session_decision(
            event="circuit_breaker_triggered",
            user_id=7,
            session_id="sessao-diag",
            action="gerar_fatura",
            state_from="draft",
            state_to="suspended",
            decision="max_repeat_count",
            repeat_count=2,
        )

        payload = asyncio.run(
            pending_actions_diagnostics(
                user_id=None,
                session_id="sessao-diag",
                states=None,
                limit=10,
                service=self.service,
            )
        )
        self.assertEqual(payload["filters"]["session_id"], "sessao-diag")
        self.assertEqual(payload["summary"]["total_entries"], 3)
        self.assertEqual(payload["summary"]["active_entries"], 2)
        self.assertEqual(payload["summary"]["counts_by_state"]["draft"], 1)
        self.assertEqual(payload["summary"]["counts_by_state"]["pending_confirmation"], 1)
        self.assertEqual(payload["summary"]["counts_by_state"]["completed"], 1)
        self.assertEqual(payload["summary"]["counts_by_action"]["gerar_fatura"], 2)
        self.assertEqual(payload["metrics"]["counters"]["confirmation_replayed"], 1)
        self.assertEqual(payload["metrics"]["counters"]["circuit_breaker_triggered"], 1)

        completed_items = [
            item for item in payload["pending_actions"] if item["state"] == "completed"
        ]
        self.assertEqual(len(completed_items), 1)
        self.assertTrue(completed_items[0]["metadata"]["has_completed_result"])

    def test_endpoint_filtra_por_estado(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 101}],
            user_id=7,
            session_id="sessao-diag",
            metadata={"state": "draft"},
        )
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 101}],
            user_id=7,
            session_id="sessao-diag",
            metadata={"state": "suspended"},
        )

        payload = asyncio.run(
            pending_actions_diagnostics(
                user_id=None,
                session_id="sessao-diag",
                states="suspended",
                limit=20,
                service=self.service,
            )
        )
        self.assertEqual(payload["summary"]["total_entries"], 1)
        self.assertEqual(payload["summary"]["counts_by_state"]["suspended"], 1)
        self.assertEqual(len(payload["pending_actions"]), 1)
        self.assertEqual(payload["pending_actions"][0]["state"], "suspended")

    def test_metrics_endpoint_json_retorna_snapshot_de_metricas_e_pendencias(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 101}],
            user_id=7,
            session_id="sessao-metrics",
            metadata={"state": "draft"},
        )
        self.service.pending_actions.save(
            action="criar_cliente",
            records=[{"cnpj": "12345678000190"}],
            user_id=7,
            session_id="sessao-metrics",
            metadata={"state": "pending_confirmation"},
        )
        self.service._audit_session_decision(
            event="confirmation_executed",
            user_id=7,
            session_id="sessao-metrics",
            action="gerar_fatura",
            state_from="pending_confirmation",
            state_to="completed",
            decision="approve",
            total_created=1,
            total_errors=0,
        )
        self.service._record_stage_timing("process_chat_total", 123.456)
        self.service._record_stage_timing("router_route", 15.25)
        self.laravel_client.get_metrics_snapshot = lambda: {
            "total_requests": 3,
            "failed_requests": 1,
            "endpoints": {
                "POST /api/internal/agent/faturas/search": {
                    "method": "POST",
                    "path": "/api/internal/agent/faturas/search",
                    "count": 2,
                    "failed_count": 1,
                    "total_ms": 88.0,
                    "last_ms": 45.5,
                    "avg_ms": 44.0,
                    "max_ms": 45.5,
                }
            },
        }

        payload = asyncio.run(
            runtime_metrics_diagnostics(
                format="json",
                service=self.service,
            )
        )

        self.assertEqual(payload["app_name"], self.settings.app_name)
        self.assertEqual(payload["pending_actions_backend"], "memory")
        self.assertGreaterEqual(payload["runtime"]["uptime_seconds"], 0)
        self.assertEqual(payload["runtime"]["counters"]["confirmation_executed"], 1)
        self.assertEqual(payload["runtime"]["stage_timings"]["process_chat_total"]["count"], 1)
        self.assertEqual(payload["runtime"]["stage_timings"]["process_chat_total"]["last_ms"], 123.456)
        self.assertEqual(payload["runtime"]["stage_timings"]["router_route"]["avg_ms"], 15.25)
        self.assertEqual(payload["pending_actions"]["total_entries"], 2)
        self.assertEqual(payload["pending_actions"]["active_entries"], 2)
        self.assertEqual(payload["pending_actions"]["counts_by_state"]["draft"], 1)
        self.assertEqual(payload["pending_actions"]["counts_by_state"]["pending_confirmation"], 1)
        self.assertEqual(payload["laravel_client"]["total_requests"], 3)
        self.assertEqual(payload["laravel_client"]["failed_requests"], 1)
        self.assertEqual(
            payload["laravel_client"]["endpoints"]["POST /api/internal/agent/faturas/search"]["count"],
            2,
        )

    def test_metrics_endpoint_prometheus_retorna_payload_textual(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[{"cliente_id": 101}],
            user_id=7,
            session_id="sessao-metrics",
            metadata={"state": "draft"},
        )
        self.service._audit_session_decision(
            event="confirmation_replayed",
            user_id=7,
            session_id="sessao-metrics",
            action="gerar_fatura",
            state_from="completed",
            state_to="completed",
            decision="reuse_completed_result",
        )
        self.service._record_stage_timing("process_chat_total", 123.456)
        self.laravel_client.get_metrics_snapshot = lambda: {
            "total_requests": 1,
            "failed_requests": 0,
            "endpoints": {
                "POST /api/internal/agent/faturas/search": {
                    "method": "POST",
                    "path": "/api/internal/agent/faturas/search",
                    "count": 1,
                    "failed_count": 0,
                    "total_ms": 45.5,
                    "last_ms": 45.5,
                    "avg_ms": 45.5,
                    "max_ms": 45.5,
                }
            },
        }

        response = asyncio.run(
            runtime_metrics_diagnostics(
                format="prometheus",
                service=self.service,
            )
        )

        body = response.body.decode("utf-8")
        self.assertIn("agent_runtime_info", body)
        self.assertIn('pending_backend="memory"', body)
        self.assertIn("agent_runtime_uptime_seconds", body)
        self.assertIn("agent_runtime_pending_entries", body)
        self.assertIn('agent_runtime_pending_entries_by_state{state="draft"} 1', body)
        self.assertIn('agent_runtime_stage_timing_last_ms{stage="process_chat_total"} 123.456', body)
        self.assertIn("agent_runtime_laravel_requests_total 1", body)
        self.assertIn(
            'agent_runtime_laravel_request_count{method="POST",path="/api/internal/agent/faturas/search"} 1',
            body,
        )
        self.assertIn(
            'agent_runtime_counter{name="confirmation_replayed"} 1',
            body,
        )
        self.assertIn(
            'agent_runtime_audit_event_total{event="confirmation_replayed"} 1',
            body,
        )


if __name__ == "__main__":
    unittest.main()

from __future__ import annotations

import unittest
from unittest.mock import AsyncMock

from app.memory import PendingActionStore
from app.planner import ActionPlan
from app.router import RouteDecision
from app.schemas import ChatPayload, ResumePayload
from app.service import ChatRuntimeService
from app.settings import Settings


class ChatRuntimeServiceTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.laravel_client = AsyncMock()
        self.laravel_client.session_context = AsyncMock(
            return_value={
                "user": {
                    "id": 7,
                    "name": "Renan",
                    "email": "renan@example.com",
                    "role": "admin",
                },
                "session_id": "sessao-fatura",
                "messages": [],
            }
        )
        self.laravel_client.search_clientes = AsyncMock(return_value=[])
        self.laravel_client.search_servicos = AsyncMock(
            return_value=[
                {
                    "id": 301,
                    "codigo": "PCMSO-001",
                    "descricao": "PCMSO mensal",
                    "ativo": True,
                }
            ]
        )
        self.laravel_client.upsert_cliente = AsyncMock(
            return_value={
                "id": 101,
                "cliente_id": 101,
                "sync_operation": "criar",
                "cliente": {
                    "id": 101,
                    "razao_social": "ACME SAUDE LTDA",
                    "cnpj": "12345678000190",
                },
            }
        )
        self.laravel_client.create_cliente = AsyncMock(
            return_value={
                "id": 102,
                "razao_social": "ACME SAUDE LTDA",
                "cnpj": "12345678000190",
                "status": "ativo",
            }
        )
        self.laravel_client.create_fatura = AsyncMock(
            return_value={
                "id": 900,
                "numero_fatura": "FAT-202603-0001",
                "cliente_id": 101,
                "data_vencimento": "2026-04-10",
                "valor_total": 1500.0,
                "boleto_gerado": True,
                "boleto": {
                    "mode": "local",
                    "nosso_numero": "LOCAL0000000900",
                },
                "nfse_emitida": True,
                "nfse": {
                    "numero_nfse": "NFSE-20260326-0900",
                },
                "cliente": {
                    "id": 101,
                    "razao_social": "ACME SAUDE LTDA",
                    "cnpj": "12345678000190",
                },
            }
        )

        self.service = ChatRuntimeService(
            settings=Settings(
                openai_api_key="test-key",
                laravel_agent_secret="agent-secret",
                laravel_base_url="http://laravel.test",
            ),
            laravel_client=self.laravel_client,
            pending_actions=PendingActionStore(),
        )

    async def test_fluxo_multi_turno_prepara_rascunho_de_fatura_e_pede_cliente(self) -> None:
        self.service.router.route = AsyncMock(
            return_value=RouteDecision(
                tipo_interacao="acao_operacional",
                dominio="faturamento",
                acao_sugerida="gerar_fatura",
                precisa_confirmacao=True,
            )
        )
        self.service.action_planner.plan = AsyncMock(
            return_value=ActionPlan(
                mensagem="Preparei a fatura solicitada.",
                acao_sugerida="gerar_fatura",
                dados_mapeados=[
                    {
                        "cliente": "ACME SAUDE LTDA",
                        "periodo_referencia": "2026-03",
                        "data_vencimento": "2026-04-10",
                        "gerar_boleto": True,
                        "emitir_nfse": True,
                        "codigo_servico": "17.01",
                        "itens": [
                            {
                                "descricao": "PCMSO mensal",
                                "quantidade": 1,
                                "valor_unitario": 1500.0,
                            }
                        ],
                    }
                ],
            )
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem=(
                    "Gere uma fatura de março para ACME Saúde, vencimento 10/04/2026, "
                    "item PCMSO mensal no valor de 1500 reais, com boleto e NFS-e."
                ),
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-fatura",
            )
        )

        self.assertEqual(response.acao_sugerida, "gerar_fatura")
        self.assertIsNotNone(response.dados_estruturados)
        self.assertTrue(response.dados_estruturados.metadata["runtime_requires_more_info"])
        self.assertIn("cliente", response.dados_estruturados.metadata["runtime_pending_fields"])
        draft_record = response.dados_estruturados.dados_mapeados[0]
        self.assertTrue(draft_record["gerar_boleto"])
        self.assertTrue(draft_record["emitir_nfse"])
        self.assertEqual(draft_record["codigo_servico"], "17.01")

        pending = self.service.pending_actions.latest_for_session(
            user_id=7,
            session_id="sessao-fatura",
            states={"draft"},
        )
        self.assertIsNotNone(pending)
        self.assertEqual(pending.action, "gerar_fatura")

    async def test_fallback_heuristico_de_fatura_gera_preview_confirmavel(self) -> None:
        self.service.router.route = AsyncMock(
            return_value=RouteDecision(
                tipo_interacao="acao_operacional",
                dominio="faturamento",
                acao_sugerida="gerar_fatura",
                precisa_confirmacao=True,
            )
        )
        self.service.action_planner.plan = AsyncMock(
            return_value=ActionPlan(
                mensagem="Pedido identificado, mas sem estrutura final.",
                acao_sugerida="gerar_fatura",
                dados_mapeados=[],
            )
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem=(
                    "Cadastre e fature a ACME Saúde LTDA, CNPJ 12.345.678/0001-90, "
                    "referente a março de 2026, com vencimento em 10/04/2026, "
                    "item PCMSO mensal no valor de 1500 reais. "
                    "Gere também o boleto e emita a NFS-e com código de serviço 17.01."
                ),
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-fatura-heuristica",
            )
        )

        self.assertIn(response.acao_sugerida, {"criar_cliente", "gerar_fatura"})
        self.assertIsNotNone(response.dados_estruturados)
        metadata = response.dados_estruturados.metadata
        self.assertTrue(
            metadata.get("runtime_requires_confirmation")
            or metadata.get("runtime_requires_more_info")
        )
        first_record = response.dados_estruturados.dados_mapeados[0]
        self.assertEqual(
            first_record.get("cnpj") or first_record.get("cliente_cnpj"),
            "12345678000190",
        )

    async def test_fluxo_multi_turno_redireciona_para_cadastro_do_cliente_e_retoma_fatura_na_confirmacao(self) -> None:
        draft = self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente": "ACME SAUDE LTDA",
                    "cliente_cnpj": "12345678000190",
                    "periodo_referencia": "2026-03",
                    "data_vencimento": "2026-04-10",
                    "gerar_boleto": True,
                    "emitir_nfse": True,
                    "codigo_servico": "17.01",
                    "_itens_payload": [
                        {
                            "descricao": "PCMSO mensal",
                            "quantidade": 1,
                            "valor_unitario": 1500.0,
                        }
                    ],
                }
            ],
            user_id=7,
            session_id="sessao-fatura",
            metadata={
                "state": "draft",
                "pending_fields": ["cliente"],
                "fonte": "langchain-runtime",
            },
        )

        self.service.router.route = AsyncMock(
            return_value=RouteDecision(
                tipo_interacao="acao_operacional",
                dominio="cadastros",
                acao_sugerida="gerar_fatura",
                precisa_confirmacao=True,
            )
        )
        self.service.action_planner.plan = AsyncMock(
            return_value=ActionPlan(
                mensagem="Complemento recebido.",
                acao_sugerida="gerar_fatura",
                dados_mapeados=[],
            )
        )

        preview = await self.service.process_chat(
            ChatPayload(
                mensagem="Cadastre o cliente com CNPJ 12.345.678/0001-90 e continue a fatura.",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-fatura",
            )
        )

        self.assertEqual(preview.acao_sugerida, "criar_cliente")
        self.assertIsNotNone(preview.dados_estruturados)
        self.assertTrue(preview.dados_estruturados.metadata["runtime_requires_confirmation"])
        self.assertEqual(
            preview.dados_estruturados.dados_mapeados[0]["_resume_fatura_draft_action_id"],
            draft.action_id,
        )

        result = await self.service.confirm_action(
            ResumePayload(
                acao="criar_cliente",
                dados=preview.dados_estruturados.dados_mapeados,
                metadata=preview.dados_estruturados.metadata,
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-fatura",
            )
        )

        self.assertTrue(result["success"])
        self.assertEqual(result["detalhes"]["resumo"]["criados"], 1)
        self.assertEqual(len(result["detalhes"]["acoes_encadeadas"]), 1)
        self.assertEqual(result["detalhes"]["acoes_encadeadas"][0]["acao"], "gerar_fatura")

        self.laravel_client.upsert_cliente.assert_awaited()
        self.laravel_client.create_fatura.assert_awaited_once()

        payload = self.laravel_client.create_fatura.await_args.kwargs["payload"]
        self.assertEqual(payload["cliente_id"], 101)
        self.assertTrue(payload["gerar_boleto"])
        self.assertTrue(payload["emitir_nfse"])
        self.assertEqual(payload["codigo_servico"], "17.01")
        self.assertEqual(payload["itens"][0]["servico_id"], 301)

    async def test_confirmacao_em_linguagem_natural_aprova_acao_pendente(self) -> None:
        pending = self.service.pending_actions.save(
            action="criar_cliente",
            records=[
                {
                    "cnpj": "12345678000190",
                    "razao_social": "ACME SAUDE LTDA",
                    "nome_fantasia": "ACME SAUDE LTDA",
                    "status": "ativo",
                }
            ],
            user_id=7,
            session_id="sessao-confirmacao-natural",
            metadata={
                "state": "pending_confirmation",
                "fonte": "langchain-runtime",
            },
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem="pode seguir",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-confirmacao-natural",
            )
        )

        self.assertIsNone(response.acao_sugerida)
        self.assertIsNone(response.dados_estruturados)
        self.assertIn("ACME SAUDE LTDA", response.mensagem)
        self.laravel_client.create_cliente.assert_awaited_once()
        self.laravel_client.upsert_cliente.assert_not_awaited()
        self.laravel_client.create_fatura.assert_not_awaited()
        self.assertIsNone(self.service.pending_actions.get(pending.action_id))

    async def test_rejeicao_em_linguagem_natural_cancela_confirmacao_pendente(self) -> None:
        pending = self.service.pending_actions.save(
            action="criar_cliente",
            records=[
                {
                    "cnpj": "12345678000190",
                    "razao_social": "ACME SAUDE LTDA",
                    "nome_fantasia": "ACME SAUDE LTDA",
                    "status": "ativo",
                }
            ],
            user_id=7,
            session_id="sessao-rejeicao-natural",
            metadata={
                "state": "pending_confirmation",
                "fonte": "langchain-runtime",
            },
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem="não confirma isso",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-rejeicao-natural",
            )
        )

        self.assertIsNone(response.acao_sugerida)
        self.assertIsNone(response.dados_estruturados)
        self.assertIn("Nao vou seguir com essa acao", response.mensagem)
        self.laravel_client.create_cliente.assert_not_awaited()
        self.laravel_client.upsert_cliente.assert_not_awaited()
        self.laravel_client.create_fatura.assert_not_awaited()
        self.assertIsNone(self.service.pending_actions.get(pending.action_id))

    async def test_confirmacao_condicional_atualiza_fatura_pendente_antes_de_executar(self) -> None:
        pending = self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente_id": 101,
                    "cliente": "ACME SAUDE LTDA",
                    "periodo_referencia": "2026-03",
                    "data_vencimento": "2026-04-10",
                    "gerar_boleto": True,
                    "emitir_nfse": True,
                    "codigo_servico": "17.01",
                    "_itens_payload": [
                        {
                            "descricao": "PCMSO mensal",
                            "quantidade": 1,
                            "valor_unitario": 1500.0,
                        }
                    ],
                }
            ],
            user_id=7,
            session_id="sessao-confirmacao-condicional",
            metadata={
                "state": "pending_confirmation",
                "fonte": "langchain-runtime",
            },
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem="pode seguir, mas muda o vencimento para 20/04/2026 e sem emitir a NFS-e",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-confirmacao-condicional",
            )
        )

        self.assertEqual(response.acao_sugerida, "gerar_fatura")
        self.assertIsNotNone(response.dados_estruturados)
        self.assertTrue(response.dados_estruturados.metadata["runtime_requires_confirmation"])
        updated_record = response.dados_estruturados.dados_mapeados[0]
        self.assertEqual(updated_record["data_vencimento"], "2026-04-20")
        self.assertEqual(updated_record["periodo_referencia"], "2026-03")
        self.assertEqual(updated_record["valor_total"], 1500.0)
        self.assertFalse(updated_record["emitir_nfse"])
        self.assertEqual(updated_record["_itens_payload"][0]["valor_unitario"], 1500.0)
        self.laravel_client.create_fatura.assert_not_awaited()
        self.assertIsNone(self.service.pending_actions.get(pending.action_id))

    async def test_confirmacao_posterior_executa_fatura_atualizada(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente_id": 101,
                    "cliente": "ACME SAUDE LTDA",
                    "periodo_referencia": "2026-03",
                    "data_vencimento": "2026-04-10",
                    "gerar_boleto": True,
                    "emitir_nfse": True,
                    "codigo_servico": "17.01",
                    "_itens_payload": [
                        {
                            "descricao": "PCMSO mensal",
                            "quantidade": 1,
                            "valor_unitario": 1500.0,
                        }
                    ],
                }
            ],
            user_id=7,
            session_id="sessao-confirmacao-atualizada",
            metadata={
                "state": "pending_confirmation",
                "fonte": "langchain-runtime",
            },
        )

        preview = await self.service.process_chat(
            ChatPayload(
                mensagem="muda o vencimento para 20/04/2026 e sem emitir a NFS-e",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-confirmacao-atualizada",
            )
        )

        self.assertEqual(preview.acao_sugerida, "gerar_fatura")
        self.assertTrue(preview.dados_estruturados.metadata["runtime_requires_confirmation"])

        result = await self.service.process_chat(
            ChatPayload(
                mensagem="agora sim, pode seguir",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-confirmacao-atualizada",
            )
        )

        self.assertIsNone(result.acao_sugerida)
        self.assertIn("FAT-202603-0001", result.mensagem)
        self.laravel_client.create_fatura.assert_awaited_once()

        payload = self.laravel_client.create_fatura.await_args.kwargs["payload"]
        self.assertEqual(payload["data_vencimento"], "2026-04-20")
        self.assertFalse(payload["emitir_nfse"])


if __name__ == "__main__":
    unittest.main()

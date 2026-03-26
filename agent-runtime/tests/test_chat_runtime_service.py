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
        self.assertIn("cliente_missing", response.dados_estruturados.metadata["runtime_pending_fields"])
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
                "pending_fields": ["cliente_missing"],
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
        completed = self.service.pending_actions.get(pending.action_id)
        self.assertIsNotNone(completed)
        self.assertEqual(completed.metadata["state"], "completed")

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

    async def test_complemento_de_itens_em_rascunho_de_fatura_mapeia_item_por_linguagem_natural(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "periodo_referencia": "2026-04",
                    "data_vencimento": "2026-04-25",
                }
            ],
            user_id=7,
            session_id="sessao-itens-pendentes",
            metadata={
                "state": "draft",
                "pending_fields": ["cliente_missing", "itens_missing"],
                "fonte": "langchain-runtime",
            },
        )

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
                mensagem="Complemento recebido.",
                acao_sugerida="gerar_fatura",
                dados_mapeados=[],
            )
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem="item: exame",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-itens-pendentes",
            )
        )

        self.assertEqual(response.acao_sugerida, "gerar_fatura")
        self.assertIsNotNone(response.dados_estruturados)
        draft_record = response.dados_estruturados.dados_mapeados[0]
        self.assertEqual(draft_record["_itens_payload"][0]["descricao"], "exame")
        self.assertIn("cliente_missing", response.dados_estruturados.metadata["runtime_pending_fields"])

    async def test_pergunta_nova_descarta_rascunho_de_fatura_em_vez_de_entrar_em_loop(self) -> None:
        pending = self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente": "ALPHA SISTEMAS LTDA",
                    "periodo_referencia": "2026-04",
                    "data_vencimento": "2026-04-25",
                }
            ],
            user_id=7,
            session_id="sessao-loop-fatura",
            metadata={
                "state": "draft",
                "pending_fields": ["cliente_missing", "itens_missing"],
                "fonte": "langchain-runtime",
            },
        )

        self.service.router.route = AsyncMock(
            return_value=RouteDecision(
                tipo_interacao="ambigua",
                dominio="cadastros",
                acao_sugerida="nenhuma",
                mensagem_roteamento="Posso buscar os clientes fora de São Paulo na base.",
            )
        )

        response = await self.service.process_chat(
            ChatPayload(
                mensagem="quero saber quais clientes não são da cidade de são paulo",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-loop-fatura",
            )
        )

        self.assertEqual(response.mensagem, "Posso buscar os clientes fora de São Paulo na base.")
        self.assertIsNone(response.acao_sugerida)
        self.assertIsNone(self.service.pending_actions.get(pending.action_id))

    async def test_repeticao_do_mesmo_rascunho_adiciona_alerta_anti_loop(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "periodo_referencia": "2026-04",
                    "data_vencimento": "2026-04-25",
                }
            ],
            user_id=7,
            session_id="sessao-anti-loop",
            metadata={
                "state": "draft",
                "pending_fields": ["cliente_missing", "itens_missing"],
                "fonte": "langchain-runtime",
            },
        )

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
                mensagem="Complemento recebido.",
                acao_sugerida="gerar_fatura",
                dados_mapeados=[],
            )
        )

        first = await self.service.process_chat(
            ChatPayload(
                mensagem="cliente: ALPHA SISTEMAS LTDA",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-anti-loop",
            )
        )
        second = await self.service.process_chat(
            ChatPayload(
                mensagem="cliente: ALPHA SISTEMAS LTDA",
                user_id=7,
                user_name="Renan",
                user_email="renan@example.com",
                session_id="sessao-anti-loop",
            )
        )

        self.assertIn("Me informe", first.mensagem)
        self.assertIn("Ainda nao consegui avançar esse rascunho", second.mensagem)

    async def test_circuit_breaker_suspende_rascunho_sem_progresso_repetido(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "periodo_referencia": "2026-04",
                    "data_vencimento": "2026-04-25",
                }
            ],
            user_id=7,
            session_id="sessao-circuit-breaker",
            metadata={
                "state": "draft",
                "pending_fields": ["cliente_missing", "itens_missing"],
                "fonte": "langchain-runtime",
            },
        )

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
                mensagem="Complemento recebido.",
                acao_sugerida="gerar_fatura",
                dados_mapeados=[],
            )
        )

        for _ in range(2):
            await self.service.process_chat(
                ChatPayload(
                    mensagem="cliente: ALPHA SISTEMAS LTDA",
                    user_id=7,
                    user_name="Renan",
                    user_email="renan@example.com",
                    session_id="sessao-circuit-breaker",
                )
            )

        with self.assertLogs("agent_runtime.audit", level="INFO") as captured:
            third = await self.service.process_chat(
                ChatPayload(
                    mensagem="cliente: ALPHA SISTEMAS LTDA",
                    user_id=7,
                    user_name="Renan",
                    user_email="renan@example.com",
                    session_id="sessao-circuit-breaker",
                )
            )

        self.assertIn("Suspendi este rascunho de fatura", third.mensagem)
        self.assertIsNone(third.acao_sugerida)
        self.assertIsNone(third.dados_estruturados)
        self.assertIsNone(
            self.service.pending_actions.latest_for_session(
                user_id=7,
                session_id="sessao-circuit-breaker",
                states={"draft", "pending_confirmation"},
            )
        )
        suspended = self.service.pending_actions.latest_for_session(
            user_id=7,
            session_id="sessao-circuit-breaker",
            states={"suspended"},
        )
        self.assertIsNotNone(suspended)
        self.assertEqual(suspended.metadata["suspend_reason"], "max_repeat_count")

        joined = "\n".join(captured.output)
        self.assertIn('"event": "circuit_breaker_triggered"', joined)
        self.assertIn('"decision": "max_repeat_count"', joined)
        self.assertIn('"state_to": "suspended"', joined)

    def test_classificador_local_impede_router_de_reabrir_fatura_em_pergunta_nova(self) -> None:
        local_intent = self.service._classify_local_intent(
            message="quantos clientes tenho na base?",
            has_pending=True,
        )
        overridden = self.service._apply_local_intent_override(
            route=RouteDecision(
                tipo_interacao="acao_operacional",
                dominio="faturamento",
                acao_sugerida="gerar_fatura",
                precisa_confirmacao=True,
            ),
            local_intent=local_intent,
        )

        self.assertEqual(local_intent, "new_query")
        self.assertEqual(overridden.tipo_interacao, "consulta_operacional")
        self.assertEqual(overridden.acao_sugerida, "nenhuma")

    async def test_confirmacao_duplicada_reutiliza_resultado_sem_reexecutar(self) -> None:
        pending = self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente_id": 101,
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
            session_id="sessao-idempotencia",
            metadata={
                "state": "pending_confirmation",
                "fonte": "langchain-runtime",
            },
        )

        payload = ResumePayload(
            acao="gerar_fatura",
            metadata={
                "runtime_pending_action_id": pending.action_id,
                "runtime_requires_confirmation": True,
            },
            decision="approve",
            session_id="sessao-idempotencia",
            user_id=7,
            user_name="Renan",
            user_email="renan@example.com",
        )

        first = await self.service.confirm_action(payload)
        second = await self.service.confirm_action(payload)

        self.assertTrue(first["success"])
        self.assertEqual(first, second)
        self.laravel_client.create_fatura.assert_awaited_once()
        completed = self.service.pending_actions.get(pending.action_id)
        self.assertIsNotNone(completed)
        self.assertEqual(completed.metadata["state"], "completed")
        self.assertEqual(
            completed.metadata["completed_result"]["message"],
            first["message"],
        )

    async def test_auditoria_registra_supersede_quando_usuario_muda_de_assunto(self) -> None:
        self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente": "ALPHA SISTEMAS LTDA",
                    "periodo_referencia": "2026-04",
                    "data_vencimento": "2026-04-25",
                }
            ],
            user_id=7,
            session_id="sessao-auditoria-supersede",
            metadata={
                "state": "draft",
                "pending_fields": ["cliente_missing", "itens_missing"],
                "fonte": "langchain-runtime",
            },
        )

        self.service.router.route = AsyncMock(
            return_value=RouteDecision(
                tipo_interacao="ambigua",
                dominio="cadastros",
                acao_sugerida="nenhuma",
                mensagem_roteamento="Posso buscar os clientes fora de São Paulo na base.",
            )
        )

        with self.assertLogs("agent_runtime.audit", level="INFO") as captured:
            await self.service.process_chat(
                ChatPayload(
                    mensagem="quero saber quais clientes não são da cidade de são paulo",
                    user_id=7,
                    user_name="Renan",
                    user_email="renan@example.com",
                    session_id="sessao-auditoria-supersede",
                )
            )

        joined = "\n".join(captured.output)
        self.assertIn('"event": "draft_resolution"', joined)
        self.assertIn('"decision": "supersede"', joined)
        self.assertIn('"session_id": "sessao-auditoria-supersede"', joined)
        self.assertIn('"state_from": "draft"', joined)
        self.assertIn('"state_to": "superseded"', joined)

    async def test_auditoria_registra_replay_de_confirmacao_concluida(self) -> None:
        pending = self.service.pending_actions.save(
            action="gerar_fatura",
            records=[
                {
                    "cliente_id": 101,
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
            session_id="sessao-auditoria-replay",
            metadata={
                "state": "pending_confirmation",
                "fonte": "langchain-runtime",
            },
        )

        payload = ResumePayload(
            acao="gerar_fatura",
            metadata={
                "runtime_pending_action_id": pending.action_id,
                "runtime_requires_confirmation": True,
            },
            decision="approve",
            session_id="sessao-auditoria-replay",
            user_id=7,
            user_name="Renan",
            user_email="renan@example.com",
        )

        await self.service.confirm_action(payload)

        with self.assertLogs("agent_runtime.audit", level="INFO") as captured:
            replay = await self.service.confirm_action(payload)

        joined = "\n".join(captured.output)
        self.assertTrue(replay["success"])
        self.assertIn('"event": "confirmation_replayed"', joined)
        self.assertIn('"decision": "reuse_completed_result"', joined)
        self.assertIn('"session_id": "sessao-auditoria-replay"', joined)
        self.assertIn('"state_to": "completed"', joined)


if __name__ == "__main__":
    unittest.main()

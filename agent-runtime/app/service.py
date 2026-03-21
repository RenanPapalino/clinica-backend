from __future__ import annotations

import base64
import io
import logging
import re
from dataclasses import replace
from datetime import datetime
from decimal import Decimal, InvalidOperation
from typing import Any

from langchain.agents import create_agent
from langchain.agents.structured_output import ProviderStrategy
from langchain_openai import ChatOpenAI
from openai import APIConnectionError, AsyncOpenAI, AuthenticationError, RateLimitError

from .file_parser import ParsedFile, is_audio_attachment, is_image_attachment, parse_attachment
from .laravel_client import LaravelApiError, LaravelInternalClient
from .memory import PendingActionStore
from .planner import ActionPlan, ActionPlanner
from .prompts import (
    CHAT_SYSTEM_PROMPT,
    DOCUMENT_SPECIALIST_PROMPT,
    FINANCE_SPECIALIST_PROMPT,
    build_current_message,
)
from .router import ConversationRouter, RouteDecision
from .schemas import ChatPayload, ChatbotResponse, ColumnDefinition, ResumePayload, StructuredData
from .settings import Settings
from .tools import build_read_tools

logger = logging.getLogger(__name__)


class ChatRuntimeService:
    def __init__(
        self,
        *,
        settings: Settings,
        laravel_client: LaravelInternalClient,
        pending_actions: PendingActionStore,
    ) -> None:
        self.settings = settings
        self.laravel_client = laravel_client
        self.pending_actions = pending_actions
        self.chat_model = ChatOpenAI(
            api_key=settings.openai_api_key,
            model=settings.openai_model,
            temperature=0,
        )
        self.openai_client = AsyncOpenAI(api_key=settings.openai_api_key)
        self.router = ConversationRouter(self.chat_model)
        self.action_planner = ActionPlanner(self.chat_model)

    async def process_chat(self, payload: ChatPayload) -> ChatbotResponse:
        if not self.settings.openai_api_key:
            return ChatbotResponse(
                mensagem="OPENAI_API_KEY nao configurada no runtime do chatbot.",
                acao_sugerida=None,
                dados_estruturados=None,
            )

        try:
            parsed_file = parse_attachment(payload.arquivo)
            parsed_file = await self._enrich_multimodal_attachment(payload, parsed_file)
            session_context = await self._load_session_context(payload)
            session_draft = self.pending_actions.latest_for_session(
                user_id=payload.user_id,
                session_id=payload.session_id,
                states={"draft", "pending_confirmation"},
            )
            current_message = build_current_message(
                payload,
                parsed_file,
                draft_context=self._draft_context_payload(session_draft),
            )

            greeting_response = self._build_greeting_response(
                payload=payload,
                parsed_file=parsed_file,
                session_context=session_context,
                session_draft=session_draft,
            )
            if greeting_response is not None:
                return greeting_response

            route = await self.router.route(
                session_context=session_context,
                current_message=current_message,
            )

            logger.info(
                "Roteamento multiagente definido",
                extra={
                    "user_id": payload.user_id,
                    "session_id": payload.session_id,
                    "tipo_interacao": route.tipo_interacao,
                    "dominio": route.dominio,
                    "acao_sugerida": route.acao_sugerida,
                },
            )

            should_attempt_action = (
                route.tipo_interacao == "acao_operacional"
                or route.acao_sugerida != "nenhuma"
                or payload.arquivo is not None
                or session_draft is not None
            )

            if should_attempt_action:
                action_plan = await self.action_planner.plan(
                    payload=payload,
                    session_context=session_context,
                    current_message=current_message,
                )
                action_plan = self._merge_route_into_action_plan(route, action_plan)
                action_plan = self._merge_attachment_candidates_into_action_plan(action_plan, parsed_file)
                action_plan = self._merge_session_draft_into_action_plan(
                    action_plan,
                    session_draft,
                    payload.mensagem or "",
                )
                action_plan = self._redirect_fatura_draft_to_cliente_creation(
                    payload=payload,
                    plan=action_plan,
                    session_draft=session_draft,
                )

                preview = await self._build_action_preview(
                    payload=payload,
                    plan=action_plan,
                )
                if preview is not None:
                    return preview

            if route.tipo_interacao == "ambigua" and route.mensagem_roteamento.strip():
                return ChatbotResponse(
                    mensagem=route.mensagem_roteamento.strip(),
                    acao_sugerida=None,
                    dados_estruturados=None,
                )

            agent = create_agent(
                model=self.chat_model,
                tools=build_read_tools(
                    client=self.laravel_client,
                    user_id=payload.user_id,
                    max_rows=self.settings.max_result_rows,
                ),
                system_prompt=self._select_specialist_prompt(route),
                response_format=ProviderStrategy(ChatbotResponse),
            )

            result = await agent.ainvoke(
                {"messages": self._build_messages(session_context, payload, current_message)}
            )

            return self._normalize_agent_response(result)
        except Exception as exc:
            logger.exception("Falha ao processar chat no runtime", extra={"user_id": payload.user_id})
            return self._runtime_error_response(exc)

    async def confirm_action(self, payload: ResumePayload) -> dict[str, Any]:
        decision = (payload.decision or "approve").lower().strip()
        if decision != "approve":
            return {
                "success": False,
                "message": "Acao rejeitada pelo usuario.",
                "detalhes": {
                    "resumo": {"criados": 0, "erros": 0},
                    "erros_lista": [],
                },
            }

        action = self._canonical_action(payload.acao)
        if action is None:
            return {
                "success": False,
                "message": f"Acao nao suportada: {payload.acao}",
                "detalhes": None,
            }

        pending_fields = list(payload.metadata.get("runtime_pending_fields") or [])
        if payload.metadata.get("runtime_requires_more_info") or payload.metadata.get("runtime_draft_action_id"):
            return {
                "success": False,
                "message": self._build_pending_message(action, pending_fields, records=list(payload.dados or [])),
                "detalhes": {
                    "resumo": {"criados": 0, "erros": 0},
                    "registros": [],
                    "erros_lista": [],
                    "pendencias": pending_fields,
                },
            }

        records = list(payload.dados or [])
        action_id = str(payload.metadata.get("runtime_pending_action_id") or "").strip()
        if not records and action_id:
            pending = self.pending_actions.get(action_id)
            if pending is not None and pending.user_id == payload.user_id:
                records = list(pending.records)

        if not records:
            return {
                "success": False,
                "message": "Nenhum registro disponivel para confirmar.",
                "detalhes": None,
            }

        created: list[dict[str, Any]] = []
        follow_up_actions: list[dict[str, Any]] = []
        errors: list[str] = []

        for index, record in enumerate(records, start=1):
            try:
                normalized_record, missing = await self._normalize_record(
                    action=action,
                    record=record,
                    user_id=payload.user_id,
                )
                if missing:
                    raise ValueError("Faltam campos obrigatorios: " + ", ".join(missing))

                result = await self._execute_confirmed_record(
                    action=action,
                    record=normalized_record,
                    user_id=payload.user_id,
                )
                created.append(result)

                if action == "criar_cliente":
                    follow_up = await self._resume_fatura_after_cliente_creation(
                        normalized_record=normalized_record,
                        created_cliente=result,
                        user_id=payload.user_id,
                    )
                    if follow_up:
                        follow_up_actions.append(follow_up)
            except Exception as exc:
                errors.append(f"Linha {index}: {exc}")

        if action_id:
            self.pending_actions.pop(action_id)

        total_created = len(created)
        total_errors = len(errors)
        success = total_created > 0 and total_errors == 0
        sync_operations = [self._as_string(record.get("sync_operation")) for record in created]
        total_sync_create = sum(1 for operation in sync_operations if operation == "criar")
        total_sync_update = sum(1 for operation in sync_operations if operation == "atualizar")
        total_sync_noop = sum(1 for operation in sync_operations if operation == "sem_alteracao")
        resumo = {
            "criados": total_created,
            "erros": total_errors,
        }

        if action == "sincronizar_clientes" or any(sync_operations):
            resumo.update(
                {
                    "processados": total_created,
                    "novos": total_sync_create,
                    "atualizados": total_sync_update,
                    "sem_alteracao": total_sync_noop,
                }
            )

        message = self._build_confirmation_message(
            action=action,
            created=created,
            follow_up_actions=follow_up_actions,
            errors=errors,
        )

        return {
            "success": success or total_created > 0,
            "message": message,
            "detalhes": {
                "resumo": resumo,
                "registros": created,
                "acoes_encadeadas": follow_up_actions,
                "erros_lista": errors[:5],
            },
        }

    async def _load_session_context(self, payload: ChatPayload) -> dict[str, Any]:
        try:
            return await self.laravel_client.session_context(
                user_id=payload.user_id,
                session_id=payload.session_id,
                limit=self.settings.default_history_limit,
            )
        except LaravelApiError:
            return {
                "user": {
                    "id": payload.user_id,
                    "name": payload.user_name,
                    "email": payload.user_email,
                    "role": "user",
                },
                "session_id": payload.session_id,
                "messages": [],
            }

    async def _enrich_multimodal_attachment(
        self,
        payload: ChatPayload,
        parsed_file: ParsedFile,
    ) -> ParsedFile:
        file_payload = payload.arquivo
        if file_payload is None:
            return parsed_file

        extension = (file_payload.extensao or "").lower()
        mime_type = (file_payload.mime_type or "").lower()

        if parsed_file.mode == "image" or is_image_attachment(extension, mime_type):
            extracted = await self._extract_image_text(file_payload)
            if extracted:
                return replace(
                    parsed_file,
                    text=extracted,
                    message="Imagem processada com sucesso. Vou usar o conteudo extraido para continuar a analise.",
                )
            return replace(
                parsed_file,
                supported=False,
                message="Recebi a imagem, mas nao consegui extrair o conteudo automaticamente. Vou continuar com o contexto disponivel e pedir apenas o que faltar.",
            )

        if parsed_file.mode == "audio" or is_audio_attachment(extension, mime_type):
            extracted = await self._transcribe_audio(file_payload)
            if extracted:
                return replace(
                    parsed_file,
                    text=extracted,
                    message="Audio transcrito com sucesso. Vou usar a transcricao como parte da conversa atual.",
                )
            return replace(
                parsed_file,
                supported=False,
                message="Recebi o audio, mas nao consegui transcrever automaticamente. Vou continuar com o contexto disponivel e pedir apenas o que faltar.",
            )

        return parsed_file

    async def _extract_image_text(self, file_payload) -> str | None:
        raw = self._decode_attachment_bytes(file_payload.base64)
        if raw is None:
            return None

        mime_type = file_payload.mime_type or "image/jpeg"
        prompt = (
            "Voce esta analisando uma imagem enviada ao chatbot financeiro do MedIntelligence. "
            "Extraia o texto visivel, identifique o tipo de documento quando possivel e resuma os campos "
            "financeiros relevantes em portugues do Brasil. Nao invente dados ausentes."
        )

        response = await self.openai_client.chat.completions.create(
            model=self.settings.openai_vision_model or self.settings.openai_model,
            temperature=0,
            messages=[
                {
                    "role": "user",
                    "content": [
                        {"type": "text", "text": prompt},
                        {
                            "type": "image_url",
                            "image_url": {
                                "url": f"data:{mime_type};base64,{base64.b64encode(raw).decode('utf-8')}",
                            },
                        },
                    ],
                }
            ],
        )

        return self._first_choice_text(response)

    async def _transcribe_audio(self, file_payload) -> str | None:
        raw = self._decode_attachment_bytes(file_payload.base64)
        if raw is None:
            return None

        audio_buffer = io.BytesIO(raw)
        audio_buffer.name = file_payload.nome or "audio.webm"

        response = await self.openai_client.audio.transcriptions.create(
            model=self.settings.openai_transcription_model,
            file=audio_buffer,
        )

        text = getattr(response, "text", None)
        if text:
            return str(text).strip() or None
        return None

    def _decode_attachment_bytes(self, base64_payload: str | None) -> bytes | None:
        if not base64_payload:
            return None

        try:
            return base64.b64decode(base64_payload)
        except Exception:
            return None

    def _first_choice_text(self, response: Any) -> str | None:
        choices = getattr(response, "choices", None) or []
        if not choices:
            return None

        message = getattr(choices[0], "message", None)
        content = getattr(message, "content", None)
        if isinstance(content, str):
            cleaned = content.strip()
            return cleaned or None
        return None

    def _runtime_error_response(self, exc: Exception) -> ChatbotResponse:
        if isinstance(exc, AuthenticationError):
            message = "OPENAI_API_KEY invalida ou nao autorizada no runtime do chatbot."
        elif isinstance(exc, RateLimitError):
            message = "A OpenAI recusou a solicitacao por limite de uso. Tente novamente em instantes."
        elif isinstance(exc, APIConnectionError):
            message = "Nao foi possivel conectar ao provedor de IA neste momento."
        elif isinstance(exc, LaravelApiError):
            message = str(exc) or "Falha ao consultar os dados internos do sistema pelo runtime do chatbot."
        else:
            message = "Falha ao processar a solicitacao no runtime do chatbot."

        return ChatbotResponse(
            mensagem=message,
            acao_sugerida=None,
            dados_estruturados=None,
        )

    def _build_messages(
        self,
        session_context: dict[str, Any],
        payload: ChatPayload,
        current_message: str,
    ) -> list[dict[str, str]]:
        messages: list[dict[str, str]] = []

        for item in session_context.get("messages") or []:
            role = "assistant" if item.get("role") == "assistant" else "user"
            content = str(item.get("content") or "").strip()
            if content:
                messages.append({"role": role, "content": content})

        current_raw = (payload.mensagem or "").strip()
        if messages and messages[-1]["role"] == "user" and messages[-1]["content"].strip() == current_raw:
            messages[-1] = {"role": "user", "content": current_message}
        else:
            messages.append({"role": "user", "content": current_message})

        return messages

    def _normalize_agent_response(self, result: Any) -> ChatbotResponse:
        structured = None
        if isinstance(result, dict):
            structured = result.get("structured_response") or result.get("output") or result
        else:
            structured = result

        if isinstance(structured, ChatbotResponse):
            response = structured
        elif isinstance(structured, str):
            response = ChatbotResponse(mensagem=structured)
        else:
            response = ChatbotResponse.model_validate(
                structured
                or {
                    "mensagem": "Nao foi possivel estruturar a resposta do agente.",
                    "acao_sugerida": None,
                    "dados_estruturados": None,
                }
            )

        if response.dados_estruturados is not None:
            registros = list(response.dados_estruturados.dados_mapeados or [])
            if not response.dados_estruturados.colunas and registros:
                response.dados_estruturados.colunas = self._build_columns(registros)
            if not response.dados_estruturados.total_registros:
                response.dados_estruturados.total_registros = len(registros)
            response.dados_estruturados.sucesso = True

        return response

    def _build_greeting_response(
        self,
        *,
        payload: ChatPayload,
        parsed_file: ParsedFile,
        session_context: dict[str, Any],
        session_draft: Any | None,
    ) -> ChatbotResponse | None:
        if payload.arquivo is not None or parsed_file.text or session_draft is not None:
            return None

        message = (payload.mensagem or "").strip()
        if not self._is_plain_greeting(message):
            return None

        first_name = self._first_name(payload.user_name)
        saudacao = self._preferred_greeting(message)
        nome = f", {first_name}" if first_name else ""

        if list(session_context.get("messages") or []):
            texto = (
                f"{saudacao}{nome}. Estou pronto para continuar. "
                "Posso consultar faturamento, analisar um arquivo ou executar alguma rotina financeira."
            )
        else:
            texto = (
                f"{saudacao}{nome}. Bem-vindo ao MedIntelligence. "
                "Posso consultar faturamento, gerar faturas, sincronizar clientes ou analisar arquivos, imagens e audios."
            )

        return ChatbotResponse(
            mensagem=texto,
            acao_sugerida=None,
            dados_estruturados=None,
        )

    async def _build_action_preview(
        self,
        *,
        payload: ChatPayload,
        plan: ActionPlan,
    ) -> ChatbotResponse | None:
        action = self._canonical_action(plan.acao_sugerida)
        if action is None:
            return None

        records = list(plan.dados_mapeados or [])
        pending_hints = sorted({field for field in (plan.pendencias or []) if field})
        if not records:
            message = plan.mensagem or "Nao encontrei dados suficientes para preparar a acao."
            if pending_hints:
                draft = self.pending_actions.save(
                    action=action,
                    records=[],
                    user_id=payload.user_id,
                    session_id=payload.session_id,
                    metadata={
                        "fonte": "langchain-runtime",
                        "state": "draft",
                        "pending_fields": pending_hints,
                    },
                )
                return ChatbotResponse(
                    mensagem=self._build_pending_message(action, pending_hints, records=[]),
                    acao_sugerida=action,
                    dados_estruturados=StructuredData(
                        tipo=self._action_type(action),
                        dados_mapeados=[],
                        colunas=[],
                        acao_sugerida=action,
                        total_registros=0,
                        confianca=plan.confianca,
                        metadata={
                            "fonte": "langchain-runtime",
                            "runtime_draft_action_id": draft.action_id,
                            "runtime_pending_fields": pending_hints,
                            "runtime_requires_more_info": True,
                        },
                    ),
                )
            return ChatbotResponse(mensagem=message)

        normalized_records: list[dict[str, Any]] = []
        pending_fields: list[str] = []

        for record in records:
            normalized_record, missing = await self._normalize_record(
                action=action,
                record=record,
                user_id=payload.user_id,
            )
            normalized_records.append(normalized_record)
            pending_fields.extend(missing)

        pending_fields = sorted({field for field in pending_fields if field})
        normalized_records = [record for record in normalized_records if record]

        if not normalized_records:
            return ChatbotResponse(
                mensagem=plan.mensagem or "Nao encontrei dados suficientes para preparar a acao.",
            )

        if pending_fields:
            auto_cliente_preview = await self._build_auto_cliente_preview_for_fatura(
                payload=payload,
                action=action,
                normalized_records=normalized_records,
                pending_fields=pending_fields,
                confidence=plan.confianca,
            )
            if auto_cliente_preview is not None:
                return auto_cliente_preview

            draft = self.pending_actions.save(
                action=action,
                records=normalized_records,
                user_id=payload.user_id,
                session_id=payload.session_id,
                metadata={
                    "fonte": "langchain-runtime",
                    "state": "draft",
                    "pending_fields": pending_fields,
                },
            )
            message = self._build_pending_message(action, pending_fields, records=normalized_records)
            return ChatbotResponse(
                mensagem=message,
                acao_sugerida=action,
                dados_estruturados=StructuredData(
                    tipo=self._action_type(action),
                    dados_mapeados=normalized_records,
                    colunas=self._build_columns(normalized_records),
                    acao_sugerida=action,
                    total_registros=len(normalized_records),
                    confianca=plan.confianca,
                    metadata={
                        "fonte": "langchain-runtime",
                        "runtime_draft_action_id": draft.action_id,
                        "runtime_pending_fields": pending_fields,
                        "runtime_requires_more_info": True,
                    },
                ),
            )

        pending = self.pending_actions.save(
            action=action,
            records=normalized_records,
            user_id=payload.user_id,
            session_id=payload.session_id,
            metadata={
                "fonte": "langchain-runtime",
                "state": "pending_confirmation",
            },
        )

        structured = StructuredData(
            tipo=self._action_type(action),
            dados_mapeados=normalized_records,
            colunas=self._build_columns(normalized_records),
            acao_sugerida=action,
            total_registros=len(normalized_records),
            confianca=plan.confianca,
            metadata={
                "fonte": "langchain-runtime",
                "runtime_pending_action_id": pending.action_id,
                "runtime_requires_confirmation": True,
            },
        )

        message = self._build_preview_message(
            action=action,
            records=normalized_records,
            fallback=plan.mensagem,
        )
        return ChatbotResponse(
            mensagem=message,
            acao_sugerida=action,
            dados_estruturados=structured,
        )

    async def _build_auto_cliente_preview_for_fatura(
        self,
        *,
        payload: ChatPayload,
        action: str,
        normalized_records: list[dict[str, Any]],
        pending_fields: list[str],
        confidence: float | None,
    ) -> ChatbotResponse | None:
        if action != "gerar_fatura":
            return None

        unique_pending_fields = sorted({field for field in pending_fields if field})
        if unique_pending_fields != ["cliente"]:
            return None

        if any(self._cliente_candidate_labels([record]) for record in normalized_records):
            return None

        cliente_records = self._build_cliente_records_from_fatura_draft(normalized_records)
        if len(cliente_records) != 1:
            return None

        cliente_record = self._normalize_cliente_record(cliente_records[0])
        cliente_missing_fields = self._missing_cliente_fields(cliente_record)
        if cliente_missing_fields:
            return None

        draft = self.pending_actions.save(
            action="gerar_fatura",
            records=normalized_records,
            user_id=payload.user_id,
            session_id=payload.session_id,
            metadata={
                "fonte": "langchain-runtime",
                "state": "draft",
                "pending_fields": unique_pending_fields,
                "auto_cliente_creation": True,
            },
        )

        cliente_preview_record = {
            **cliente_record,
            "_resume_fatura_draft_action_id": draft.action_id,
        }

        pending = self.pending_actions.save(
            action="criar_cliente",
            records=[cliente_preview_record],
            user_id=payload.user_id,
            session_id=payload.session_id,
            metadata={
                "fonte": "langchain-runtime",
                "state": "pending_confirmation",
                "origin_action": "gerar_fatura",
            },
        )

        message = self._build_preview_message(
            action="criar_cliente",
            records=[cliente_preview_record],
            fallback=(
                "Nao encontrei esse cliente no cadastro. "
                "Preparei o cadastro do cliente extraido da planilha e, depois da confirmacao, "
                "continuo automaticamente a fatura."
            ),
        )

        return ChatbotResponse(
            mensagem=message,
            acao_sugerida="criar_cliente",
            dados_estruturados=StructuredData(
                tipo=self._action_type("criar_cliente"),
                dados_mapeados=[cliente_preview_record],
                colunas=self._build_columns([cliente_preview_record]),
                acao_sugerida="criar_cliente",
                total_registros=1,
                confianca=confidence,
                metadata={
                    "fonte": "langchain-runtime",
                    "runtime_pending_action_id": pending.action_id,
                    "runtime_requires_confirmation": True,
                    "runtime_origin_action": "gerar_fatura",
                },
            ),
        )

    async def _normalize_record(
        self,
        *,
        action: str,
        record: dict[str, Any],
        user_id: int,
    ) -> tuple[dict[str, Any], list[str]]:
        if action == "sincronizar_clientes":
            return await self._normalize_cliente_sync_record(record, user_id)
        if action == "criar_cliente":
            return self._normalize_cliente_record(record), self._missing_cliente_fields(record)
        if action in {"inativar_cliente", "reativar_cliente"}:
            return await self._normalize_cliente_status_record(action, record, user_id), self._missing_cliente_status_fields(record)
        if action == "baixar_titulo":
            return await self._normalize_baixa_titulo_record(record, user_id), self._missing_baixa_titulo_fields(record)
        if action == "baixar_despesa":
            return await self._normalize_baixa_despesa_record(record, user_id), self._missing_baixa_despesa_fields(record)
        if action == "renegociar_titulo":
            return await self._normalize_renegociacao_titulo_record(record, user_id), self._missing_renegociacao_titulo_fields(record)
        if action == "emitir_nfse":
            return await self._normalize_emitir_nfse_record(record, user_id), self._missing_emitir_nfse_fields(record)
        if action == "criar_conta_receber":
            return await self._normalize_conta_receber_record(record, user_id), self._missing_receber_fields(record)
        if action == "criar_conta_pagar":
            return await self._normalize_conta_pagar_record(record, user_id), self._missing_pagar_fields(record)
        if action == "gerar_fatura":
            return await self._normalize_fatura_record(record, user_id), self._missing_fatura_fields(record)
        return record, []

    async def _normalize_cliente_sync_record(
        self,
        record: dict[str, Any],
        user_id: int,
    ) -> tuple[dict[str, Any], list[str]]:
        normalized = self._normalize_cliente_record(record)
        missing = self._missing_cliente_sync_fields(normalized)

        normalized["cliente_label"] = (
            normalized.get("razao_social")
            or normalized.get("nome_fantasia")
            or normalized.get("cnpj")
        )

        if missing:
            normalized["sync_operation"] = "pendente"
            normalized["changed_fields"] = []
            return normalized, missing

        preview = await self.laravel_client.upsert_cliente(
            user_id=user_id,
            payload={
                **normalized,
                "dry_run": True,
            },
        )

        normalized.update(
            {
                "cliente_id": preview.get("cliente_id") or normalized.get("cliente_id"),
                "sync_operation": self._as_string(preview.get("sync_operation")) or "criar",
                "matched_by": self._as_string(preview.get("matched_by")),
                "changed_fields": list(preview.get("changed_fields") or []),
                "cliente_label": self._as_string(preview.get("cliente_label")) or normalized.get("cliente_label"),
            }
        )

        return normalized, []

    def _normalize_cliente_record(self, record: dict[str, Any]) -> dict[str, Any]:
        hidden_fields = {
            key: value
            for key, value in record.items()
            if str(key).startswith("_") and value not in (None, "")
        }
        normalized = {
            "cnpj": self._clean_document(record.get("cnpj")),
            "razao_social": self._upper(record.get("razao_social")),
            "nome_fantasia": self._upper(record.get("nome_fantasia")),
            "email": self._lower(record.get("email")),
            "telefone": self._as_string(record.get("telefone")),
            "celular": self._as_string(record.get("celular")),
            "cep": self._as_string(record.get("cep")),
            "logradouro": self._as_string(record.get("logradouro") or record.get("endereco")),
            "numero": self._as_string(record.get("numero")),
            "bairro": self._title(record.get("bairro")),
            "complemento": self._as_string(record.get("complemento")),
            "cidade": self._title(record.get("cidade")),
            "uf": self._upper(record.get("uf")),
            "status": self._as_string(record.get("status")) or "ativo",
        }

        normalized = {
            **{key: value for key, value in normalized.items() if value not in (None, "")},
            **hidden_fields,
        }

        return normalized

    async def _normalize_conta_receber_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        client_id = self._to_int(record.get("cliente_id"))
        if client_id is None:
            client_id = await self._resolve_cliente_id(record, user_id)

        normalized = {
            "cliente_id": client_id,
            "descricao": self._as_string(record.get("descricao") or record.get("historico") or record.get("nome")),
            "valor_original": self._parse_decimal(
                record.get("valor_original") or record.get("valor") or record.get("valor_total")
            ),
            "data_vencimento": self._normalize_date(record.get("data_vencimento") or record.get("vencimento")),
            "data_emissao": self._normalize_date(record.get("data_emissao") or record.get("emissao"))
            or self._today(),
            "competencia": self._normalize_competencia(record.get("competencia") or record.get("periodo")),
            "plano_conta_id": self._to_int(record.get("plano_conta_id")),
            "centro_custo_id": self._to_int(record.get("centro_custo_id")),
            "observacoes": self._as_string(record.get("observacoes") or record.get("obs")),
            "numero_titulo": self._as_string(record.get("numero_titulo")),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_cliente_status_record(
        self,
        action: str,
        record: dict[str, Any],
        user_id: int,
    ) -> dict[str, Any]:
        client_id = self._to_int(record.get("cliente_id"))
        if client_id is None:
            client_id = await self._resolve_cliente_id(record, user_id)

        cliente_label = self._as_string(
            record.get("cliente")
            or record.get("cliente_nome")
            or record.get("razao_social")
            or record.get("nome_fantasia")
        )

        normalized = {
            "cliente_id": client_id,
            "status": "inativo" if action == "inativar_cliente" else "ativo",
            "cliente_label": cliente_label or self._clean_document(record.get("cliente_cnpj") or record.get("cnpj")),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_baixa_titulo_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        matched = await self._find_titulo_match(record, user_id)
        titulo_id = self._to_int(record.get("titulo_id")) or self._to_int(matched.get("id") if matched else None)

        valor = self._parse_decimal(
            record.get("valor")
            or record.get("valor_pago")
            or record.get("valor_baixa")
            or (matched.get("valor_saldo") if matched else None)
            or (matched.get("valor_original") if matched else None)
        )

        normalized = {
            "titulo_id": titulo_id,
            "valor": valor,
            "forma_pagamento": self._as_string(record.get("forma_pagamento")),
            "data_pagamento": self._normalize_date(record.get("data_pagamento")) or self._today(),
            "titulo_label": self._as_string(
                record.get("numero_titulo")
                or record.get("titulo")
                or record.get("descricao")
                or (matched.get("descricao") if matched else None)
                or (matched.get("numero_titulo") if matched else None)
            ),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_baixa_despesa_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        matched = await self._find_despesa_match(record, user_id)
        despesa_id = self._to_int(record.get("despesa_id")) or self._to_int(matched.get("id") if matched else None)

        valor = self._parse_decimal(
            record.get("valor")
            or record.get("valor_pago")
            or record.get("valor_baixa")
            or (matched.get("valor_original") if matched else None)
            or (matched.get("valor") if matched else None)
        )

        normalized = {
            "despesa_id": despesa_id,
            "valor": valor,
            "data_pagamento": self._normalize_date(record.get("data_pagamento")) or self._today(),
            "despesa_label": self._as_string(
                record.get("descricao")
                or record.get("despesa")
                or (matched.get("descricao") if matched else None)
            ),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_renegociacao_titulo_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        matched = await self._find_titulo_match(record, user_id)
        titulo_id = self._to_int(record.get("titulo_id")) or self._to_int(matched.get("id") if matched else None)

        normalized = {
            "titulo_id": titulo_id,
            "nova_data_vencimento": self._normalize_date(
                record.get("nova_data_vencimento")
                or record.get("novo_vencimento")
                or record.get("data_vencimento")
                or record.get("vencimento")
            ),
            "observacoes": self._as_string(record.get("observacoes") or record.get("obs")),
            "titulo_label": self._as_string(
                record.get("numero_titulo")
                or record.get("titulo")
                or record.get("descricao")
                or (matched.get("descricao") if matched else None)
                or (matched.get("numero_titulo") if matched else None)
            ),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_emitir_nfse_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        matched = await self._find_fatura_match(record, user_id)
        fatura_id = self._to_int(record.get("fatura_id")) or self._to_int(matched.get("id") if matched else None)

        normalized = {
            "fatura_id": fatura_id,
            "codigo_servico": self._as_string(record.get("codigo_servico")),
            "discriminacao": self._as_string(
                record.get("discriminacao")
                or record.get("descricao")
                or record.get("observacoes")
                or (matched.get("observacoes") if matched else None)
            ),
            "fatura_label": self._as_string(
                record.get("numero_fatura")
                or record.get("fatura")
                or (matched.get("numero_fatura") if matched else None)
            ),
            "periodo_referencia": self._as_string(
                record.get("periodo_referencia")
                or (matched.get("periodo_referencia") if matched else None)
            ),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_conta_pagar_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        fornecedor_id = self._to_int(record.get("fornecedor_id"))
        if fornecedor_id is None:
            fornecedor_id = await self._resolve_fornecedor_id(record, user_id)

        valor = self._parse_decimal(
            record.get("valor_original") or record.get("valor") or record.get("valor_total")
        )
        normalized = {
            "fornecedor_id": fornecedor_id,
            "descricao": self._as_string(record.get("descricao") or record.get("historico") or record.get("nome")),
            "valor_original": valor,
            "valor": valor,
            "data_vencimento": self._normalize_date(record.get("data_vencimento") or record.get("vencimento")),
            "data_emissao": self._normalize_date(record.get("data_emissao") or record.get("emissao"))
            or self._today(),
            "categoria_id": self._to_int(record.get("categoria_id")),
            "plano_conta_id": self._to_int(record.get("plano_conta_id")),
            "observacoes": self._as_string(record.get("observacoes") or record.get("obs")),
            "codigo_barras": self._as_string(record.get("codigo_barras") or record.get("linha_digitavel")),
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

    async def _normalize_fatura_record(self, record: dict[str, Any], user_id: int) -> dict[str, Any]:
        client_rows = await self._search_cliente_candidates(record, user_id)
        client_id = self._resolve_cliente_id_from_rows(record, client_rows)

        cliente_nome = self._as_string(
            record.get("cliente")
            or record.get("cliente_nome")
            or record.get("razao_social")
            or record.get("nome_fantasia")
        )
        cliente_cnpj = self._clean_document(
            record.get("cliente_cnpj") or record.get("cnpj_cliente") or record.get("cnpj")
        )

        items = [
            normalized_item
            for normalized_item in (
                self._normalize_fatura_item(item)
                for item in self._extract_fatura_items(record)
            )
            if normalized_item
        ]
        metadata = self._normalize_fatura_metadata(record.get("metadata"))
        observacoes = self._compose_fatura_observacoes(
            record.get("observacoes"),
            metadata=metadata,
        )

        normalized = {
            "cliente_id": client_id,
            "cliente": cliente_nome,
            "cliente_cnpj": cliente_cnpj,
            "_cliente_candidates": self._format_cliente_candidates(client_rows) if client_id is None else None,
            "data_emissao": self._normalize_date(record.get("data_emissao") or record.get("emissao"))
            or self._today(),
            "data_vencimento": self._normalize_date(record.get("data_vencimento") or record.get("vencimento")),
            "periodo_referencia": self._normalize_periodo_referencia(
                record.get("periodo_referencia") or record.get("competencia") or record.get("periodo")
            ),
            "status": self._as_string(record.get("status")) or "pendente",
            "observacoes": observacoes,
            "metadata": metadata,
            "gerar_titulo": bool(record.get("gerar_titulo", True)),
            "itens": items,
        }

        return {key: value for key, value in normalized.items() if value not in (None, "", [])}

    def _extract_fatura_items(self, record: dict[str, Any]) -> list[dict[str, Any]]:
        raw_items = record.get("itens")
        if isinstance(raw_items, list):
            return [item for item in raw_items if isinstance(item, dict)]

        fallback_item = {
            "servico_id": record.get("servico_id"),
            "descricao": record.get("descricao")
            or record.get("servico")
            or record.get("nome_servico")
            or record.get("historico")
            or record.get("nome"),
            "quantidade": record.get("quantidade"),
            "valor_unitario": record.get("valor_unitario"),
            "valor_total": record.get("valor_total"),
            "valor": record.get("valor"),
        }

        if any(value not in (None, "") for value in fallback_item.values()):
            return [fallback_item]

        return []

    def _normalize_fatura_item(self, item: dict[str, Any]) -> dict[str, Any] | None:
        quantidade = self._parse_decimal(item.get("quantidade")) or 1.0
        valor_unitario = self._parse_decimal(item.get("valor_unitario"))
        valor_total = self._parse_decimal(item.get("valor_total") or item.get("valor"))

        if valor_unitario is None and valor_total is not None:
            valor_unitario = valor_total / quantidade if quantidade else valor_total

        normalized = {
            "servico_id": self._to_int(item.get("servico_id")),
            "descricao": self._as_string(
                item.get("descricao")
                or item.get("servico")
                or item.get("nome_servico")
                or item.get("historico")
                or item.get("nome")
            ),
            "quantidade": quantidade,
            "valor_unitario": valor_unitario,
        }

        normalized = {key: value for key, value in normalized.items() if value not in (None, "")}
        if "descricao" not in normalized and "valor_unitario" not in normalized:
            return None

        return normalized

    def _normalize_fatura_metadata(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        metadata = {
            "origem_importacao": self._as_string(value.get("origem_importacao")),
            "unidade": self._as_string(value.get("unidade")),
            "numero_funcionarios": self._to_int(value.get("numero_funcionarios")),
            "quantidade_funcionarios_registrados": self._to_int(value.get("quantidade_funcionarios_registrados")),
            "quantidade_exames_registrados": self._to_int(value.get("quantidade_exames_registrados")),
        }

        funcionarios = [
            funcionario
            for funcionario in (self._normalize_fatura_funcionario(item) for item in value.get("funcionarios") or [])
            if funcionario
        ]
        exames = [
            exame
            for exame in (self._normalize_fatura_exame(item) for item in value.get("exames") or [])
            if exame
        ]

        if funcionarios:
            metadata["funcionarios"] = funcionarios[:50]
        if exames:
            metadata["exames"] = exames[:50]

        metadata = {key: value for key, value in metadata.items() if value not in (None, "", [])}
        return metadata or None

    def _normalize_fatura_funcionario(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        funcionario = {
            "nome": self._as_string(value.get("nome")),
            "setor": self._as_string(value.get("setor")),
            "situacao": self._as_string(value.get("situacao")),
            "matricula": self._to_int(value.get("matricula")),
            "valor_cobrar": self._parse_decimal(value.get("valor_cobrar")),
        }
        funcionario = {key: value for key, value in funcionario.items() if value not in (None, "")}

        if not funcionario.get("nome") and funcionario.get("matricula") is None:
            return None

        return funcionario

    def _normalize_fatura_exame(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        exame = {
            "nome": self._as_string(value.get("nome")),
            "quantidade": self._parse_decimal(value.get("quantidade")),
            "valor_cobrar": self._parse_decimal(value.get("valor_cobrar")),
        }
        exame = {key: value for key, value in exame.items() if value not in (None, "")}

        if not exame.get("nome"):
            return None

        return exame

    def _compose_fatura_observacoes(self, raw_value: Any, *, metadata: dict[str, Any] | None) -> str | None:
        observacoes = self._as_string(raw_value)
        metadata_summary = self._build_fatura_metadata_summary(metadata)

        if observacoes and metadata_summary:
            if metadata_summary in observacoes:
                return observacoes
            return f"{observacoes}\n\n{metadata_summary}"

        return observacoes or metadata_summary

    def _build_fatura_metadata_summary(self, metadata: dict[str, Any] | None) -> str | None:
        if not metadata:
            return None

        partes: list[str] = []
        unidade = self._as_string(metadata.get("unidade"))
        if unidade:
            partes.append(f"Unidade: {unidade}.")

        funcionarios = metadata.get("funcionarios") or []
        total_funcionarios = (
            self._to_int(metadata.get("numero_funcionarios"))
            or self._to_int(metadata.get("quantidade_funcionarios_registrados"))
            or len(funcionarios)
        )
        if funcionarios:
            exemplos = ", ".join(self._format_fatura_funcionario_texto(item) for item in funcionarios[:8])
            sufixo = ""
            if total_funcionarios > 8:
                sufixo = f" + {total_funcionarios - 8} outro(s)"
            partes.append(f"Funcionários vinculados ({total_funcionarios}): {exemplos}{sufixo}.")
        elif total_funcionarios:
            partes.append(f"Funcionários vinculados: {total_funcionarios}.")

        exames = metadata.get("exames") or []
        total_exames = self._to_int(metadata.get("quantidade_exames_registrados")) or len(exames)
        if exames:
            exemplos = ", ".join(self._format_fatura_exame_texto(item) for item in exames[:10])
            sufixo = ""
            if total_exames > 10:
                sufixo = f" + {total_exames - 10} outro(s)"
            partes.append(f"Exames identificados: {exemplos}{sufixo}.")

        if not partes:
            return None

        return "Detalhes analíticos do anexo: " + " ".join(partes)

    def _format_fatura_funcionario_texto(self, value: dict[str, Any]) -> str:
        nome = self._as_string(value.get("nome")) or "Sem nome"
        detalhes: list[str] = []
        if self._as_string(value.get("setor")):
            detalhes.append(self._as_string(value.get("setor")))
        if self._to_int(value.get("matricula")) is not None:
            detalhes.append(f"matrícula {self._to_int(value.get('matricula'))}")
        if self._as_string(value.get("situacao")):
            detalhes.append(self._as_string(value.get("situacao")).lower())

        if detalhes:
            return f"{nome} ({', '.join(detalhes)})"
        return nome

    def _format_fatura_exame_texto(self, value: dict[str, Any]) -> str:
        nome = self._as_string(value.get("nome")) or "Exame"
        detalhes: list[str] = []
        quantidade = self._parse_decimal(value.get("quantidade"))
        valor = self._parse_decimal(value.get("valor_cobrar"))

        if quantidade is not None:
            detalhes.append(f"{self._format_decimal_for_text(quantidade)}x")
        if valor is not None:
            detalhes.append(self._format_currency(valor))

        if detalhes:
            return f"{nome} ({', '.join(detalhes)})"
        return nome

    async def _resolve_cliente_id(self, record: dict[str, Any], user_id: int) -> int | None:
        rows = await self._search_cliente_candidates(record, user_id)
        return self._resolve_cliente_id_from_rows(record, rows)

    async def _search_cliente_candidates(self, record: dict[str, Any], user_id: int) -> list[dict[str, Any]]:
        document = self._clean_document(
            record.get("cliente_cnpj") or record.get("cnpj_cliente") or record.get("cnpj")
        )
        name = self._upper(
            record.get("cliente")
            or record.get("cliente_nome")
            or record.get("razao_social")
            or record.get("nome_fantasia")
        )

        query = document or name
        if not query:
            return []

        return list(
            await self.laravel_client.search_clientes(
                user_id=user_id,
                query=query,
                limit=self.settings.max_result_rows,
            )
        )

    def _resolve_cliente_id_from_rows(self, record: dict[str, Any], rows: list[dict[str, Any]]) -> int | None:
        if not rows:
            return None

        document = self._clean_document(
            record.get("cliente_cnpj") or record.get("cnpj_cliente") or record.get("cnpj")
        )
        name = self._upper(
            record.get("cliente")
            or record.get("cliente_nome")
            or record.get("razao_social")
            or record.get("nome_fantasia")
        )

        exact_ids: list[int] = []
        for item in rows:
            item_id = self._to_int(item.get("id"))
            if item_id is None:
                continue

            if document and self._clean_document(item.get("cnpj")) == document:
                exact_ids.append(item_id)
                continue

            if name and self._upper(item.get("razao_social")) == name:
                exact_ids.append(item_id)
                continue

            if name and self._upper(item.get("nome_fantasia")) == name:
                exact_ids.append(item_id)
                continue

        exact_ids = list(dict.fromkeys(exact_ids))
        if len(exact_ids) == 1:
            return exact_ids[0]

        if len(rows) == 1:
            return self._to_int(rows[0].get("id"))

        return None

    def _format_cliente_candidates(self, rows: list[dict[str, Any]], limit: int = 3) -> list[dict[str, Any]]:
        formatted: list[dict[str, Any]] = []
        for item in rows[:limit]:
            item_id = self._to_int(item.get("id"))
            if item_id is None:
                continue

            formatted.append(
                {
                    "id": item_id,
                    "razao_social": self._as_string(item.get("razao_social")),
                    "nome_fantasia": self._as_string(item.get("nome_fantasia")),
                    "cnpj": self._clean_document(item.get("cnpj")),
                }
            )

        return formatted

    async def _resolve_fornecedor_id(self, record: dict[str, Any], user_id: int) -> int | None:
        document = self._clean_document(
            record.get("fornecedor_cnpj")
            or record.get("cnpj_fornecedor")
            or record.get("cnpj")
            or record.get("cpf")
        )
        name = self._upper(
            record.get("fornecedor")
            or record.get("fornecedor_nome")
            or record.get("nome_fornecedor")
            or record.get("razao_social_fornecedor")
        )

        query = document or name
        if not query:
            return None

        rows = await self.laravel_client.search_fornecedores(
            user_id=user_id,
            query=query,
            limit=self.settings.max_result_rows,
        )

        for item in rows:
            if document and self._clean_document(item.get("cnpj")) == document:
                return self._to_int(item.get("id"))
            if document and self._clean_document(item.get("cpf")) == document:
                return self._to_int(item.get("id"))
            if name and self._upper(item.get("razao_social")) == name:
                return self._to_int(item.get("id"))
            if name and self._upper(item.get("nome_fantasia")) == name:
                return self._to_int(item.get("id"))

        return self._to_int(rows[0].get("id")) if rows else None

    async def _find_titulo_match(self, record: dict[str, Any], user_id: int) -> dict[str, Any] | None:
        titulo_id = self._to_int(record.get("titulo_id"))
        if titulo_id is not None:
            return {"id": titulo_id}

        cliente_id = self._to_int(record.get("cliente_id"))
        if cliente_id is None:
            cliente_id = await self._resolve_cliente_id(record, user_id)

        query = self._as_string(
            record.get("numero_titulo")
            or record.get("titulo")
            or record.get("descricao")
            or record.get("historico")
            or record.get("cliente")
            or record.get("cliente_nome")
        )
        valor = self._parse_decimal(record.get("valor") or record.get("valor_pago") or record.get("valor_baixa"))
        data_vencimento = self._normalize_date(record.get("data_vencimento") or record.get("vencimento"))

        rows = await self.laravel_client.search_titulos(
            user_id=user_id,
            query=query,
            cliente_id=cliente_id,
            tipo="receber",
            status="aberto",
            limit=self.settings.max_result_rows,
        )

        candidates = list(rows)
        if data_vencimento:
            candidates = [row for row in candidates if self._normalize_date(row.get("data_vencimento")) == data_vencimento] or candidates

        if valor is not None:
            exact_by_value = [
                row for row in candidates
                if any(
                    self._parse_decimal(row.get(key)) is not None
                    and abs((self._parse_decimal(row.get(key)) or 0) - valor) <= 0.01
                    for key in ("valor_saldo", "valor_original")
                )
            ]
            candidates = exact_by_value or candidates

        return candidates[0] if len(candidates) == 1 else None

    async def _find_despesa_match(self, record: dict[str, Any], user_id: int) -> dict[str, Any] | None:
        despesa_id = self._to_int(record.get("despesa_id"))
        if despesa_id is not None:
            return {"id": despesa_id}

        fornecedor_id = self._to_int(record.get("fornecedor_id"))
        if fornecedor_id is None:
            fornecedor_id = await self._resolve_fornecedor_id(record, user_id)

        query = self._as_string(
            record.get("descricao")
            or record.get("despesa")
            or record.get("historico")
            or record.get("fornecedor")
            or record.get("fornecedor_nome")
        )
        valor = self._parse_decimal(record.get("valor") or record.get("valor_pago") or record.get("valor_baixa"))
        data_vencimento = self._normalize_date(record.get("data_vencimento") or record.get("vencimento"))

        rows = await self.laravel_client.search_despesas(
            user_id=user_id,
            query=query,
            fornecedor_id=fornecedor_id,
            status="pendente",
            limit=self.settings.max_result_rows,
        )

        candidates = list(rows)
        if data_vencimento:
            candidates = [row for row in candidates if self._normalize_date(row.get("data_vencimento")) == data_vencimento] or candidates

        if valor is not None:
            exact_by_value = [
                row for row in candidates
                if any(
                    self._parse_decimal(row.get(key)) is not None
                    and abs((self._parse_decimal(row.get(key)) or 0) - valor) <= 0.01
                    for key in ("valor_original", "valor")
                )
            ]
            candidates = exact_by_value or candidates

        return candidates[0] if len(candidates) == 1 else None

    async def _find_fatura_match(self, record: dict[str, Any], user_id: int) -> dict[str, Any] | None:
        fatura_id = self._to_int(record.get("fatura_id"))
        if fatura_id is not None:
            return {"id": fatura_id}

        cliente_id = self._to_int(record.get("cliente_id"))
        if cliente_id is None:
            cliente_id = await self._resolve_cliente_id(record, user_id)

        query = self._as_string(
            record.get("numero_fatura")
            or record.get("fatura")
            or record.get("descricao")
            or record.get("cliente")
            or record.get("cliente_nome")
        )
        periodo_referencia = self._normalize_periodo_referencia(
            record.get("periodo_referencia")
            or record.get("competencia")
            or record.get("periodo")
        )

        rows = await self.laravel_client.search_faturas(
            user_id=user_id,
            query=query,
            cliente_id=cliente_id,
            nfse_emitida=False,
            limit=self.settings.max_result_rows,
        )

        candidates = list(rows)
        if periodo_referencia:
            candidates = [
                row for row in candidates
                if self._normalize_periodo_referencia(row.get("periodo_referencia")) == periodo_referencia
            ] or candidates

        return candidates[0] if len(candidates) == 1 else None

    async def _execute_confirmed_record(
        self,
        *,
        action: str,
        record: dict[str, Any],
        user_id: int,
    ) -> dict[str, Any]:
        public_record = self._strip_internal_fields(record)
        if action == "sincronizar_clientes":
            return await self.laravel_client.upsert_cliente(user_id=user_id, payload=public_record)
        if action == "criar_cliente":
            if record.get("_resume_fatura_draft_action_id"):
                return await self.laravel_client.upsert_cliente(user_id=user_id, payload=public_record)
            return await self.laravel_client.create_cliente(user_id=user_id, payload=public_record)
        if action in {"inativar_cliente", "reativar_cliente"}:
            return await self.laravel_client.update_cliente_status(user_id=user_id, payload=public_record)
        if action == "baixar_titulo":
            return await self.laravel_client.baixar_titulo(user_id=user_id, payload=public_record)
        if action == "baixar_despesa":
            return await self.laravel_client.baixar_despesa(user_id=user_id, payload=public_record)
        if action == "renegociar_titulo":
            return await self.laravel_client.renegociar_titulo(user_id=user_id, payload=public_record)
        if action == "emitir_nfse":
            return await self.laravel_client.emitir_nfse(user_id=user_id, payload=public_record)
        if action == "criar_conta_receber":
            return await self.laravel_client.create_conta_receber(user_id=user_id, payload=public_record)
        if action == "criar_conta_pagar":
            return await self.laravel_client.create_conta_pagar(user_id=user_id, payload=public_record)
        if action == "gerar_fatura":
            return await self.laravel_client.create_fatura(user_id=user_id, payload=public_record)
        raise LaravelApiError(f"Acao nao suportada: {action}")

    async def _resume_fatura_after_cliente_creation(
        self,
        *,
        normalized_record: dict[str, Any],
        created_cliente: dict[str, Any],
        user_id: int,
    ) -> dict[str, Any] | None:
        draft_action_id = str(normalized_record.get("_resume_fatura_draft_action_id") or "").strip()
        if not draft_action_id:
            return None

        pending = self.pending_actions.get(draft_action_id)
        if pending is None or pending.user_id != user_id:
            return None

        if self._canonical_action(pending.action) != "gerar_fatura":
            return None

        cliente_id = (
            self._to_int(created_cliente.get("id"))
            or self._to_int(created_cliente.get("cliente_id"))
            or self._to_int((created_cliente.get("cliente") or {}).get("id"))
        )
        if cliente_id is None:
            return None

        created_faturas: list[dict[str, Any]] = []
        errors: list[str] = []

        for index, draft_record in enumerate(list(pending.records or []), start=1):
            try:
                updated_record = {
                    **(draft_record if isinstance(draft_record, dict) else {}),
                    "cliente_id": cliente_id,
                }
                normalized_fatura, missing = await self._normalize_record(
                    action="gerar_fatura",
                    record=updated_record,
                    user_id=user_id,
                )
                if missing:
                    raise ValueError("Faltam campos obrigatorios: " + ", ".join(missing))

                created_faturas.append(
                    await self._execute_confirmed_record(
                        action="gerar_fatura",
                        record=normalized_fatura,
                        user_id=user_id,
                    )
                )
            except Exception as exc:
                errors.append(f"Linha {index}: {exc}")

        self.pending_actions.pop(draft_action_id)

        return {
            "acao": "gerar_fatura",
            "registros": created_faturas,
            "erros_lista": errors[:5],
        }

    def _strip_internal_fields(self, record: dict[str, Any]) -> dict[str, Any]:
        return {
            key: value
            for key, value in record.items()
            if not str(key).startswith("_")
        }

    def _missing_cliente_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []
        if not self._clean_document(record.get("cnpj")):
            missing.append("cnpj")
        if not self._upper(record.get("razao_social")):
            missing.append("razao_social")
        return missing

    def _missing_cliente_sync_fields(self, record: dict[str, Any]) -> list[str]:
        return self._missing_cliente_fields(record)

    def _missing_receber_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []
        if not (
            self._to_int(record.get("cliente_id"))
            or self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente"))
            or self._upper(record.get("cliente") or record.get("cliente_nome"))
        ):
            missing.append("cliente")
        if not self._as_string(record.get("descricao") or record.get("historico") or record.get("nome")):
            missing.append("descricao")
        if self._parse_decimal(record.get("valor_original") or record.get("valor") or record.get("valor_total")) is None:
            missing.append("valor_original")
        if not self._normalize_date(record.get("data_vencimento") or record.get("vencimento")):
            missing.append("data_vencimento")
        return missing

    def _missing_cliente_status_fields(self, record: dict[str, Any]) -> list[str]:
        if (
            self._to_int(record.get("cliente_id"))
            or self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente") or record.get("cnpj"))
            or self._upper(record.get("cliente") or record.get("cliente_nome") or record.get("razao_social"))
        ):
            return []

        return ["cliente"]

    def _missing_baixa_titulo_fields(self, record: dict[str, Any]) -> list[str]:
        if (
            self._to_int(record.get("titulo_id"))
            or self._as_string(record.get("numero_titulo"))
            or self._as_string(record.get("titulo"))
            or self._as_string(record.get("descricao"))
            or self._upper(record.get("cliente") or record.get("cliente_nome"))
            or self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente"))
        ):
            return []

        return ["titulo"]

    def _missing_baixa_despesa_fields(self, record: dict[str, Any]) -> list[str]:
        if (
            self._to_int(record.get("despesa_id"))
            or self._as_string(record.get("descricao"))
            or self._as_string(record.get("despesa"))
            or self._upper(record.get("fornecedor") or record.get("fornecedor_nome"))
            or self._clean_document(record.get("fornecedor_cnpj") or record.get("cnpj_fornecedor"))
        ):
            return []

        return ["despesa"]

    def _missing_renegociacao_titulo_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []

        if not (
            self._to_int(record.get("titulo_id"))
            or self._as_string(record.get("numero_titulo"))
            or self._as_string(record.get("titulo"))
            or self._as_string(record.get("descricao"))
            or self._upper(record.get("cliente") or record.get("cliente_nome"))
            or self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente"))
        ):
            missing.append("titulo")

        if not self._normalize_date(
            record.get("nova_data_vencimento")
            or record.get("novo_vencimento")
            or record.get("data_vencimento")
            or record.get("vencimento")
        ):
            missing.append("nova_data_vencimento")

        return missing

    def _missing_emitir_nfse_fields(self, record: dict[str, Any]) -> list[str]:
        if (
            self._to_int(record.get("fatura_id"))
            or self._as_string(record.get("numero_fatura"))
            or self._as_string(record.get("fatura"))
            or self._upper(record.get("cliente") or record.get("cliente_nome"))
            or self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente"))
        ):
            return []

        return ["fatura"]

    def _missing_pagar_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []
        if not self._as_string(record.get("descricao") or record.get("historico") or record.get("nome")):
            missing.append("descricao")
        if self._parse_decimal(record.get("valor_original") or record.get("valor") or record.get("valor_total")) is None:
            missing.append("valor_original")
        if not self._normalize_date(record.get("data_vencimento") or record.get("vencimento")):
            missing.append("data_vencimento")
        return missing

    def _missing_fatura_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []
        if not self._to_int(record.get("cliente_id")):
            missing.append("cliente")
        if not self._normalize_periodo_referencia(record.get("periodo_referencia")):
            missing.append("periodo_referencia")
        if not self._normalize_date(record.get("data_vencimento")):
            missing.append("data_vencimento")

        items = self._extract_fatura_items(record)
        if not items:
            missing.append("itens")
            return missing

        for index, item in enumerate(items, start=1):
            if not self._as_string(
                item.get("descricao")
                or item.get("servico")
                or item.get("nome_servico")
                or item.get("historico")
                or item.get("nome")
            ):
                missing.append(f"item_{index}.descricao")

            valor_item = self._parse_decimal(item.get("valor_unitario"))
            if valor_item is None:
                valor_item = self._parse_decimal(item.get("valor_total"))
            if valor_item is None:
                valor_item = self._parse_decimal(item.get("valor"))

            if valor_item is None:
                missing.append(f"item_{index}.valor_unitario")

        return missing

    def _build_columns(self, records: list[dict[str, Any]]) -> list[ColumnDefinition]:
        first = records[0] if records else {}
        return [
            ColumnDefinition(
                key=key,
                label=key.replace("_", " ").title(),
            )
            for key in first.keys()
            if not str(key).startswith("_")
        ]

    def _canonical_action(self, action: str | None) -> str | None:
        if not action:
            return None

        mapping = {
            "criar_cliente": "criar_cliente",
            "cliente": "criar_cliente",
            "sincronizar_clientes": "sincronizar_clientes",
            "importar_clientes": "sincronizar_clientes",
            "inativar_cliente": "inativar_cliente",
            "reativar_cliente": "reativar_cliente",
            "baixar_titulo": "baixar_titulo",
            "baixar_despesa": "baixar_despesa",
            "renegociar_titulo": "renegociar_titulo",
            "emitir_nfse": "emitir_nfse",
            "nfse": "emitir_nfse",
            "criar_despesa": "criar_conta_pagar",
            "despesa": "criar_conta_pagar",
            "criar_conta_pagar": "criar_conta_pagar",
            "conta_pagar": "criar_conta_pagar",
            "criar_conta_receber": "criar_conta_receber",
            "criar_titulo_receber": "criar_conta_receber",
            "titulo_receber": "criar_conta_receber",
            "gerar_fatura": "gerar_fatura",
            "criar_fatura": "gerar_fatura",
            "fatura": "gerar_fatura",
        }

        return mapping.get(action)

    def _action_type(self, action: str) -> str:
        return {
            "criar_cliente": "cliente",
            "sincronizar_clientes": "cliente_sync",
            "inativar_cliente": "cliente_status",
            "reativar_cliente": "cliente_status",
            "baixar_titulo": "baixa_titulo",
            "baixar_despesa": "baixa_despesa",
            "renegociar_titulo": "renegociacao_titulo",
            "emitir_nfse": "nfse",
            "criar_conta_pagar": "despesa",
            "criar_conta_receber": "titulo_receber",
            "gerar_fatura": "fatura",
        }.get(action, "dados")

    def _default_preview_message(self, action: str, total: int) -> str:
        labels = {
            "criar_cliente": "cliente(s)",
            "sincronizar_clientes": "cliente(s) para sincronizacao",
            "inativar_cliente": "cliente(s) para inativacao",
            "reativar_cliente": "cliente(s) para reativacao",
            "baixar_titulo": "baixa(s) de titulo",
            "baixar_despesa": "baixa(s) de despesa",
            "renegociar_titulo": "renegociacao(oes) de titulo",
            "emitir_nfse": "emissao(oes) de NFS-e",
            "criar_conta_pagar": "conta(s) a pagar",
            "criar_conta_receber": "conta(s) a receber",
            "gerar_fatura": "fatura(s)",
        }
        return f"Preparei {total} {labels.get(action, 'registro(s)')} para confirmacao."

    def _build_preview_message(
        self,
        *,
        action: str,
        records: list[dict[str, Any]],
        fallback: str | None = None,
    ) -> str:
        base_message = (fallback or "").strip()
        summary = self._summarize_action_records(action, records)

        if base_message and summary:
            return f"{base_message} {summary}"
        if base_message:
            return base_message
        if summary:
            return summary
        return self._default_preview_message(action, len(records))

    def _build_pending_message(
        self,
        action: str,
        pending_fields: list[str],
        records: list[dict[str, Any]] | None = None,
    ) -> str:
        humanized = [self._humanize_pending_field(field) for field in pending_fields if field]
        humanized = [field for field in humanized if field]
        humanized = list(dict.fromkeys(humanized))

        if not humanized:
            return "Faltam alguns dados antes da confirmacao."

        requested = self._join_human_values(humanized[:3])
        intro = {
            "criar_cliente": "Consigo preparar o cadastro do cliente, mas ainda preciso de alguns dados.",
            "sincronizar_clientes": "Consigo preparar a sincronizacao da base de clientes, mas ainda preciso de alguns dados.",
            "criar_conta_pagar": "Consigo preparar a conta a pagar, mas ainda preciso de alguns dados.",
            "criar_conta_receber": "Consigo preparar a conta a receber, mas ainda preciso de alguns dados.",
            "gerar_fatura": "Consigo preparar a fatura, mas ainda preciso confirmar alguns dados.",
            "emitir_nfse": "Consigo preparar a emissao da NFS-e, mas ainda preciso confirmar alguns dados.",
        }.get(action, "Consigo continuar, mas ainda preciso de alguns dados.")

        message = f"{intro} Me informe {requested}."

        if "cliente" in pending_fields:
            candidate_labels = self._cliente_candidate_labels(records or [])
            if candidate_labels:
                message += f" Encontrei estes clientes no cadastro: {self._join_human_values(candidate_labels)}. Responda com o ID ou CNPJ correto."
            else:
                cliente_referencia = self._pending_cliente_reference(records or [])
                if cliente_referencia:
                    message += f" Recebi do arquivo: {cliente_referencia}. Se esse cliente ja existir, responda com o ID ou CNPJ cadastrado. Se nao existir, me diga para cadastrar primeiro."

        return message

    def _cliente_candidate_labels(self, records: list[dict[str, Any]]) -> list[str]:
        labels: list[str] = []

        for record in records:
            candidates = record.get("_cliente_candidates") or []
            if not isinstance(candidates, list):
                continue

            for candidate in candidates:
                if not isinstance(candidate, dict):
                    continue

                candidate_id = self._to_int(candidate.get("id"))
                nome = self._as_string(candidate.get("razao_social") or candidate.get("nome_fantasia"))
                cnpj = self._clean_document(candidate.get("cnpj"))

                parts = []
                if candidate_id is not None:
                    parts.append(f"ID {candidate_id}")
                if nome:
                    parts.append(nome)
                if cnpj:
                    parts.append(f"CNPJ {cnpj}")

                label = " - ".join(parts).strip()
                if label:
                    labels.append(label)

        return list(dict.fromkeys(labels))[:3]

    def _pending_cliente_reference(self, records: list[dict[str, Any]]) -> str | None:
        for record in records:
            nome = self._as_string(record.get("cliente") or record.get("cliente_nome"))
            cnpj = self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente") or record.get("cnpj"))

            parts = []
            if nome:
                parts.append(nome)
            if cnpj:
                parts.append(f"CNPJ {cnpj}")

            if parts:
                return " - ".join(parts)

        return None

    def _summarize_action_records(self, action: str, records: list[dict[str, Any]]) -> str | None:
        if not records:
            return None

        if action == "criar_cliente":
            labels = [
                record.get("razao_social") or record.get("nome_fantasia") or record.get("cnpj")
                for record in records
            ]
            labels = [str(label) for label in labels if label]
            preview = self._join_human_values(labels[:3])
            if preview:
                return f"Preparei {len(records)} cliente(s) para confirmacao: {preview}. Posso seguir?"

        if action == "sincronizar_clientes":
            total_create = sum(1 for record in records if record.get("sync_operation") == "criar")
            total_update = sum(1 for record in records if record.get("sync_operation") == "atualizar")
            total_noop = sum(1 for record in records if record.get("sync_operation") == "sem_alteracao")
            labels = [
                record.get("cliente_label") or record.get("razao_social") or record.get("cnpj")
                for record in records
            ]
            labels = [str(label) for label in labels if label]
            message = (
                f"Preparei a sincronizacao de {len(records)} cliente(s): "
                f"{total_create} novo(s), {total_update} atualizacao(oes) e {total_noop} sem alteracao."
            )
            if labels:
                message += f" Exemplos: {self._join_human_values(labels[:3])}."
            message += " Posso confirmar a sincronizacao?"
            return message

        if action in {"inativar_cliente", "reativar_cliente"}:
            labels = [
                record.get("cliente_label") or record.get("cliente_id")
                for record in records
            ]
            labels = [str(label) for label in labels if label not in (None, "")]
            verb = "inativacao" if action == "inativar_cliente" else "reativacao"
            preview = self._join_human_values(labels[:3])
            if preview:
                return f"Preparei {len(records)} cliente(s) para {verb}: {preview}. Posso confirmar?"
            return f"Preparei {len(records)} cliente(s) para {verb}. Posso confirmar?"

        if action in {"baixar_titulo", "baixar_despesa"}:
            total = sum(self._parse_decimal(record.get("valor")) or 0 for record in records)
            labels = [
                record.get("titulo_label") if action == "baixar_titulo" else record.get("despesa_label")
                for record in records
            ]
            labels = [str(label) for label in labels if label]
            target = "titulo(s)" if action == "baixar_titulo" else "despesa(s)"
            message = f"Preparei {len(records)} baixa(s) de {target} somando {self._format_currency(total)}."
            if labels:
                message += f" Referencias: {self._join_human_values(labels[:3])}."
            message += " Posso confirmar?"
            return message

        if action == "renegociar_titulo":
            labels = [record.get("titulo_label") for record in records if record.get("titulo_label")]
            vencimentos = [
                self._format_date(record.get("nova_data_vencimento"))
                for record in records
                if record.get("nova_data_vencimento")
            ]
            message = f"Preparei {len(records)} renegociacao(oes) de titulo."
            if labels:
                message += f" Referencias: {self._join_human_values(labels[:3])}."
            if vencimentos:
                message += f" Novos vencimentos: {self._join_human_values(vencimentos[:3])}."
            message += " Posso confirmar?"
            return message

        if action == "emitir_nfse":
            labels = [
                record.get("fatura_label") or record.get("fatura_id")
                for record in records
                if record.get("fatura_label") or record.get("fatura_id")
            ]
            periodos = [
                record.get("periodo_referencia")
                for record in records
                if record.get("periodo_referencia")
            ]
            message = f"Preparei {len(records)} emissao(oes) de NFS-e."
            if labels:
                message += f" Faturas: {self._join_human_values([str(label) for label in labels[:3]])}."
            if periodos:
                message += f" Competencias: {self._join_human_values(list(dict.fromkeys([str(periodo) for periodo in periodos]))[:3])}."
            message += " Posso confirmar?"
            return message

        if action in {"criar_conta_pagar", "criar_conta_receber"}:
            total = sum(self._parse_decimal(record.get("valor_original") or record.get("valor")) or 0 for record in records)
            due_dates = [
                self._format_date(record.get("data_vencimento"))
                for record in records
                if record.get("data_vencimento")
            ]
            label = "conta(s) a pagar" if action == "criar_conta_pagar" else "conta(s) a receber"
            message = f"Preparei {len(records)} {label} somando {self._format_currency(total)}."
            if due_dates:
                message += f" Vencimentos: {self._join_human_values(due_dates[:3])}."
            message += " Posso confirmar?"
            return message

        if action == "gerar_fatura":
            total = 0.0
            periodos: list[str] = []
            vencimentos: list[str] = []
            unidades: list[str] = []
            funcionarios: list[str] = []
            exames: list[str] = []

            for record in records:
                period = self._as_string(record.get("periodo_referencia"))
                if period:
                    periodos.append(period)

                due_date = self._format_date(record.get("data_vencimento"))
                if due_date:
                    vencimentos.append(due_date)

                metadata = record.get("metadata") if isinstance(record.get("metadata"), dict) else None
                if metadata:
                    unidade = self._as_string(metadata.get("unidade"))
                    if unidade:
                        unidades.append(unidade)

                    for funcionario in metadata.get("funcionarios") or []:
                        label = self._as_string((funcionario or {}).get("nome"))
                        if label:
                            funcionarios.append(label)

                    for exame in metadata.get("exames") or []:
                        label = self._as_string((exame or {}).get("nome"))
                        if label:
                            exames.append(label)

                for item in record.get("itens") or []:
                    quantidade = self._parse_decimal(item.get("quantidade")) or 1.0
                    valor_unitario = self._parse_decimal(item.get("valor_unitario")) or 0.0
                    total += quantidade * valor_unitario

            message = f"Preparei {len(records)} fatura(s) somando {self._format_currency(total)}."
            if unidades:
                message += f" Unidades: {self._join_human_values(list(dict.fromkeys(unidades))[:3])}."
            if periodos:
                message += f" Periodos: {self._join_human_values(list(dict.fromkeys(periodos))[:3])}."
            if vencimentos:
                message += f" Vencimentos: {self._join_human_values(list(dict.fromkeys(vencimentos))[:3])}."
            if funcionarios:
                message += f" Funcionários identificados: {self._join_human_values(list(dict.fromkeys(funcionarios))[:5])}."
            if exames:
                message += f" Exames identificados: {self._join_human_values(list(dict.fromkeys(exames))[:6])}."
            message += " Posso confirmar a criacao?"
            return message

        return None

    def _build_confirmation_message(
        self,
        *,
        action: str,
        created: list[dict[str, Any]],
        follow_up_actions: list[dict[str, Any]],
        errors: list[str],
    ) -> str:
        total_created = len(created)
        total_errors = len(errors)

        if total_created == 0:
            if total_errors == 1:
                return errors[0]
            if total_errors > 1:
                return f"Nenhum registro foi processado. Encontrei {total_errors} erro(s) durante a confirmacao."
            return "Nenhum registro foi processado."

        parts: list[str] = []

        primary_summary = self._summarize_completed_action_records(action, created)
        if primary_summary:
            parts.append(primary_summary)
        elif total_errors > 0:
            parts.append(
                f"Acao concluida parcialmente: {total_created} registro(s) processado(s) e {total_errors} erro(s)."
            )
        else:
            parts.append(f"Acao concluida com sucesso: {total_created} registro(s) processado(s).")

        follow_up_summary = self._summarize_follow_up_actions(follow_up_actions)
        if follow_up_summary:
            parts.append(follow_up_summary)

        if total_errors > 0:
            parts.append(f"Ainda encontrei {total_errors} erro(s) durante o processamento.")

        return " ".join(part.strip() for part in parts if part).strip()

    def _summarize_follow_up_actions(self, follow_up_actions: list[dict[str, Any]]) -> str | None:
        if not follow_up_actions:
            return None

        summaries: list[str] = []
        total_follow_up = 0

        for action_item in follow_up_actions:
            action = self._canonical_action(action_item.get("acao"))
            records = list(action_item.get("registros") or [])
            if action is None or not records:
                continue

            total_follow_up += len(records)
            summary = self._summarize_completed_action_records(action, records, chained=True)
            if summary:
                summaries.append(summary)

        if summaries:
            return " ".join(summaries)

        if total_follow_up > 0:
            return f"Tambem retomei automaticamente {total_follow_up} registro(s) vinculado(s)."

        return None

    def _summarize_completed_action_records(
        self,
        action: str,
        records: list[dict[str, Any]],
        *,
        chained: bool = False,
    ) -> str | None:
        if not records:
            return None

        if action in {"criar_cliente", "sincronizar_clientes"}:
            return self._summarize_completed_cliente_records(action, records, chained=chained)

        if action == "gerar_fatura":
            return self._summarize_completed_faturas(records, chained=chained)

        if action == "criar_conta_receber":
            return self._summarize_completed_titulos(
                records,
                singular="conta a receber",
                plural="contas a receber",
                chained=chained,
            )

        if action == "criar_conta_pagar":
            return self._summarize_completed_titulos(
                records,
                singular="conta a pagar",
                plural="contas a pagar",
                chained=chained,
            )

        if action in {"inativar_cliente", "reativar_cliente"}:
            labels = [self._cliente_result_label(record) for record in records]
            labels = [label for label in labels if label]
            verb = "inativado" if action == "inativar_cliente" else "reativado"

            if len(records) == 1 and labels:
                prefix = "Tambem o " if chained else "O "
                return f"{prefix}cliente {labels[0]} foi {verb} com sucesso."

            prefix = "Tambem " if chained else ""
            message = f"{prefix}{len(records)} cliente(s) {verb}s com sucesso."
            if labels:
                message += f" Exemplos: {self._join_human_values(labels[:3])}."
            return message

        if action in {"baixar_titulo", "baixar_despesa"}:
            total = sum(self._parse_decimal(record.get("valor")) or 0 for record in records)
            target = "titulo" if action == "baixar_titulo" else "despesa"
            prefix = "Tambem confirmei" if chained else "Confirmei"

            if len(records) == 1:
                return f"{prefix} a baixa de 1 {target} no valor de {self._format_currency(total)}."

            return f"{prefix} a baixa de {len(records)} {target}s somando {self._format_currency(total)}."

        if action == "renegociar_titulo":
            prefix = "Tambem renegociei" if chained else "Renegociei"
            due_dates = [
                self._format_date(record.get("data_vencimento") or record.get("nova_data_vencimento"))
                for record in records
            ]
            due_dates = [value for value in due_dates if value]

            if len(records) == 1 and due_dates:
                return f"{prefix} 1 titulo com novo vencimento em {due_dates[0]}."

            message = f"{prefix} {len(records)} titulo(s) com sucesso."
            if due_dates:
                message += f" Novos vencimentos: {self._join_human_values(due_dates[:3])}."
            return message

        if action == "emitir_nfse":
            prefix = "Tambem emiti" if chained else "Emiti"
            labels = [
                record.get("fatura_numero")
                or record.get("numero_fatura")
                or (record.get("fatura") or {}).get("numero_fatura")
                for record in records
            ]
            labels = [str(label) for label in labels if label]

            if len(records) == 1 and labels:
                return f"{prefix} a NFS-e da fatura {labels[0]} com sucesso."

            message = f"{prefix} {len(records)} NFS-e(s) com sucesso."
            if labels:
                message += f" Faturas: {self._join_human_values(labels[:3])}."
            return message

        return None

    def _summarize_completed_cliente_records(
        self,
        action: str,
        records: list[dict[str, Any]],
        *,
        chained: bool = False,
    ) -> str | None:
        labels = [self._cliente_result_label(record) for record in records]
        labels = [label for label in labels if label]

        sync_operations = [self._as_string(record.get("sync_operation")) for record in records]
        sync_operations = [operation for operation in sync_operations if operation]

        if action == "sincronizar_clientes" or sync_operations:
            total_create = sum(1 for operation in sync_operations if operation == "criar")
            total_update = sum(1 for operation in sync_operations if operation == "atualizar")
            total_noop = sum(1 for operation in sync_operations if operation == "sem_alteracao")

            if len(records) == 1:
                label = labels[0] if labels else "o cliente"
                operation = sync_operations[0] if sync_operations else "atualizar"

                if operation == "criar":
                    prefix = "Tambem o " if chained else "O "
                    return f"{prefix}cliente {label} foi cadastrado com sucesso."
                if operation == "sem_alteracao":
                    prefix = "Tambem o " if chained else "O "
                    return f"{prefix}cliente {label} ja estava sincronizado."

                prefix = "Tambem o " if chained else "O "
                return f"{prefix}cliente {label} foi sincronizado com sucesso."

            prefix = "Tambem sincronizei" if chained else "Sincronizei"
            message = (
                f"{prefix} {len(records)} cliente(s): "
                f"{total_create} novo(s), {total_update} atualizado(s) e {total_noop} sem alteracao."
            )
            if labels:
                message += f" Exemplos: {self._join_human_values(labels[:3])}."
            return message

        if len(records) == 1:
            label = labels[0] if labels else "o cliente"
            prefix = "Tambem o " if chained else "O "
            return f"{prefix}cliente {label} foi cadastrado com sucesso."

        prefix = "Tambem cadastrei" if chained else "Cadastrei"
        message = f"{prefix} {len(records)} cliente(s) com sucesso."
        if labels:
            message += f" Exemplos: {self._join_human_values(labels[:3])}."
        return message

    def _summarize_completed_faturas(
        self,
        records: list[dict[str, Any]],
        *,
        chained: bool = False,
    ) -> str | None:
        if not records:
            return None

        total = sum(self._parse_decimal(record.get("valor_total")) or 0 for record in records)

        if len(records) == 1:
            record = records[0]
            numero = self._as_string(record.get("numero_fatura")) or self._as_string(record.get("id"))
            cliente = self._cliente_result_label(record.get("cliente") or {}) or self._cliente_result_label(record)
            valor = self._parse_decimal(record.get("valor_total"))
            vencimento = self._format_date(record.get("data_vencimento"))
            parts = []

            if numero:
                parts.append(f"fatura {numero}")
            else:
                parts.append("fatura")

            if cliente:
                parts.append(f"para {cliente}")

            message = ("Tambem gerei automaticamente a " if chained else "Gerei a ") + "".join(
                [
                    parts[0],
                    *[f" {part}" for part in parts[1:]],
                ]
            )

            details: list[str] = []
            if valor is not None:
                details.append(f"no valor de {self._format_currency(valor)}")
            if vencimento:
                details.append(f"com vencimento em {vencimento}")

            if details:
                message += " " + " ".join(details)

            return message.strip() + "."

        prefix = "Tambem gerei automaticamente" if chained else "Gerei"
        message = f"{prefix} {len(records)} fatura(s)"
        if total > 0:
            message += f" somando {self._format_currency(total)}"
        message += "."
        return message

    def _summarize_completed_titulos(
        self,
        records: list[dict[str, Any]],
        *,
        singular: str,
        plural: str,
        chained: bool = False,
    ) -> str | None:
        if not records:
            return None

        total = sum(
            self._parse_decimal(
                record.get("valor_original")
                or record.get("valor")
                or record.get("valor_total")
            ) or 0
            for record in records
        )

        prefix = "Tambem criei" if chained else "Criei"
        if len(records) == 1:
            message = f"{prefix} 1 {singular}"
        else:
            message = f"{prefix} {len(records)} {plural}"

        if total > 0:
            message += f" somando {self._format_currency(total)}"

        return message + "."

    def _cliente_result_label(self, record: dict[str, Any]) -> str | None:
        if not isinstance(record, dict):
            return None

        cliente = record.get("cliente")
        if isinstance(cliente, dict):
            return (
                self._as_string(cliente.get("razao_social"))
                or self._as_string(cliente.get("nome_fantasia"))
                or self._clean_document(cliente.get("cnpj"))
            )

        return (
            self._as_string(record.get("cliente_label"))
            or self._as_string(record.get("razao_social"))
            or self._as_string(record.get("nome_fantasia"))
            or self._as_string(record.get("cliente"))
            or self._as_string(record.get("cliente_nome"))
            or self._clean_document(record.get("cnpj"))
            or self._clean_document(record.get("cliente_cnpj"))
        )

    def _humanize_pending_field(self, field: str) -> str:
        if field.startswith("item_") and "." in field:
            prefix, suffix = field.split(".", 1)
            item_number = prefix.replace("item_", "")
            field_label = {
                "descricao": f"descricao do item {item_number}",
                "valor_unitario": f"valor do item {item_number}",
            }.get(suffix, f"{suffix.replace('_', ' ')} do item {item_number}")
            return field_label

        mapping = {
            "cnpj": "o CNPJ",
            "razao_social": "a razao social",
            "cliente": "o cliente",
            "fatura": "a fatura",
            "titulo": "o titulo",
            "despesa": "a despesa",
            "descricao": "a descricao",
            "valor_original": "o valor",
            "data_vencimento": "a data de vencimento",
            "periodo_referencia": "o periodo de referencia",
            "itens": "os itens da fatura",
            "codigo_servico": "o codigo do servico",
        }

        return mapping.get(field, field.replace("_", " "))

    def _select_specialist_prompt(self, route: RouteDecision) -> str:
        if route.tipo_interacao == "consulta_documental" or route.dominio == "documental":
            return DOCUMENT_SPECIALIST_PROMPT

        if route.dominio in {"financeiro", "faturamento", "cadastros"}:
            return FINANCE_SPECIALIST_PROMPT

        return CHAT_SYSTEM_PROMPT

    def _merge_route_into_action_plan(self, route: RouteDecision, plan: ActionPlan) -> ActionPlan:
        if route.acao_sugerida != "nenhuma" and plan.acao_sugerida == "nenhuma":
            plan.acao_sugerida = route.acao_sugerida

        if not plan.mensagem and route.mensagem_roteamento:
            plan.mensagem = route.mensagem_roteamento

        if route.pendencias:
            plan.pendencias = list(dict.fromkeys([*plan.pendencias, *route.pendencias]))

        return plan

    def _merge_attachment_candidates_into_action_plan(
        self,
        plan: ActionPlan,
        parsed_file: ParsedFile,
    ) -> ActionPlan:
        file_action = self._canonical_action(parsed_file.action_hint)
        plan_action = self._canonical_action(plan.acao_sugerida)

        if plan_action is None and file_action is not None:
            plan.acao_sugerida = file_action
            plan_action = file_action

        if file_action is None or plan_action != file_action:
            return plan

        if not plan.dados_mapeados and parsed_file.structured_records:
            plan.dados_mapeados = list(parsed_file.structured_records)
            plan.mensagem = ""
            plan.pendencias = []

        return plan

    def _draft_context_payload(self, pending: Any | None) -> dict[str, Any] | None:
        if pending is None:
            return None

        return {
            "acao": pending.action,
            "pendencias": list((pending.metadata or {}).get("pending_fields") or []),
            "dados_parciais": list(pending.records or []),
        }

    def _merge_session_draft_into_action_plan(
        self,
        plan: ActionPlan,
        pending: Any | None,
        current_message_text: str,
    ) -> ActionPlan:
        if pending is None:
            return plan

        draft_action = self._canonical_action(pending.action)
        plan_action = self._canonical_action(plan.acao_sugerida)

        if plan_action is None and draft_action is not None:
            plan.acao_sugerida = draft_action
            plan_action = draft_action

        if draft_action is None or plan_action != draft_action:
            return plan

        draft_records = list(pending.records or [])
        current_records = list(plan.dados_mapeados or [])
        pending_fields = list((pending.metadata or {}).get("pending_fields") or [])
        extracted_updates = self._extract_pending_updates_from_message(
            action=plan_action,
            message=current_message_text,
            pending_fields=pending_fields,
            draft_records=draft_records,
        )

        if extracted_updates:
            if not current_records:
                current_records = extracted_updates
            else:
                current_records = self._merge_records(current_records, extracted_updates)

        if not current_records:
            plan.dados_mapeados = draft_records
            plan.pendencias = list(
                dict.fromkeys([
                    *pending_fields,
                    *list(plan.pendencias or []),
                ])
            )
            return plan

        plan.dados_mapeados = self._merge_records(draft_records, current_records)
        return plan

    def _redirect_fatura_draft_to_cliente_creation(
        self,
        *,
        payload: ChatPayload,
        plan: ActionPlan,
        session_draft: Any | None,
    ) -> ActionPlan:
        if session_draft is None:
            return plan

        draft_action = self._canonical_action(session_draft.action)
        if draft_action != "gerar_fatura":
            return plan

        pending_fields = list((session_draft.metadata or {}).get("pending_fields") or [])
        if "cliente" not in pending_fields:
            return plan

        if not self._message_requests_client_creation(payload.mensagem or ""):
            return plan

        cliente_records = self._build_cliente_records_from_fatura_draft(list(session_draft.records or []))
        if not cliente_records:
            return plan

        return ActionPlan(
            mensagem="Preparei o cadastro do cliente extraído da planilha antes de continuar a fatura.",
            acao_sugerida="criar_cliente",
            confianca=plan.confianca,
            dados_mapeados=[
                {
                    **record,
                    "_resume_fatura_draft_action_id": session_draft.action_id,
                }
                for record in cliente_records
            ],
            pendencias=[],
        )

    def _message_requests_client_creation(self, message: str) -> bool:
        normalized = (message or "").strip().lower()
        if not normalized:
            return False

        has_create_verb = any(token in normalized for token in ["cadastre", "cadastrar", "crie", "criar"])
        mentions_client = "cliente" in normalized
        return has_create_verb and mentions_client

    def _merge_records(
        self,
        draft_records: list[dict[str, Any]],
        current_records: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        merged_records: list[dict[str, Any]] = []
        max_len = max(len(draft_records), len(current_records))

        for index in range(max_len):
            draft_record = draft_records[index] if index < len(draft_records) else {}
            current_record = current_records[index] if index < len(current_records) else {}
            if not draft_record and len(draft_records) == 1 and current_record:
                draft_record = draft_records[0]
            if not current_record and len(current_records) == 1 and draft_record:
                current_record = current_records[0]

            merged_record = {
                **(draft_record if isinstance(draft_record, dict) else {}),
                **(current_record if isinstance(current_record, dict) else {}),
            }
            merged_records.append(merged_record)

        return merged_records

    def _extract_pending_updates_from_message(
        self,
        *,
        action: str,
        message: str,
        pending_fields: list[str],
        draft_records: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        text = (message or "").strip()
        if not text or not pending_fields:
            return []

        updates: dict[str, Any] = {}

        if "data_vencimento" in pending_fields:
            if due_date := self._extract_date_from_text(text):
                updates["data_vencimento"] = due_date

        if "nova_data_vencimento" in pending_fields:
            if due_date := self._extract_date_from_text(text):
                updates["nova_data_vencimento"] = due_date

        if "periodo_referencia" in pending_fields:
            if periodo := self._extract_period_from_text(text):
                updates["periodo_referencia"] = periodo

        if "cliente" in pending_fields:
            if cliente_id := self._extract_named_id(text, label="id"):
                updates["cliente_id"] = cliente_id
            if cliente_cnpj := self._extract_document_from_text(text, required_length=14):
                updates["cliente_cnpj"] = cliente_cnpj
            cliente_nome = self._extract_cliente_name_from_text(text)
            if cliente_nome and "cliente_id" not in updates and "cliente_cnpj" not in updates:
                updates["cliente"] = cliente_nome

        if "cnpj" in pending_fields:
            if cnpj := self._extract_document_from_text(text, required_length=14):
                updates["cnpj"] = cnpj

        if "razao_social" in pending_fields:
            if razao_social := self._extract_named_value(text, labels=["razao social", "cliente", "empresa"]):
                updates["razao_social"] = self._upper(razao_social)

        if "valor_original" in pending_fields:
            if valor := self._extract_currency_from_text(text):
                updates["valor_original"] = valor

        if "descricao" in pending_fields:
            if descricao := self._extract_named_value(text, labels=["descricao", "histórico", "historico"]):
                updates["descricao"] = descricao

        if not updates:
            return []

        target_size = max(len(draft_records), 1)
        return [dict(updates) for _ in range(target_size)]

    def _build_cliente_records_from_fatura_draft(self, records: list[dict[str, Any]]) -> list[dict[str, Any]]:
        normalized_records: list[dict[str, Any]] = []
        seen_keys: set[str] = set()

        for record in records:
            if not isinstance(record, dict):
                continue

            razao_social = self._upper(record.get("cliente") or record.get("cliente_nome"))
            cnpj = self._clean_document(record.get("cliente_cnpj") or record.get("cnpj_cliente") or record.get("cnpj"))
            if not razao_social and not cnpj:
                continue

            key = f"{cnpj or ''}|{razao_social or ''}"
            if key in seen_keys:
                continue
            seen_keys.add(key)

            cliente_record = {
                "cnpj": cnpj,
                "razao_social": razao_social,
                "nome_fantasia": razao_social,
            }
            cliente_record = {k: v for k, v in cliente_record.items() if v not in (None, "")}
            if cliente_record:
                normalized_records.append(cliente_record)

        return normalized_records

    def _is_plain_greeting(self, message: str) -> bool:
        normalized = self._normalize_free_text(message)
        if not normalized:
            return False

        simple_greetings = {
            "oi",
            "ola",
            "olá",
            "bom dia",
            "boa tarde",
            "boa noite",
            "e ai",
            "e aí",
            "tudo bem",
        }

        return normalized in simple_greetings

    def _preferred_greeting(self, message: str) -> str:
        normalized = self._normalize_free_text(message)
        if "boa tarde" in normalized:
            return "Boa tarde"
        if "boa noite" in normalized:
            return "Boa noite"
        return "Bom dia" if "bom dia" in normalized else "Olá"

    def _first_name(self, value: Any) -> str | None:
        full_name = self._as_string(value)
        if not full_name:
            return None

        return full_name.split()[0].title()

    def _normalize_free_text(self, value: Any) -> str:
        text = self._as_string(value) or ""
        text = text.strip().lower()
        text = re.sub(r"\s+", " ", text)
        return text

    def _extract_date_from_text(self, message: str) -> str | None:
        normalized = self._normalize_free_text(message)
        for pattern in (
            r"(\d{2}/\d{2}/\d{4})",
            r"(\d{2}-\d{2}-\d{4})",
            r"(\d{4}-\d{2}-\d{2})",
        ):
            match = re.search(pattern, normalized)
            if match:
                return self._normalize_date(match.group(1))

        return None

    def _extract_period_from_text(self, message: str) -> str | None:
        normalized = self._normalize_free_text(message)
        for pattern in (
            r"(\d{2}/\d{4})",
            r"(\d{2}-\d{4})",
            r"(\d{4}-\d{2})",
        ):
            match = re.search(pattern, normalized)
            if match:
                return self._normalize_periodo_referencia(match.group(1))

        return None

    def _extract_document_from_text(self, message: str, required_length: int | None = None) -> str | None:
        match = re.search(r"\d[\d\.\-\/]{10,20}\d", message or "")
        if not match:
            return None

        cleaned = self._clean_document(match.group(0))
        if not cleaned:
            return None

        if required_length is not None and len(cleaned) != required_length:
            return None

        return cleaned

    def _extract_named_id(self, message: str, *, label: str) -> int | None:
        pattern = rf"\b{re.escape(label)}\s*[:#-]?\s*(\d{{1,10}})\b"
        match = re.search(pattern, self._normalize_free_text(message))
        if not match:
            return None

        return self._to_int(match.group(1))

    def _extract_currency_from_text(self, message: str) -> float | None:
        match = re.search(r"(?:r\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2}|\d+(?:,\d{2})?)", message or "", re.IGNORECASE)
        if not match:
            return None

        raw = match.group(1).replace(".", "").replace(",", ".")
        try:
            return float(raw)
        except ValueError:
            return None

    def _extract_named_value(self, message: str, *, labels: list[str]) -> str | None:
        normalized = self._as_string(message)
        if not normalized:
            return None

        for label in labels:
            pattern = rf"{re.escape(label)}\s*[:\-]?\s*(.+)$"
            match = re.search(pattern, normalized, re.IGNORECASE)
            if match:
                value = self._as_string(match.group(1))
                if value:
                    return value

        return None

    def _extract_cliente_name_from_text(self, message: str) -> str | None:
        normalized = self._as_string(message)
        if not normalized:
            return None

        match = re.search(r"cliente\s*[:\-]?\s*(.+)$", normalized, re.IGNORECASE)
        if not match:
            return None

        candidate = self._as_string(match.group(1))
        if not candidate:
            return None

        if self._extract_date_from_text(candidate) or self._extract_document_from_text(candidate):
            return None

        return candidate

    def _join_human_values(self, values: list[str]) -> str:
        cleaned = [value.strip() for value in values if isinstance(value, str) and value.strip()]
        if not cleaned:
            return ""
        if len(cleaned) == 1:
            return cleaned[0]
        if len(cleaned) == 2:
            return f"{cleaned[0]} e {cleaned[1]}"
        return f"{', '.join(cleaned[:-1])} e {cleaned[-1]}"

    def _format_currency(self, value: float) -> str:
        formatted = f"{value:,.2f}"
        return "R$ " + formatted.replace(",", "X").replace(".", ",").replace("X", ".")

    def _format_decimal_for_text(self, value: float) -> str:
        if float(value).is_integer():
            return str(int(value))
        return str(value).replace(".", ",")

    def _format_date(self, value: Any) -> str | None:
        normalized = self._normalize_date(value)
        if not normalized:
            return None

        try:
            return datetime.strptime(normalized, "%Y-%m-%d").strftime("%d/%m/%Y")
        except ValueError:
            return normalized

    def _clean_document(self, value: Any) -> str | None:
        if value is None:
            return None
        digits = "".join(char for char in str(value) if char.isdigit())
        return digits or None

    def _upper(self, value: Any) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text.upper() if text else None

    def _lower(self, value: Any) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text.lower() if text else None

    def _title(self, value: Any) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text.title() if text else None

    def _as_string(self, value: Any) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text or None

    def _to_int(self, value: Any) -> int | None:
        if value in (None, ""):
            return None
        try:
            return int(value)
        except (TypeError, ValueError):
            return None

    def _parse_decimal(self, value: Any) -> float | None:
        if value in (None, ""):
            return None

        if isinstance(value, (int, float)):
            return float(value)

        text = str(value).strip()
        if text == "":
            return None

        text = text.replace("R$", "").replace(" ", "")
        if "," in text and "." in text:
            text = text.replace(".", "").replace(",", ".")
        elif "," in text:
            text = text.replace(",", ".")

        try:
            return float(Decimal(text))
        except (InvalidOperation, ValueError):
            return None

    def _normalize_date(self, value: Any) -> str | None:
        if value in (None, ""):
            return None

        if isinstance(value, datetime):
            return value.date().isoformat()

        text = str(value).strip()
        if text == "":
            return None

        for fmt in ("%Y-%m-%d", "%d/%m/%Y", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M:%S"):
            try:
                return datetime.strptime(text[:19], fmt).date().isoformat()
            except ValueError:
                continue

        return None

    def _normalize_competencia(self, value: Any) -> str | None:
        if value in (None, ""):
            return None

        text = str(value).strip()
        if text == "":
            return None

        if len(text) == 7 and text[2] == "/":
            try:
                return datetime.strptime(text, "%m/%Y").date().replace(day=1).isoformat()
            except ValueError:
                return None

        if len(text) == 7 and text[4] == "-":
            try:
                return datetime.strptime(text, "%Y-%m").date().replace(day=1).isoformat()
            except ValueError:
                return None

        normalized = self._normalize_date(text)
        if normalized is None:
            return None

        date_value = datetime.strptime(normalized, "%Y-%m-%d").date()
        return date_value.replace(day=1).isoformat()

    def _normalize_periodo_referencia(self, value: Any) -> str | None:
        if value in (None, ""):
            return None

        text = str(value).strip()
        if text == "":
            return None

        if len(text) == 7 and text[2] == "/":
            try:
                return datetime.strptime(text, "%m/%Y").strftime("%Y-%m")
            except ValueError:
                return None

        if len(text) == 7 and text[4] == "-":
            try:
                return datetime.strptime(text, "%Y-%m").strftime("%Y-%m")
            except ValueError:
                return None

        normalized = self._normalize_date(text)
        if normalized is None:
            return None

        return datetime.strptime(normalized, "%Y-%m-%d").strftime("%Y-%m")

    def _today(self) -> str:
        return datetime.utcnow().date().isoformat()

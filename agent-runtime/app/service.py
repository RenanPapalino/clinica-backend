from __future__ import annotations

from datetime import datetime
from decimal import Decimal, InvalidOperation
from typing import Any

from langchain.agents import create_agent
from langchain.agents.structured_output import ProviderStrategy
from langchain_openai import ChatOpenAI

from .file_parser import ParsedFile, parse_attachment
from .laravel_client import LaravelApiError, LaravelInternalClient
from .memory import PendingActionStore
from .planner import ActionPlan, ActionPlanner
from .prompts import CHAT_SYSTEM_PROMPT, build_current_message
from .schemas import ChatPayload, ChatbotResponse, ColumnDefinition, ResumePayload, StructuredData
from .settings import Settings
from .tools import build_read_tools


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
        self.action_planner = ActionPlanner(self.chat_model)

    async def process_chat(self, payload: ChatPayload) -> ChatbotResponse:
        if not self.settings.openai_api_key:
            return ChatbotResponse(
                mensagem="OPENAI_API_KEY nao configurada no runtime do chatbot.",
                acao_sugerida=None,
                dados_estruturados=None,
            )

        parsed_file = parse_attachment(payload.arquivo)
        session_context = await self._load_session_context(payload)
        current_message = build_current_message(payload, parsed_file)

        action_plan = await self.action_planner.plan(
            payload=payload,
            session_context=session_context,
            current_message=current_message,
        )

        preview = await self._build_action_preview(
            payload=payload,
            plan=action_plan,
        )
        if preview is not None:
            return preview

        if payload.arquivo is not None and not parsed_file.supported and not (payload.mensagem or "").strip():
            return ChatbotResponse(
                mensagem=parsed_file.message
                or "Recebi o anexo, mas a leitura documental completa deve seguir pelo pipeline de ingestao/RAG.",
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
            system_prompt=CHAT_SYSTEM_PROMPT,
            response_format=ProviderStrategy(ChatbotResponse),
        )

        result = await agent.ainvoke(
            {"messages": self._build_messages(session_context, payload, current_message)}
        )

        return self._normalize_agent_response(result)

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
            except Exception as exc:
                errors.append(f"Linha {index}: {exc}")

        if action_id:
            self.pending_actions.pop(action_id)

        total_created = len(created)
        total_errors = len(errors)
        success = total_created > 0 and total_errors == 0

        if total_created > 0 and total_errors > 0:
            message = f"Acao concluida parcialmente: {total_created} registro(s) criado(s) e {total_errors} erro(s)."
        elif total_created > 0:
            message = f"Acao concluida com sucesso: {total_created} registro(s) criado(s)."
        else:
            message = "Nenhum registro foi criado."

        return {
            "success": success or total_created > 0,
            "message": message,
            "detalhes": {
                "resumo": {
                    "criados": total_created,
                    "erros": total_errors,
                },
                "registros": created,
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
        if not records:
            message = plan.mensagem or "Nao encontrei dados suficientes para preparar a acao."
            if plan.pendencias:
                message += " Faltam: " + ", ".join(plan.pendencias) + "."
            return ChatbotResponse(mensagem=message)

        normalized_records: list[dict[str, Any]] = []
        pending_fields: list[str] = list(plan.pendencias)

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
            message = plan.mensagem or "Faltam alguns dados antes da confirmacao."
            message += " Faltam: " + ", ".join(pending_fields) + "."
            return ChatbotResponse(mensagem=message)

        pending = self.pending_actions.save(
            action=action,
            records=normalized_records,
            user_id=payload.user_id,
            session_id=payload.session_id,
            metadata={"fonte": "langchain-runtime"},
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

        message = plan.mensagem or self._default_preview_message(action, len(normalized_records))
        return ChatbotResponse(
            mensagem=message,
            acao_sugerida=action,
            dados_estruturados=structured,
        )

    async def _normalize_record(
        self,
        *,
        action: str,
        record: dict[str, Any],
        user_id: int,
    ) -> tuple[dict[str, Any], list[str]]:
        if action == "criar_cliente":
            return self._normalize_cliente_record(record), self._missing_cliente_fields(record)
        if action == "criar_conta_receber":
            return await self._normalize_conta_receber_record(record, user_id), self._missing_receber_fields(record)
        if action == "criar_conta_pagar":
            return await self._normalize_conta_pagar_record(record, user_id), self._missing_pagar_fields(record)
        return record, []

    def _normalize_cliente_record(self, record: dict[str, Any]) -> dict[str, Any]:
        normalized = {
            "cnpj": self._clean_document(record.get("cnpj")),
            "razao_social": self._upper(record.get("razao_social")),
            "nome_fantasia": self._upper(record.get("nome_fantasia")),
            "email": self._lower(record.get("email")),
            "telefone": self._as_string(record.get("telefone")),
            "celular": self._as_string(record.get("celular")),
            "cidade": self._title(record.get("cidade")),
            "uf": self._upper(record.get("uf")),
            "status": self._as_string(record.get("status")) or "ativo",
        }

        return {key: value for key, value in normalized.items() if value not in (None, "")}

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

    async def _resolve_cliente_id(self, record: dict[str, Any], user_id: int) -> int | None:
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
            return None

        rows = await self.laravel_client.search_clientes(
            user_id=user_id,
            query=query,
            limit=self.settings.max_result_rows,
        )

        for item in rows:
            if document and self._clean_document(item.get("cnpj")) == document:
                return self._to_int(item.get("id"))
            if name and self._upper(item.get("razao_social")) == name:
                return self._to_int(item.get("id"))
            if name and self._upper(item.get("nome_fantasia")) == name:
                return self._to_int(item.get("id"))

        return self._to_int(rows[0].get("id")) if rows else None

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

    async def _execute_confirmed_record(
        self,
        *,
        action: str,
        record: dict[str, Any],
        user_id: int,
    ) -> dict[str, Any]:
        if action == "criar_cliente":
            return await self.laravel_client.create_cliente(user_id=user_id, payload=record)
        if action == "criar_conta_receber":
            return await self.laravel_client.create_conta_receber(user_id=user_id, payload=record)
        if action == "criar_conta_pagar":
            return await self.laravel_client.create_conta_pagar(user_id=user_id, payload=record)
        raise LaravelApiError(f"Acao nao suportada: {action}")

    def _missing_cliente_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []
        if not self._clean_document(record.get("cnpj")):
            missing.append("cnpj")
        if not self._upper(record.get("razao_social")):
            missing.append("razao_social")
        return missing

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

    def _missing_pagar_fields(self, record: dict[str, Any]) -> list[str]:
        missing: list[str] = []
        if not self._as_string(record.get("descricao") or record.get("historico") or record.get("nome")):
            missing.append("descricao")
        if self._parse_decimal(record.get("valor_original") or record.get("valor") or record.get("valor_total")) is None:
            missing.append("valor_original")
        if not self._normalize_date(record.get("data_vencimento") or record.get("vencimento")):
            missing.append("data_vencimento")
        return missing

    def _build_columns(self, records: list[dict[str, Any]]) -> list[ColumnDefinition]:
        first = records[0] if records else {}
        return [
            ColumnDefinition(
                key=key,
                label=key.replace("_", " ").title(),
            )
            for key in first.keys()
        ]

    def _canonical_action(self, action: str | None) -> str | None:
        if not action:
            return None

        mapping = {
            "criar_cliente": "criar_cliente",
            "cliente": "criar_cliente",
            "criar_despesa": "criar_conta_pagar",
            "despesa": "criar_conta_pagar",
            "criar_conta_pagar": "criar_conta_pagar",
            "conta_pagar": "criar_conta_pagar",
            "criar_conta_receber": "criar_conta_receber",
            "criar_titulo_receber": "criar_conta_receber",
            "titulo_receber": "criar_conta_receber",
        }

        return mapping.get(action)

    def _action_type(self, action: str) -> str:
        return {
            "criar_cliente": "cliente",
            "criar_conta_pagar": "despesa",
            "criar_conta_receber": "titulo_receber",
        }.get(action, "dados")

    def _default_preview_message(self, action: str, total: int) -> str:
        labels = {
            "criar_cliente": "cliente(s)",
            "criar_conta_pagar": "conta(s) a pagar",
            "criar_conta_receber": "conta(s) a receber",
        }
        return f"Preparei {total} {labels.get(action, 'registro(s)')} para confirmacao."

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

    def _today(self) -> str:
        return datetime.utcnow().date().isoformat()

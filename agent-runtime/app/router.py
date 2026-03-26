from __future__ import annotations

from typing import Literal

from langchain_openai import ChatOpenAI
from pydantic import BaseModel, Field

from .prompts import ROUTER_PROMPT, build_history_text


class RouteDecision(BaseModel):
    tipo_interacao: Literal[
        "consulta_operacional",
        "consulta_documental",
        "acao_operacional",
        "ambigua",
    ] = "consulta_operacional"
    dominio: Literal["financeiro", "faturamento", "cadastros", "documental", "geral"] = "geral"
    acao_sugerida: Literal[
        "nenhuma",
        "criar_cliente",
        "sincronizar_clientes",
        "criar_conta_pagar",
        "criar_conta_receber",
        "gerar_fatura",
        "gerar_boleto",
        "excluir_boleto",
        "excluir_fatura",
        "inativar_cliente",
        "reativar_cliente",
        "baixar_titulo",
        "baixar_despesa",
        "renegociar_titulo",
        "emitir_nfse",
    ] = "nenhuma"
    precisa_confirmacao: bool = False
    mensagem_roteamento: str = ""
    pendencias: list[str] = Field(default_factory=list)


class ConversationRouter:
    def __init__(self, model: ChatOpenAI) -> None:
        self._model = model

    async def route(self, *, session_context: dict, current_message: str) -> RouteDecision:
        structured_model = self._model.with_structured_output(RouteDecision, method="function_calling")

        history = build_history_text(session_context)
        content = "\n\n".join(
            part
            for part in [
                "Historico recente da sessao:",
                history or "Sem historico relevante.",
                "Entrada atual:",
                current_message,
            ]
            if part
        )

        result = await structured_model.ainvoke(
            [
                {"role": "system", "content": ROUTER_PROMPT},
                {"role": "user", "content": content},
            ]
        )

        if isinstance(result, RouteDecision):
            return result

        return RouteDecision.model_validate(result)

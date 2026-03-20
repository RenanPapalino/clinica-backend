from __future__ import annotations

from typing import Any, Literal

from langchain_openai import ChatOpenAI
from pydantic import BaseModel, Field

from .prompts import ACTION_PLANNER_PROMPT, build_current_message, build_history_text
from .schemas import ChatPayload


class ActionPlan(BaseModel):
    mensagem: str = "Sem acao de criacao detectada."
    acao_sugerida: Literal[
        "nenhuma",
        "criar_cliente",
        "sincronizar_clientes",
        "criar_conta_pagar",
        "criar_conta_receber",
        "gerar_fatura",
        "inativar_cliente",
        "reativar_cliente",
        "baixar_titulo",
        "baixar_despesa",
        "renegociar_titulo",
        "emitir_nfse",
    ] = "nenhuma"
    confianca: float | None = None
    dados_mapeados: list[dict[str, Any]] = Field(default_factory=list)
    pendencias: list[str] = Field(default_factory=list)


class ActionPlanner:
    def __init__(self, model: ChatOpenAI) -> None:
        self._model = model

    async def plan(self, *, payload: ChatPayload, session_context: dict, current_message: str) -> ActionPlan:
        structured_model = self._model.with_structured_output(ActionPlan, method="function_calling")

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
                {"role": "system", "content": ACTION_PLANNER_PROMPT},
                {"role": "user", "content": content},
            ]
        )

        if isinstance(result, ActionPlan):
            return result

        return ActionPlan.model_validate(result)

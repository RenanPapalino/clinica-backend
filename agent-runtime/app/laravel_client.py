from __future__ import annotations

import json
from typing import Any

import httpx

from .settings import Settings


class LaravelApiError(RuntimeError):
    pass


class LaravelInternalClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def session_context(self, *, user_id: int, session_id: str, limit: int) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/session-context",
            method="POST",
            user_id=user_id,
            body={"session_id": session_id, "limit": limit},
        )

    async def search_knowledge(
        self,
        *,
        user_id: int,
        query: str,
        business_context: str | None = None,
        context_key: str | None = None,
        limit: int = 5,
    ) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/knowledge/search",
            method="POST",
            user_id=user_id,
            body={
                "query": query,
                "business_context": business_context,
                "context_key": context_key,
                "limit": limit,
            },
        )

    async def consultar_cnpj(self, *, user_id: int, cnpj: str) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/cnpj/consultar",
            method="POST",
            user_id=user_id,
            body={"cnpj": cnpj},
        )

    async def financial_summary(self, *, user_id: int) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/financial-summary",
            method="GET",
            user_id=user_id,
        )

    async def faturamento_summary(
        self,
        *,
        user_id: int,
        periodo_inicio: str,
        periodo_fim: str,
        cliente_id: int | None = None,
        status: str | None = None,
        nfse_emitida: bool | None = None,
    ) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/faturamento/summary",
            method="POST",
            user_id=user_id,
            body={
                "periodo_inicio": periodo_inicio,
                "periodo_fim": periodo_fim,
                "cliente_id": cliente_id,
                "status": status,
                "nfse_emitida": nfse_emitida,
            },
        )

    async def previsao_caixa(
        self,
        *,
        user_id: int,
        periodo_inicio: str,
        periodo_fim: str,
    ) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/caixa/previsao",
            method="POST",
            user_id=user_id,
            body={
                "periodo_inicio": periodo_inicio,
                "periodo_fim": periodo_fim,
            },
        )

    async def search_clientes(self, *, user_id: int, query: str | None = None, limit: int = 10) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/clientes/search",
            method="POST",
            user_id=user_id,
            body={"query": query, "limit": limit},
        )

    async def search_fornecedores(
        self,
        *,
        user_id: int,
        query: str | None = None,
        limit: int = 10,
    ) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/fornecedores/search",
            method="POST",
            user_id=user_id,
            body={"query": query, "limit": limit},
        )

    async def search_titulos(
        self,
        *,
        user_id: int,
        query: str | None = None,
        cliente_id: int | None = None,
        tipo: str | None = None,
        status: str | None = None,
        limit: int = 10,
    ) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/titulos/search",
            method="POST",
            user_id=user_id,
            body={
                "query": query,
                "cliente_id": cliente_id,
                "tipo": tipo,
                "status": status,
                "limit": limit,
            },
        )

    async def search_faturas(
        self,
        *,
        user_id: int,
        query: str | None = None,
        cliente_id: int | None = None,
        status: str | None = None,
        periodo_inicio: str | None = None,
        periodo_fim: str | None = None,
        nfse_emitida: bool | None = None,
        limit: int = 10,
    ) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/faturas/search",
            method="POST",
            user_id=user_id,
            body={
                "query": query,
                "cliente_id": cliente_id,
                "status": status,
                "periodo_inicio": periodo_inicio,
                "periodo_fim": periodo_fim,
                "nfse_emitida": nfse_emitida,
                "limit": limit,
            },
        )

    async def search_nfse(
        self,
        *,
        user_id: int,
        query: str | None = None,
        cliente_id: int | None = None,
        fatura_id: int | None = None,
        status: str | None = None,
        periodo_inicio: str | None = None,
        periodo_fim: str | None = None,
        limit: int = 10,
    ) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/nfse/search",
            method="POST",
            user_id=user_id,
            body={
                "query": query,
                "cliente_id": cliente_id,
                "fatura_id": fatura_id,
                "status": status,
                "periodo_inicio": periodo_inicio,
                "periodo_fim": periodo_fim,
                "limit": limit,
            },
        )

    async def search_despesas(
        self,
        *,
        user_id: int,
        query: str | None = None,
        fornecedor_id: int | None = None,
        status: str | None = None,
        limit: int = 10,
    ) -> list[dict[str, Any]]:
        return await self._request(
            path="/api/internal/agent/despesas/search",
            method="POST",
            user_id=user_id,
            body={
                "query": query,
                "fornecedor_id": fornecedor_id,
                "status": status,
                "limit": limit,
            },
        )

    async def create_cliente(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/clientes",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def upsert_cliente(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/clientes/upsert",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def update_cliente_status(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/clientes/status",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def create_conta_receber(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/contas-receber",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def create_conta_pagar(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/contas-pagar",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def create_fatura(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/faturas",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def emitir_nfse(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/nfse/emitir",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def baixar_titulo(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/titulos/baixar",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def renegociar_titulo(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/titulos/renegociar",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def baixar_despesa(self, *, user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/despesas/baixar",
            method="POST",
            user_id=user_id,
            body=payload,
        )

    async def fechamento_diario(
        self,
        *,
        user_id: int,
        data: str | None = None,
    ) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/fechamento/diario",
            method="POST",
            user_id=user_id,
            body={"data": data},
        )

    async def _request(
        self,
        *,
        path: str,
        method: str,
        user_id: int,
        body: dict[str, Any] | None = None,
    ) -> Any:
        if not self.settings.laravel_agent_secret:
            raise LaravelApiError("LARAVEL_AGENT_SECRET nao configurado.")

        url = self.settings.laravel_base_url.rstrip("/") + path
        headers = {
            "Accept": "application/json",
            "X-Agent-Secret": self.settings.laravel_agent_secret,
            "X-Agent-User-Id": str(user_id),
        }

        async with httpx.AsyncClient(timeout=self.settings.request_timeout_seconds) as client:
            response = await client.request(
                method=method,
                url=url,
                json=body,
                headers=headers,
            )

        payload = self._decode_payload(response)

        if response.status_code >= 400:
            raise LaravelApiError(
                payload.get("message")
                or f"Erro HTTP {response.status_code} ao chamar {path}."
            )

        if isinstance(payload, dict) and payload.get("success") is False:
            raise LaravelApiError(payload.get("message") or f"Falha logica em {path}.")

        if isinstance(payload, dict) and "data" in payload:
            return payload["data"]

        return payload

    def _decode_payload(self, response: httpx.Response) -> dict[str, Any] | list[Any]:
        try:
            return response.json()
        except ValueError:
            text = response.text or ""
            start_positions = [index for index in (text.find("{"), text.find("[")) if index >= 0]
            if start_positions:
                candidate = text[min(start_positions):].strip()
                try:
                    parsed = json.loads(candidate)
                    if isinstance(parsed, (dict, list)):
                        return parsed
                except ValueError:
                    pass

            return {"message": text}

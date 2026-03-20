from __future__ import annotations

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

    async def financial_summary(self, *, user_id: int) -> dict[str, Any]:
        return await self._request(
            path="/api/internal/agent/financial-summary",
            method="GET",
            user_id=user_id,
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
                "cliente_id": cliente_id,
                "tipo": tipo,
                "status": status,
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

        try:
            payload = response.json()
        except ValueError:
            payload = {"message": response.text}

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

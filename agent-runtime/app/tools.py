from __future__ import annotations

import json

from langchain.tools import tool

from .laravel_client import LaravelInternalClient


def _dump_json(value) -> str:
    return json.dumps(value, ensure_ascii=False, indent=2, default=str)


def build_read_tools(
    *,
    client: LaravelInternalClient,
    user_id: int,
    max_rows: int,
):
    max_rows = max(1, max_rows)

    @tool
    async def buscar_clientes(query: str, limit: int = 5) -> str:
        """Busca clientes por nome, fantasia ou CNPJ."""
        data = await client.search_clientes(
            user_id=user_id,
            query=query,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    @tool
    async def buscar_fornecedores(query: str, limit: int = 5) -> str:
        """Busca fornecedores por nome, fantasia, CNPJ ou CPF."""
        data = await client.search_fornecedores(
            user_id=user_id,
            query=query,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    @tool
    async def buscar_titulos(
        cliente_id: int | None = None,
        tipo: str | None = None,
        status: str | None = None,
        limit: int = 10,
    ) -> str:
        """Busca titulos financeiros do sistema, filtrando por cliente, tipo ou status."""
        data = await client.search_titulos(
            user_id=user_id,
            cliente_id=cliente_id,
            tipo=tipo,
            status=status,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    @tool
    async def buscar_despesas(
        query: str | None = None,
        fornecedor_id: int | None = None,
        status: str | None = None,
        limit: int = 10,
    ) -> str:
        """Busca despesas ou contas a pagar por descricao, fornecedor ou status."""
        data = await client.search_despesas(
            user_id=user_id,
            query=query,
            fornecedor_id=fornecedor_id,
            status=status,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    @tool
    async def resumo_financeiro() -> str:
        """Retorna um resumo financeiro do sistema com contas a pagar, contas a receber e faturamento."""
        data = await client.financial_summary(user_id=user_id)
        return _dump_json(data)

    @tool
    async def buscar_conhecimento_documental(
        query: str,
        business_context: str | None = None,
        context_key: str | None = None,
        limit: int = 5,
    ) -> str:
        """Busca trechos de documentos indexados no sistema, como PDFs, manuais, FAQs e planilhas processadas."""
        data = await client.search_knowledge(
            user_id=user_id,
            query=query,
            business_context=business_context,
            context_key=context_key,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    return [
        buscar_clientes,
        buscar_fornecedores,
        buscar_titulos,
        buscar_despesas,
        resumo_financeiro,
        buscar_conhecimento_documental,
    ]

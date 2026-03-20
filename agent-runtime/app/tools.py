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
        query: str | None = None,
        cliente_id: int | None = None,
        tipo: str | None = None,
        status: str | None = None,
        limit: int = 10,
    ) -> str:
        """Busca titulos financeiros do sistema, filtrando por cliente, tipo ou status."""
        data = await client.search_titulos(
            user_id=user_id,
            query=query,
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
    async def buscar_faturas(
        query: str | None = None,
        cliente_id: int | None = None,
        status: str | None = None,
        periodo_inicio: str | None = None,
        periodo_fim: str | None = None,
        nfse_emitida: bool | None = None,
        limit: int = 10,
    ) -> str:
        """Busca faturas por cliente, status, periodo de emissao e emissao de NFS-e."""
        data = await client.search_faturas(
            user_id=user_id,
            query=query,
            cliente_id=cliente_id,
            status=status,
            periodo_inicio=periodo_inicio,
            periodo_fim=periodo_fim,
            nfse_emitida=nfse_emitida,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    @tool
    async def buscar_nfse(
        query: str | None = None,
        cliente_id: int | None = None,
        fatura_id: int | None = None,
        status: str | None = None,
        periodo_inicio: str | None = None,
        periodo_fim: str | None = None,
        limit: int = 10,
    ) -> str:
        """Busca NFS-e por numero, protocolo, cliente, fatura, status ou periodo."""
        data = await client.search_nfse(
            user_id=user_id,
            query=query,
            cliente_id=cliente_id,
            fatura_id=fatura_id,
            status=status,
            periodo_inicio=periodo_inicio,
            periodo_fim=periodo_fim,
            limit=min(limit, max_rows),
        )
        return _dump_json(data)

    @tool
    async def resumo_financeiro() -> str:
        """Retorna um resumo financeiro do sistema com contas a pagar, contas a receber e faturamento."""
        data = await client.financial_summary(user_id=user_id)
        return _dump_json(data)

    @tool
    async def resumo_faturamento_periodo(
        periodo_inicio: str,
        periodo_fim: str,
        cliente_id: int | None = None,
        status: str | None = None,
        nfse_emitida: bool | None = None,
    ) -> str:
        """Retorna total de faturamento, quantidade de faturas e ticket medio em um periodo informado."""
        data = await client.faturamento_summary(
            user_id=user_id,
            periodo_inicio=periodo_inicio,
            periodo_fim=periodo_fim,
            cliente_id=cliente_id,
            status=status,
            nfse_emitida=nfse_emitida,
        )
        return _dump_json(data)

    @tool
    async def previsao_caixa_periodo(
        periodo_inicio: str,
        periodo_fim: str,
    ) -> str:
        """Retorna entradas previstas, saídas previstas e saldo projetado para um período futuro."""
        data = await client.previsao_caixa(
            user_id=user_id,
            periodo_inicio=periodo_inicio,
            periodo_fim=periodo_fim,
        )
        return _dump_json(data)

    @tool
    async def resumo_fechamento_diario(data: str | None = None) -> str:
        """Retorna o resumo operacional do fechamento diario, incluindo previstos, realizados, vencidos, faturas e NFS-e pendentes."""
        result = await client.fechamento_diario(
            user_id=user_id,
            data=data,
        )
        return _dump_json(result)

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
        buscar_faturas,
        buscar_nfse,
        buscar_despesas,
        resumo_financeiro,
        resumo_faturamento_periodo,
        previsao_caixa_periodo,
        resumo_fechamento_diario,
        buscar_conhecimento_documental,
    ]

from __future__ import annotations

from .file_parser import ParsedFile
from .schemas import ChatPayload


CHAT_SYSTEM_PROMPT = """
Voce e o copiloto financeiro do MedIntelligence, um sistema de gestao financeira para clinicas SST.

Regras obrigatorias:
- Responda sempre em portugues do Brasil.
- Priorize dados operacionais vivos via tools do Laravel.
- Use o tool de conhecimento documental quando a pergunta envolver documentos, politicas, manuais, PDFs, planilhas, anexos indexados ou regras operacionais.
- Nunca invente IDs, CNPJs, valores, datas, status ou clientes.
- Nao execute acoes destrutivas nem financeiras sem confirmacao humana.
- Quando responder com base em documentos, cite o nome do arquivo se ele vier no retorno do tool.
- Seja objetivo, tecnico e orientado ao uso do sistema.
"""


ACTION_PLANNER_PROMPT = """
Voce classifica se a conversa atual pede uma acao de criacao para o sistema MedIntelligence.

As unicas acoes validas sao:
- nenhuma
- criar_cliente
- criar_conta_pagar
- criar_conta_receber

Regras:
- Se for apenas pergunta, consulta, analise ou explicacao, retorne acao_sugerida="nenhuma".
- Se faltar informacao obrigatoria, nao invente. Preencha pendencias.
- Extraia apenas o que estiver explicitamente dito pelo usuario ou no contexto recente.
- Nunca invente IDs.
- Para conta a pagar, aceite fornecedor_id, fornecedor, nome_fornecedor, fornecedor_cnpj.
- Para conta a receber, aceite cliente_id, cliente, cliente_cnpj.
- Para cliente, aceite cnpj, razao_social e campos opcionais conhecidos.
- Retorne dados_mapeados como lista de registros.
"""


def build_history_text(session_context: dict, max_messages: int = 8) -> str:
    messages = list(session_context.get("messages") or [])[-max_messages:]
    lines: list[str] = []

    for message in messages:
        role = "assistente" if message.get("role") == "assistant" else "usuario"
        content = str(message.get("content") or "").strip()
        if content:
            lines.append(f"{role}: {content}")

    return "\n".join(lines)


def build_current_message(payload: ChatPayload, parsed_file: ParsedFile) -> str:
    parts: list[str] = []

    if payload.user_name or payload.user_email:
        parts.append(
            f"Usuario autenticado: {payload.user_name or 'sem nome'} <{payload.user_email or 'sem email'}>"
        )

    if payload.tipo_processamento:
        parts.append(f"Tipo de processamento: {payload.tipo_processamento}")

    parts.append(f"Mensagem atual: {payload.mensagem or ''}")

    if payload.arquivo is not None:
        parts.append(
            f"Arquivo anexado: {payload.arquivo.nome} ({payload.arquivo.mime_type or 'mime_desconhecido'})."
        )

    if parsed_file.text:
        parts.append("Trecho extraido do arquivo:")
        parts.append(parsed_file.text)
    elif parsed_file.message:
        parts.append(parsed_file.message)

    return "\n".join(part for part in parts if part)

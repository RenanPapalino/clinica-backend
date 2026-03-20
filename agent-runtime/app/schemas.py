from __future__ import annotations

from typing import Any

from pydantic import BaseModel, ConfigDict, Field


class ColumnDefinition(BaseModel):
    key: str
    label: str


class StructuredData(BaseModel):
    sucesso: bool = True
    tipo: str
    dados_mapeados: list[dict[str, Any]] = Field(default_factory=list)
    colunas: list[ColumnDefinition] = Field(default_factory=list)
    acao_sugerida: str
    total_registros: int = 0
    confianca: float | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)


class ChatbotResponse(BaseModel):
    model_config = ConfigDict(extra="ignore")

    mensagem: str
    acao_sugerida: str | None = None
    dados_estruturados: StructuredData | None = None


class FilePayload(BaseModel):
    nome: str
    extensao: str | None = None
    mime_type: str | None = None
    tamanho: int | None = None
    base64: str


class ChatPayload(BaseModel):
    model_config = ConfigDict(extra="ignore")

    mensagem: str | None = ""
    user_id: int
    user_name: str | None = None
    user_email: str | None = None
    session_id: str
    tipo_processamento: str | None = "auto"
    timestamp: str | None = None
    contexto: dict[str, Any] = Field(default_factory=dict)
    arquivo: FilePayload | None = None


class ResumePayload(BaseModel):
    model_config = ConfigDict(extra="ignore")

    acao: str
    dados: list[dict[str, Any]] = Field(default_factory=list)
    metadata: dict[str, Any] = Field(default_factory=dict)
    decision: str = "approve"
    session_id: str | None = None
    user_id: int
    user_name: str | None = None
    user_email: str | None = None
    timestamp: str | None = None

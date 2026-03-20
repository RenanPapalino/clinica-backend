from __future__ import annotations

import base64
import io
import json
from dataclasses import dataclass

from .schemas import FilePayload


@dataclass(slots=True)
class ParsedFile:
    text: str | None
    mode: str | None
    supported: bool
    message: str | None = None


def parse_attachment(file_payload: FilePayload | None, max_chars: int = 8000) -> ParsedFile:
    if file_payload is None:
        return ParsedFile(text=None, mode=None, supported=True)

    try:
        raw = base64.b64decode(file_payload.base64)
    except Exception:
        return ParsedFile(
            text=None,
            mode=None,
            supported=False,
            message="Nao foi possivel decodificar o anexo recebido.",
        )

    extension = (file_payload.extensao or "").lower()
    mime_type = (file_payload.mime_type or "").lower()

    if extension in {"txt", "md", "csv", "json"} or mime_type in {
        "text/plain",
        "text/csv",
        "application/json",
    }:
        text = _decode_text(raw)
        if extension == "json" or mime_type == "application/json":
            text = _pretty_json(text)
        return ParsedFile(text=_truncate(text, max_chars), mode="text", supported=True)

    if extension in {"pdf"} or mime_type == "application/pdf":
        text = _extract_pdf(raw)
        if text:
            return ParsedFile(text=_truncate(text, max_chars), mode="pdf", supported=True)
        return ParsedFile(
            text=None,
            mode="pdf",
            supported=False,
            message="Recebi o PDF, mas nao consegui extrair texto dele no runtime atual.",
        )

    if extension in {"xlsx", "xlsm"} or mime_type in {
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "application/vnd.ms-excel.sheet.macroenabled.12",
    }:
        text = _extract_xlsx(raw)
        if text:
            return ParsedFile(text=_truncate(text, max_chars), mode="xlsx", supported=True)
        return ParsedFile(
            text=None,
            mode="xlsx",
            supported=False,
            message="Recebi a planilha, mas nao consegui extrair o conteudo no runtime atual.",
        )

    return ParsedFile(
        text=None,
        mode=extension or mime_type or "unknown",
        supported=False,
        message="Recebi o anexo, mas a leitura documental completa deve seguir pelo pipeline de ingestao/RAG.",
    )


def _decode_text(raw: bytes) -> str:
    for encoding in ("utf-8", "latin-1"):
        try:
            return raw.decode(encoding)
        except UnicodeDecodeError:
            continue
    return raw.decode("utf-8", errors="ignore")


def _pretty_json(text: str) -> str:
    try:
        parsed = json.loads(text)
        return json.dumps(parsed, ensure_ascii=False, indent=2)
    except Exception:
        return text


def _extract_pdf(raw: bytes) -> str | None:
    try:
        from pypdf import PdfReader

        reader = PdfReader(io.BytesIO(raw))
        parts: list[str] = []
        for page in reader.pages:
            parts.append(page.extract_text() or "")
        return "\n".join(part for part in parts if part.strip()) or None
    except Exception:
        return None


def _extract_xlsx(raw: bytes) -> str | None:
    try:
        from openpyxl import load_workbook

        workbook = load_workbook(io.BytesIO(raw), read_only=True, data_only=True)
        lines: list[str] = []
        for sheet in workbook.worksheets:
            lines.append(f"[Aba] {sheet.title}")
            for row in sheet.iter_rows(values_only=True):
                values = [str(value).strip() for value in row if value not in (None, "")]
                if values:
                    lines.append(" | ".join(values))
        return "\n".join(lines) or None
    except Exception:
        return None


def _truncate(text: str, max_chars: int) -> str:
    text = text.strip()
    if len(text) <= max_chars:
        return text
    return text[:max_chars].rstrip() + "\n...[conteudo truncado]"

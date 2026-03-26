from __future__ import annotations

from functools import lru_cache

from fastapi import Depends, FastAPI, Header

from .auth import authorize_request
from .laravel_client import LaravelInternalClient
from .memory import (
    PendingActionStore,
    PendingActionStoreProtocol,
    PostgresPendingActionStore,
    SqlitePendingActionStore,
)
from .schemas import ChatPayload, ChatbotResponse, ResumePayload
from .service import ChatRuntimeService
from .settings import Settings, get_settings


@lru_cache
def get_pending_store() -> PendingActionStoreProtocol:
    settings = get_settings()
    backend = settings.pending_actions_backend
    if backend == "auto":
        backend = "postgres" if settings.pending_actions_database_url else "sqlite"

    if backend == "memory":
        return PendingActionStore(ttl_minutes=settings.pending_actions_ttl_minutes)

    if backend == "postgres":
        return PostgresPendingActionStore(
            dsn=settings.pending_actions_database_url,
            ttl_minutes=settings.pending_actions_ttl_minutes,
        )

    return SqlitePendingActionStore(
        db_path=settings.pending_actions_db_path,
        ttl_minutes=settings.pending_actions_ttl_minutes,
    )


@lru_cache
def get_laravel_client() -> LaravelInternalClient:
    return LaravelInternalClient(get_settings())


@lru_cache
def get_runtime_service() -> ChatRuntimeService:
    settings = get_settings()
    return ChatRuntimeService(
        settings=settings,
        laravel_client=get_laravel_client(),
        pending_actions=get_pending_store(),
    )


def auth_dependency(
    settings: Settings = Depends(get_settings),
    authorization: str | None = Header(default=None),
    x_agent_secret: str | None = Header(default=None, alias="X-Agent-Secret"),
) -> None:
    authorize_request(
        settings=settings,
        authorization=authorization,
        x_agent_secret=x_agent_secret,
    )


app = FastAPI(title="MedIntelligence Agent Runtime", version="0.1.0")


@app.get("/health")
async def health() -> dict[str, str | bool]:
    return {"ok": True, "service": "medintelligence-agent-runtime"}


@app.post("/chat", response_model=ChatbotResponse, dependencies=[Depends(auth_dependency)])
async def chat(
    payload: ChatPayload,
    service: ChatRuntimeService = Depends(get_runtime_service),
) -> ChatbotResponse:
    return await service.process_chat(payload)


@app.post("/chat/file", response_model=ChatbotResponse, dependencies=[Depends(auth_dependency)])
async def chat_file(
    payload: ChatPayload,
    service: ChatRuntimeService = Depends(get_runtime_service),
) -> ChatbotResponse:
    return await service.process_chat(payload)


@app.post("/chat/resume", dependencies=[Depends(auth_dependency)])
async def chat_resume(
    payload: ResumePayload,
    service: ChatRuntimeService = Depends(get_runtime_service),
) -> dict:
    return await service.confirm_action(payload)

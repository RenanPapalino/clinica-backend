from fastapi import Header, HTTPException, status

from .settings import Settings


def authorize_request(
    settings: Settings,
    authorization: str | None = Header(default=None),
    x_agent_secret: str | None = Header(default=None, alias="X-Agent-Secret"),
) -> None:
    expected = settings.runtime_service_token.strip()
    if expected == "":
        return

    if authorization == f"Bearer {expected}" or x_agent_secret == expected:
        return

    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Nao autorizado.",
    )

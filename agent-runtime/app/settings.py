from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "medintelligence-agent-runtime"
    app_port: int = 8787
    debug: bool = False

    openai_api_key: str = Field(default="")
    openai_model: str = Field(default="gpt-4.1-mini")

    runtime_service_token: str = Field(default="")
    laravel_base_url: str = Field(default="http://localhost:8000")
    laravel_agent_secret: str = Field(default="")

    request_timeout_seconds: float = Field(default=20.0)
    max_result_rows: int = Field(default=10)
    default_history_limit: int = Field(default=20)

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        env_prefix="",
        case_sensitive=False,
    )


@lru_cache
def get_settings() -> Settings:
    return Settings()

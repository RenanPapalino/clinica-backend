from functools import lru_cache

from pydantic import Field
from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "medintelligence-agent-runtime"
    app_port: int = 8787
    debug: bool = False
    pending_actions_backend: str = Field(default="auto")
    pending_actions_ttl_minutes: int = Field(default=30)
    pending_actions_max_repeat_count: int = Field(default=2)
    pending_actions_db_path: str = Field(default=".runtime/pending_actions.sqlite3")
    pending_actions_database_url: str = Field(default="")

    openai_api_key: str = Field(default="")
    openai_model: str = Field(default="gpt-4.1-mini")
    openai_vision_model: str = Field(default="gpt-4.1-mini")
    openai_transcription_model: str = Field(default="whisper-1")

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

    @field_validator("debug", mode="before")
    @classmethod
    def normalize_debug(cls, value):
        if isinstance(value, bool):
            return value

        if value is None:
            return False

        if isinstance(value, str):
            normalized = value.strip().lower()
            if normalized in {"1", "true", "yes", "on", "debug", "development"}:
                return True
            if normalized in {"0", "false", "no", "off", "release", "prod", "production", ""}:
                return False

        return bool(value)

    @field_validator("pending_actions_backend", mode="before")
    @classmethod
    def normalize_pending_actions_backend(cls, value):
        normalized = str(value or "auto").strip().lower()
        if normalized in {"auto", "memory", "sqlite", "postgres"}:
            return normalized
        return "auto"

    @field_validator("pending_actions_max_repeat_count", mode="before")
    @classmethod
    def normalize_pending_actions_max_repeat_count(cls, value):
        try:
            normalized = int(value)
        except (TypeError, ValueError):
            return 2
        return max(1, normalized)


@lru_cache
def get_settings() -> Settings:
    return Settings()

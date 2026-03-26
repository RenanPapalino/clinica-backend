#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request


def _normalize_base_url(value: str) -> str:
    return value.rstrip("/")


def _request(
    url: str,
    *,
    token: str | None,
    timeout: float,
    expected_content_type: str | None = None,
) -> tuple[int, str, bytes]:
    headers = {
        "User-Agent": "medintelligence-runtime-smoke/1.0",
        "Accept": expected_content_type or "*/*",
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"

    request = urllib.request.Request(url, headers=headers, method="GET")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read()
            content_type = response.headers.get("Content-Type", "")
            return int(response.status), content_type, body
    except urllib.error.HTTPError as exc:  # pragma: no cover - exercised in real smoke usage
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} em {url}: {body}") from exc
    except urllib.error.URLError as exc:  # pragma: no cover - exercised in real smoke usage
        raise RuntimeError(f"Falha de conexão em {url}: {exc}") from exc


def _request_json(url: str, *, token: str | None, timeout: float) -> dict:
    status, content_type, body = _request(
        url,
        token=token,
        timeout=timeout,
        expected_content_type="application/json",
    )
    if status != 200:
        raise RuntimeError(f"Resposta inesperada {status} em {url}")
    if "json" not in content_type.lower():
        raise RuntimeError(f"Content-Type inesperado em {url}: {content_type}")
    try:
        payload = json.loads(body.decode("utf-8"))
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"JSON inválido em {url}: {exc}") from exc
    if not isinstance(payload, dict):
        raise RuntimeError(f"Payload JSON inesperado em {url}")
    return payload


def _request_text(url: str, *, token: str | None, timeout: float) -> str:
    status, _, body = _request(
        url,
        token=token,
        timeout=timeout,
        expected_content_type="text/plain",
    )
    if status != 200:
        raise RuntimeError(f"Resposta inesperada {status} em {url}")
    return body.decode("utf-8", errors="replace")


def _print_ok(message: str) -> None:
    print(f"[OK] {message}")


def _print_skip(message: str) -> None:
    print(f"[SKIP] {message}")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Smoke test pós-deploy do agent-runtime do MedIntelligence.",
    )
    parser.add_argument("--base-url", required=True, help="Base URL pública ou local do runtime.")
    parser.add_argument(
        "--api-base-url",
        default="",
        help="Base URL da API Laravel para validar /up.",
    )
    parser.add_argument("--token", default="", help="Bearer token do runtime.")
    parser.add_argument(
        "--timeout",
        type=float,
        default=15.0,
        help="Timeout em segundos para cada request.",
    )
    parser.add_argument(
        "--require-auth-checks",
        action="store_true",
        help="Falha se o token não estiver disponível para validar endpoints internos.",
    )
    parser.add_argument(
        "--session-id",
        default="runtime-smoke-test",
        help="Session id usado na leitura de pending actions.",
    )
    args = parser.parse_args()

    runtime_base_url = _normalize_base_url(args.base_url)
    api_base_url = _normalize_base_url(args.api_base_url) if args.api_base_url else ""
    token = args.token.strip()

    if args.require_auth_checks and not token:
        print("Token do runtime não informado para os checks autenticados.", file=sys.stderr)
        return 1

    try:
        if api_base_url:
            api_health = _request_json(f"{api_base_url}/up", token=None, timeout=args.timeout)
            if not api_health.get("status") == "ok":
                raise RuntimeError(f"/up retornou payload inesperado: {api_health}")
            _print_ok(f"API Laravel respondeu em {api_base_url}/up")

        health = _request_json(f"{runtime_base_url}/health", token=None, timeout=args.timeout)
        if not health.get("ok"):
            raise RuntimeError(f"/health retornou payload inesperado: {health}")
        _print_ok(f"Runtime respondeu em {runtime_base_url}/health")

        if not token:
            _print_skip("Checks autenticados foram ignorados porque nenhum token foi informado.")
            return 0

        metrics_json = _request_json(
            f"{runtime_base_url}/internal/diagnostics/metrics?format=json",
            token=token,
            timeout=args.timeout,
        )
        runtime = metrics_json.get("runtime") if isinstance(metrics_json.get("runtime"), dict) else {}
        pending_actions = (
            metrics_json.get("pending_actions")
            if isinstance(metrics_json.get("pending_actions"), dict)
            else {}
        )
        if "counters" not in runtime or "total_entries" not in pending_actions:
            raise RuntimeError("Payload JSON de métricas não contém os campos esperados.")
        _print_ok("Métricas JSON do runtime foram carregadas")

        metrics_prometheus = _request_text(
            f"{runtime_base_url}/internal/diagnostics/metrics?format=prometheus",
            token=token,
            timeout=args.timeout,
        )
        expected_metrics = [
            "agent_runtime_info",
            "agent_runtime_uptime_seconds",
            "agent_runtime_pending_entries",
            "agent_runtime_counter",
            "agent_runtime_audit_event_total",
        ]
        for metric_name in expected_metrics:
            if metric_name not in metrics_prometheus:
                raise RuntimeError(f"Métrica Prometheus ausente: {metric_name}")
        _print_ok("Métricas Prometheus do runtime foram carregadas")

        query = urllib.parse.urlencode({"session_id": args.session_id, "limit": 5})
        pending_payload = _request_json(
            f"{runtime_base_url}/internal/diagnostics/pending-actions?{query}",
            token=token,
            timeout=args.timeout,
        )
        summary = pending_payload.get("summary") if isinstance(pending_payload.get("summary"), dict) else {}
        if "total_entries" not in summary or "counts_by_state" not in summary:
            raise RuntimeError("Payload de pending-actions não contém o resumo esperado.")
        _print_ok("Diagnóstico de pending actions respondeu corretamente")

        print(
            "Smoke test concluído. "
            f"Pendências atuais: {summary.get('total_entries', 0)} total, "
            f"{summary.get('active_entries', 0)} ativas."
        )
        return 0
    except Exception as exc:  # pragma: no cover - smoke script is validated by execution, not unit tests
        print(f"[ERRO] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

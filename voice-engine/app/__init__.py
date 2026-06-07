"""Voice CRM Agent — service-oriented FastAPI application.

This package replaces the legacy monolithic ``main.py``. The legacy
endpoints are still mounted under ``/legacy`` for backwards compat; new
work should target the routers defined under :mod:`app.api.routes` and
the WebSocket endpoint :mod:`app.api.ws`.
"""

"""Places worker — Overture Maps lookups for Lead Generation.

Runs as its own process/container (``uvicorn app.places.app:app``) so a
multi-minute remote Parquet scan never shares CPU or network with live calls
in the voice engine. Imports nothing from the speech/LLM stack.
"""

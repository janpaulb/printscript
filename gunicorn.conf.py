"""
Gunicorn production configuration for PrintScript.

Usage:
    gunicorn --config gunicorn.conf.py app:app

Or via Docker (see Dockerfile CMD).
"""

import multiprocessing

# ── Binding ────────────────────────────────────────────────────────────────────
bind    = "0.0.0.0:5000"

# ── Workers ────────────────────────────────────────────────────────────────────
# LibreOffice conversions are CPU-bound and each worker uses a unique profile
# dir, so multiple workers run safely in parallel.
workers     = multiprocessing.cpu_count()
worker_class = "sync"

# ── Timeouts ───────────────────────────────────────────────────────────────────
# A 50 MB DOCX can take up to 2 minutes to convert on a slow server.
timeout      = 180   # seconds — worker killed if it exceeds this
keepalive    = 5

# ── Logging ────────────────────────────────────────────────────────────────────
accesslog  = "-"   # stdout
errorlog   = "-"   # stderr
loglevel   = "info"

# ── Recycling ──────────────────────────────────────────────────────────────────
# Recycle workers after N requests to prevent memory accumulation from
# very large LibreOffice profile dirs.
max_requests      = 500
max_requests_jitter = 50


def post_fork(server, worker):
    """
    Called in each worker process after forking.
    Ensures LibreOffice headless support is available before the worker
    accepts its first request.
    """
    try:
        from processor import bootstrap_headless_libreoffice
        bootstrap_headless_libreoffice()
    except Exception:
        pass

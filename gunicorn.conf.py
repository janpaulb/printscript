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
# WeasyPrint + mammoth conversions are CPU-bound; multiple workers run safely
# in parallel since there are no shared external processes or profile dirs.
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
# Recycle workers after N requests to prevent slow memory growth.
max_requests        = 500
max_requests_jitter = 50

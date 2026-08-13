"""
Gunicorn configuration for PrintScript.

    gunicorn --config gunicorn.conf.py app:app
"""

import multiprocessing
import os

bind = '0.0.0.0:%s' % os.environ.get('PORT', '5000')

# Conversion is CPU-bound and completely self-contained — no shared profile
# directories, no helper processes — so workers scale linearly and safely.
workers = int(os.environ.get('WEB_CONCURRENCY', min(multiprocessing.cpu_count(), 4)))
worker_class = 'sync'
threads = 1

# A 50 MB document with hundreds of images is the worst case we allow.
timeout = 180
graceful_timeout = 30
keepalive = 5

accesslog = '-'
errorlog = '-'
loglevel = os.environ.get('LOG_LEVEL', 'info')

# WeasyPrint caches font data per process; recycling keeps that bounded.
max_requests = 300
max_requests_jitter = 50

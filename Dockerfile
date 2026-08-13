# PrintScript — Google Docs in, print-ready PDF out.
#
# WeasyPrint renders through Pango, so the image needs Pango and a font set.
# It does *not* need an office suite, a display server or any helper process,
# which is exactly why this image is small and boring.

FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

# ── System libraries ──────────────────────────────────────────────────────────
#   libpango / libharfbuzz  — text shaping and layout for WeasyPrint
#   fonts-liberation        — metric-compatible Arial/Times/Courier substitutes
#   fonts-dejavu-core       — broad Latin coverage
#   fonts-noto-core + CJK   — everything else a script might contain
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        libpango-1.0-0 \
        libpangoft2-1.0-0 \
        libharfbuzz0b \
        libfribidi0 \
        shared-mime-info \
        fonts-liberation \
        fonts-dejavu-core \
        fonts-noto-core \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt requirements-dev.txt ./
RUN pip install --no-cache-dir -r requirements-dev.txt

COPY printscript/ printscript/
COPY tests/ tests/
COPY app.py gunicorn.conf.py pytest.ini ./
COPY templates/ templates/
COPY static/ static/

# The full test suite runs at build time: an image that cannot convert a
# document is never produced in the first place.
RUN python -m pytest -q

RUN useradd --create-home --uid 1001 printscript \
 && chown -R printscript:printscript /app
USER printscript

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:5000/healthz', timeout=4)"]

CMD ["gunicorn", "--config", "gunicorn.conf.py", "app:app"]
